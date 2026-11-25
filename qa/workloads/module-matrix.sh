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
if [[ "$SCENARIO" == ecosystem-module-lifecycle ]]; then
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
