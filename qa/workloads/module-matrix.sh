#!/usr/bin/env bash
set -Eeuo pipefail
unset COMPOSER_NO_DEV
cd "$PROJECT"
features=(data.database data.filesystem security.crypto security.validation security.mfa security.passkey execution.messaging execution.communication)
selection() {
  php -r '
    $actual=array_keys(array_filter((require "config/modules.php")["features"],static fn($v)=>$v===true));
    $expected=array_slice($argv,1); sort($actual); sort($expected);
    if ($actual!==$expected) {fwrite(STDERR,"Feature selection mismatch\n");exit(1);}
  ' "$@"
}
mutate() {
  php infbyte "module:$1" "$2" --json=1 > "$RUNNER_TEMP/module-result.json"
  php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); if (($r["status"]??null)!=="complete") exit(1);' "$RUNNER_TEMP/module-result.json"
}
compile() {
  APP_ENV=production APP_DEBUG=false APP_CAPABILITIES= php infbyte config:validate --production
  APP_ENV=production APP_DEBUG=false APP_CAPABILITIES= php infbyte optimize --json=1 > "$RUNNER_TEMP/module-release.json"
  APP_ENV=production APP_DEBUG=false APP_CAPABILITIES= php infbyte app:ready
  php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);if (!is_file($r["manifest"]) || !hash_equals($r["manifest_sha256"],hash_file("sha256",$r["manifest"]))) exit(1);' "$RUNNER_TEMP/module-release.json"
}
if [[ "$SCENARIO" == ecosystem-module-conditional-* ]]; then
  case "$SCENARIO" in
    ecosystem-module-conditional-validation) dependent=security.validation; prerequisite=data.database; namespace=validation; patch="['database_connection'=>'primary']" ;;
    ecosystem-module-conditional-mfa) dependent=security.mfa; prerequisite=security.crypto; namespace=auth; patch="['drivers'=>['mfa'=>'otp']]" ;;
    ecosystem-module-conditional-messaging) dependent=execution.messaging; prerequisite=data.database; namespace=messaging; patch="['durable'=>['enabled'=>true]]" ;;
    *) exit 1 ;;
  esac
  mutate disable "$dependent"
  # Disable other consumers of the same prerequisite before manipulating it.
  for feature in security.validation security.mfa execution.messaging; do
    if [[ "$feature" != "$dependent" ]]; then mutate disable "$feature"; fi
  done
  mutate disable "$prerequisite"
  original="config/$namespace.php"
  test -f "$original"
  cp "$original" "$original.lab-original"
  trap 'mv "$original.lab-original" "$original"' EXIT
  printf '<?php\nreturn array_replace_recursive(require __DIR__ . "/%s.php.lab-original", %s);\n' "$namespace" "$patch" > "$original"
  php infbyte module:plan "$dependent" --json=1 > "$EVIDENCE/conditional-plan.json"
  php -r '$p=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); if (!$p["applicable"] || !in_array($argv[2],$p["required"],true) || $p["effects"]["activate"]!==[]) exit(1);' "$EVIDENCE/conditional-plan.json" "$prerequisite"
  unchanged() { sha256sum composer.json composer.lock config/modules.php; }
  before="$(unchanged)"
  if php infbyte module:enable "$dependent" --json=1 > "$EVIDENCE/blocked-enable.log" 2>&1; then
    echo 'Conditional activation accepted without prerequisite' >&2; exit 1
  fi
  grep -F "$prerequisite" "$EVIDENCE/blocked-enable.log"
  [[ "$(unchanged)" == "$before" ]]
  mutate enable "$prerequisite"
  mutate enable "$dependent"
  php infbyte module:show "$dependent" --json=1 > "$EVIDENCE/conditional-enabled.json"
  php -r '$p=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);$s=$p["states"][$argv[2]];if (!$s["enabled"] || !in_array($argv[3],$s["dependencies"],true)) exit(1);' "$EVIDENCE/conditional-enabled.json" "$dependent" "$prerequisite"
  before="$(unchanged)"
  for operation in disable remove; do
    if php infbyte "module:$operation" "$prerequisite" --json=1 > "$EVIDENCE/blocked-$operation.log" 2>&1; then
      echo 'Active dependent did not protect prerequisite' >&2; exit 1
    fi
    grep -F "$dependent" "$EVIDENCE/blocked-$operation.log"
    [[ "$(unchanged)" == "$before" ]]
  done
  mutate disable "$dependent"
  mutate disable "$prerequisite"
  mv "$original.lab-original" "$original"
  trap - EXIT
  # Return to the normal all-feature topology, then compile and verify.
  for feature in "${features[@]}"; do mutate enable "$feature"; done
  selection "${features[@]}"
  compile
  echo "Conditional CLI lifecycle passed: $dependent -> $prerequisite"
elif [[ "$SCENARIO" == ecosystem-module-lifecycle ]]; then
  # Disabled sibling must not cause shared OTP package removal while passkey stays enabled.
  mutate disable security.mfa
  mutate remove security.mfa
  php -r 'require "vendor/autoload.php";if (!Composer\InstalledVersions::isInstalled("infocyph/otp") || !(require "config/modules.php")["features"]["security.passkey"]) exit(1);'
  mutate install security.mfa
  mutate enable security.mfa
  selection "${features[@]}"
  for feature in "${features[@]}"; do
    mutate enable "$feature"
    if [[ "$feature" == data.database ]]; then
      php infbyte module:remove "$feature" --dry-run --json=1 > "$RUNNER_TEMP/blocked-remove.json" && { echo 'Enabled removal accepted' >&2; exit 1; }
      php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);if (($r["status"]??null)==="complete" || ($r["status"]??null)==="planned") exit(1);' "$RUNNER_TEMP/blocked-remove.json"
    fi
  done
  compile
  before="$(sha256sum config/modules.php)"
  mutate enable security.mfa
  [[ "$(sha256sum config/modules.php)" == "$before" ]]
  for ((i=7;i>=0;--i)); do mutate disable "${features[i]}"; done
  selection
  for ((i=7;i>=0;--i)); do mutate remove "${features[i]}"; done
  test ! -d vendor/infocyph/phpforge
  test ! -e vendor/bin/pest
  compile
  for feature in "${features[@]}"; do mutate install "$feature"; mutate enable "$feature"; done
  selection "${features[@]}"
  composer check-platform-reqs --no-dev
  test ! -d vendor/infocyph/phpforge
  compile
  echo 'Shared dependency removal/reinstall, blocked enabled removal, idempotency and core/all recompilation passed'
else
  shard="${SCENARIO#ecosystem-module-}"
  [[ "$shard" =~ ^[0-7]$ ]]
  previous=255
  # Gray-code traversal of all 256 possible explicit selections split into eight independent shards.
  for ((i=shard*32;i<(shard+1)*32;++i)); do
    mask=$((i ^ (i >> 1)))
    wanted=()
    for ((bit=0;bit<8;++bit)); do
      old=$(( (previous >> bit) & 1 ))
      new=$(( (mask >> bit) & 1 ))
      if (( new == 1 )); then wanted+=("${features[bit]}"); fi
      if (( old != new )); then
        if (( new == 1 )); then mutate enable "${features[bit]}"; else mutate disable "${features[bit]}"; fi
      fi
    done
    selection "${wanted[@]}"
    compile
    printf '%s\n' "$mask" >> "$EVIDENCE/module-masks.txt"
    previous="$mask"
  done
  test "$(wc -l < "$EVIDENCE/module-masks.txt")" = 32
  test "$(sort -u "$EVIDENCE/module-masks.txt" | wc -l)" = 32
fi
