#!/usr/bin/env bash
set -euo pipefail
root="$RUNNER_TEMP/probe-fixture"
evidence="$RUNNER_TEMP/probe-evidence"
mkdir -p "$root/src" "$evidence"
cd "$root"
composer init --name=experiment/probe --no-interaction
composer require infocyph/phpprobe --no-interaction --prefer-dist --no-progress
composer show --format=json > "$evidence/packages.json"

probe=vendor/bin/phpprobe
expect_exit() {
    local expected="$1" report="$2" actual=0
    shift 2
    "$probe" "$@" > "$evidence/$report" 2> "$evidence/$report.stderr" || actual=$?
    printf '%s expected=%s actual=%s\n' "$report" "$expected" "$actual" >> "$evidence/exit-codes.txt"
    test "$actual" = "$expected"
}
case "$PROBE_MODE" in
syntax-graph)
    printf '%s\n' '<?php declare(strict_types=1); final class LabProbe { public function value(): int { return 42; } }' > src/valid.php
    printf '%s\n' '<?php function invalid( {' > invalid.php
    expect_exit 0 syntax-valid.json syntax --parallel=4 --json src
    expect_exit 1 syntax-invalid.json syntax --parallel=4 --json invalid.php
    expect_exit 2 syntax-bad-option.txt syntax --parallel=0 src
    "$probe" graph --root="$root" --output="$evidence/graph-1.json" src
    "$probe" graph --root="$root" --output="$evidence/graph-2.json" src
    cmp "$evidence/graph-1.json" "$evidence/graph-2.json"
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); if ($a===[]) exit(1);' "$evidence/graph-1.json"
    ;;
reference)
    mkdir -p src/Contracts
    php -r '$f="composer.json"; $a=json_decode(file_get_contents($f),true,flags:JSON_THROW_ON_ERROR); $a["autoload"]["psr-4"]["Acme\\"]="src/"; file_put_contents($f,json_encode($a,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));'
    printf '%s\n' '<?php namespace Acme\Contracts; interface PaymentGateway {}' > src/Contracts/PaymentGateway.php
    cat > src/OrderService.php <<'PHP'
<?php
namespace Acme;
use Acme\Contracts\PaymantGateway;
final class OrderService
{
    public function __construct(private PaymantGateway $gateway) {}
}
PHP
    expect_exit 1 reference-broken.json reference --json src
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); foreach ($a["findings"] as $f) if ($f["type"]==="unknown_fqcn" && $f["symbol"]==="Acme\\Contracts\\PaymantGateway" && $f["candidates"][0]["fqcn"]==="Acme\\Contracts\\PaymentGateway") exit(0); exit(1);' "$evidence/reference-broken.json"
    sed -i 's/PaymantGateway/PaymentGateway/g' src/OrderService.php
    expect_exit 0 reference-fixed.json reference --json src
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); if ($a["findings"]!==[]) exit(1);' "$evidence/reference-fixed.json"
    ;;
duplicates)
    mkdir -p left right
    cat > left/Alpha.php <<'PHP'
<?php
final class Alpha
{
    public function render(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $result[] = strtoupper((string) $item);
        }
        return $result;
    }
}
PHP
    sed -e 's/Alpha/Beta/g' -e 's/render/build/g' -e 's/items/values/g' -e 's/result/output/g' -e 's/item/value/g' left/Alpha.php > right/Beta.php
    args=(duplicates --json --fuzzy --min-lines=5 --min-tokens=20 left right)
    expect_exit 1 duplicates-found.json "${args[@]}"
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); foreach ($a["clones"] as $c) if (count($c["occurrences"])>=2) exit(0); exit(1);' "$evidence/duplicates-found.json"
    expect_exit 0 duplicates-baseline.json "${args[@]}" --write-baseline="$root/duplicates-baseline.json"
    expect_exit 0 duplicates-known.json "${args[@]}" --baseline="$root/duplicates-baseline.json"
    expect_exit 0 duplicates-clean.json duplicates --json --fuzzy --min-lines=5 --min-tokens=20 left
    ;;
comments)
    printf '%s\n' '<?php' '// SECURITY(auth): inspect token logging before release' 'final class CommentFixture {}' > src/CommentFixture.php
    expect_exit 1 comments-found.json comments --json --fail-on=info src
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); foreach ($a["findings"] as $f) if ($f["type"]==="comment_marker" && $f["tag"]==="SECURITY") exit(0); exit(1);' "$evidence/comments-found.json"
    expect_exit 1 comments-phpstan.json comments --format=phpstan-json --fail-on=info src
    expect_exit 0 comments-baseline.json comments --json --fail-on=info --write-baseline="$root/comments-baseline.json" src
    expect_exit 0 comments-known.json comments --json --fail-on=info --baseline="$root/comments-baseline.json" src
    printf '%s\n' '<?php final class CommentFixture {}' > src/CommentFixture.php
    expect_exit 0 comments-clean.json comments --json --fail-on=info src
    ;;
config-check)
    expect_exit 0 presets.txt presets
    expect_exit 0 preset.json preset standard
    expect_exit 0 init.txt init --preset=standard --with-ci
    test -s phpprobe.json
    test -s .github/workflows/phpprobe.yml
    cp phpprobe.json "$evidence/generated-config.json"
    expect_exit 0 config-valid.json config validate --json
    expect_exit 0 doctor.json doctor --json
    printf '{' > invalid-config.json
    expect_exit 2 config-invalid.json config validate --config=invalid-config.json --json
    printf '%s\n' '<?php declare(strict_types=1); final class CleanProbe {}' > src/CleanProbe.php
    expect_exit 0 check-clean.json check --format=json --report-dir="$evidence/check-clean" src
    printf '%s\n' '<?php function invalid( {' > src/Broken.php
    expect_exit 1 check-invalid.json check --format=json --report-dir="$evidence/check-invalid" src
    cmp phpprobe.json "$evidence/generated-config.json"
    ;;
*)
    printf 'Unknown PHPProbe acceptance mode: %s\n' "$PROBE_MODE" >&2
    exit 2
    ;;
esac
printf 'Published QA tool acceptance passed: %s\n' "$PROBE_MODE"
