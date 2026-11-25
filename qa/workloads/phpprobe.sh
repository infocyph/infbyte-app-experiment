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
changed-only)
    git init -q
    git config user.email playground@example.invalid
    git config user.name Playground
    printf '%s\n' '<?php final class Historical { ' > src/Historical.php
    printf '%s\n' '<?php final class Committed {}' > src/Committed.php
    printf '%s\n' '<?php final class Working {}' > src/Working.php
    printf '%s\n' '<?php final class Removed {}' > src/Removed.php
    git add src
    git commit -qm baseline
    base="$(git rev-parse HEAD)"
    printf '%s\n' '<?php final class Committed { public const VALUE = 1; }' > src/Committed.php
    git rm -q src/Removed.php
    git add src/Committed.php
    git commit -qm committed
    printf '%s\n' '<?php final class Working { public const VALUE = 2; }' > src/Working.php
    printf '%s\n' '<?php final class Untracked {}' > src/Untracked.php
    mkdir src/excluded
    printf '%s\n' '<?php final class Excluded {' > src/excluded/Excluded.php
    expect_exit 0 changed-valid.json syntax --json --changed-only --changed-base="$base" --exclude=src/excluded src
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); if($a["files_checked"]!==3) exit(1);' "$evidence/changed-valid.json"
    printf '%s\n' '<?php final class Working {' > src/Working.php
    expect_exit 1 changed-invalid.json syntax --json --changed-only --changed-base="$base" --exclude=src/excluded src
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); if($a["files_checked"]!==3 || $a["groups"][0]["failures"]!==1) exit(1);' "$evidence/changed-invalid.json"
    expect_exit 1 full-invalid.json syntax --json src
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); if($a["groups"][0]["failures"]!==3) exit(1);' "$evidence/full-invalid.json"
    ;;
graph-api)
    cat > src/Contract.php <<'PHP'
<?php
namespace Lab;
interface Contract {}
trait Logging { protected function log(): void {} }
class BaseService {}
function helper(): void {}
PHP
    cat > src/Service.php <<'PHP'
<?php
namespace Lab;
use DateTimeImmutable;
final class Service extends BaseService implements Contract
{
    use Logging;
    public function run(object $client): DateTimeImmutable
    {
        $this->log();
        helper();
        $client->execute();
        unresolved_lab_function();
        return new DateTimeImmutable();
    }
}
throw new \RuntimeException('Analyzed source must never execute.');
PHP
    cat > graph-contract.php <<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
use Infocyph\PHPProbe\Graph\CodeGraphExtractor;
function requireContract(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$extractor = new CodeGraphExtractor();
$a = __DIR__ . '/src/Contract.php';
$b = __DIR__ . '/src/Service.php';
$graph = $extractor->extract([$a, $b], __DIR__);
requireContract($graph === $extractor->extract([$b, $a, $b], __DIR__), 'Graph changed with input order or duplicates.');
requireContract($graph['schema'] === 'phpprobe.code-graph' && $graph['schema_version'] === 1 && $graph['root'] === '.' && $graph['files_scanned'] === 2, 'Graph contract changed.');
$nodes = array_column($graph['nodes'], null, 'id');
requireContract(count($nodes) === count($graph['nodes']), 'Duplicate graph IDs.');
requireContract($nodes['class-like:Lab\\Service']['defined'] && $nodes['class-like:Lab\\Service']['file'] === 'src/Service.php', 'Declaration provenance missing.');
requireContract($nodes['class-like:DateTimeImmutable']['defined'] === false, 'External reference marked as declaration.');
$edges = [];
$resolutions = [];
foreach ($graph['edges'] as $edge) {
    requireContract(isset($nodes[$edge['source']], $nodes[$edge['target']]), 'Dangling graph edge.');
    requireContract($edge['certainty'] === 'extracted' && $edge['line'] > 0, 'Invalid edge provenance.');
    $edges[] = implode('|', [$edge['source'], $edge['relation'], $edge['target'], $edge['resolution']]);
    $resolutions[] = $edge['resolution'];
}
foreach ([
    'class-like:Lab\\Service|extends|class-like:Lab\\BaseService|exact',
    'class-like:Lab\\Service|implements|class-like:Lab\\Contract|exact',
    'class-like:Lab\\Service|uses|class-like:Lab\\Logging|exact',
    'method:Lab\\Service::run|calls|function:Lab\\helper|exact',
    'method:Lab\\Service::run|instantiates|class-like:DateTimeImmutable|exact',
] as $expected) {
    requireContract(in_array($expected, $edges, true), 'Missing edge: ' . $expected);
}
requireContract(in_array('dynamic', $resolutions, true) && in_array('namespace_fallback', $resolutions, true), 'Uncertain calls were falsely resolved.');
echo json_encode($graph, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;
PHP
    php graph-contract.php > "$evidence/graph-api.json"
    expect_exit 0 graph-cli.json graph --root="$root" src
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); $b=json_decode(file_get_contents($argv[2]),true,flags:JSON_THROW_ON_ERROR); if($a!==$b) exit(1);' "$evidence/graph-api.json" "$evidence/graph-cli.json"
    ;;
reference-scope)
    mkdir -p src/Contracts
    php -r '$a=json_decode(file_get_contents("composer.json"),true,flags:JSON_THROW_ON_ERROR);$a["autoload"]["psr-4"]["Lab\\"]="src/";file_put_contents("composer.json",json_encode($a,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));'
    printf '%s\n' '<?php namespace Lab\Contracts; interface Payment {}' > src/Contracts/Payment.php
    printf '%s\n' '<?php namespace Lab; final class Historical { public MissingHistorical $value; }' > src/Historical.php
    printf '%s\n' '<?php namespace Lab; final class Current {}' > src/Current.php
    git init -q
    git config user.email playground@example.invalid
    git config user.name Playground
    git add src composer.json
    git commit -qm baseline
    base="$(git rev-parse HEAD)"
    printf '%s\n' '<?php namespace Lab; final class Current { public \Lab\Contracts\Payment $value; }' > src/Current.php
    expect_exit 0 reference-changed-valid.json reference --json --changed-only --changed-base="$base" src
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);if($a["files_checked"]!==1||$a["findings"]!==[])exit(1);' "$evidence/reference-changed-valid.json"
    printf '%s\n' '<?php namespace Lab; final class Current { public \Lab\Contracts\Paymant $value; }' > src/Current.php
    expect_exit 1 reference-changed-invalid.json reference --json --changed-only --changed-base="$base" src
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);$f=$a["findings"];if(count($f)!==1||$f[0]["type"]!=="unknown_fqcn"||$f[0]["symbol"]!=="Lab\\Contracts\\Paymant"||$f[0]["candidates"][0]["fqcn"]!=="Lab\\Contracts\\Payment")exit(1);' "$evidence/reference-changed-invalid.json"
    expect_exit 1 reference-full.json reference --json src
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);if(count($a["findings"])!==2)exit(1);' "$evidence/reference-full.json"
    printf '%s\n' '<?php namespace Lab; final class Current { public \Lab\Contracts\Payment $value; }' > src/Current.php
    printf '%s\n' '<?php namespace Lab\Wrong; final class WrongLocation {}' > src/WrongLocation.php
    php -r '$a=json_decode(file_get_contents("composer.json"),true,flags:JSON_THROW_ON_ERROR);$a["require"]["ext-phpprobe_fixture_missing"]="*";$a["config"]["platform"]["ext-phpprobe_fixture_missing"]="1.0";file_put_contents("fixture-composer.json",json_encode($a,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));'
    expect_exit 1 reference-extension.json reference --json --composer=fixture-composer.json src
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);$types=array_count_values(array_column($a["findings"],"type"));if($a["extensions_missing"]!==1||($types["missing_extension"]??0)!==1||($types["psr4_fqcn_mismatch"]??0)!==1||($types["unknown_fqcn"]??0)!==1)exit(1);' "$evidence/reference-extension.json"
    expect_exit 1 reference-sarif.json reference --format=sarif --composer=fixture-composer.json src
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);if($a["version"]!=="2.1.0"||count($a["runs"][0]["results"])!==3)exit(1);' "$evidence/reference-sarif.json"
    ;;
duplicate-cache)
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
    args=(duplicates --json --mode=gate --fuzzy --min-lines=5 --min-tokens=20 --cache-file="$root/clone-cache.json" --fail-on=warning left right)
    expect_exit 1 clone-cold.json "${args[@]}"
    expect_exit 1 clone-warm.json "${args[@]}"
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);$b=json_decode(file_get_contents($argv[2]),true,flags:JSON_THROW_ON_ERROR);if($a["cache_hit"]!==false||$b["cache_hit"]!==true||$a["clones"]===[])exit(1);unset($a["cache_hit"],$b["cache_hit"]);if($a!==$b)exit(1);' "$evidence/clone-cold.json" "$evidence/clone-warm.json"
    printf '{broken' > clone-cache.json
    expect_exit 1 clone-corrupt.json "${args[@]}"
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);$b=json_decode(file_get_contents($argv[2]),true,flags:JSON_THROW_ON_ERROR);if($b["cache_hit"]!==false||$a["clones"]!==$b["clones"])exit(1);' "$evidence/clone-cold.json" "$evidence/clone-corrupt.json"
    expect_exit 1 clone-no-cache.json "${args[@]}" --no-cache
    expect_exit 1 clone-audit.json "${args[@]}" --mode=audit
    php -r 'foreach(array_slice($argv,1) as $p){$a=json_decode(file_get_contents($p),true,flags:JSON_THROW_ON_ERROR);if($a["cache_hit"]!==false||$a["clones"]===[])exit(1);}' "$evidence/clone-no-cache.json" "$evidence/clone-audit.json"
    expect_exit 1 clone-error-threshold.json "${args[@]}" --fail-on=error --error-duplicate-percentage=0
    expect_exit 2 clone-invalid-option.txt duplicates --mode=gate --min-tokens=0 left right
    # Preserve the old size and modification timestamp, but change actual source bytes.
    php -r '$p="right/Beta.php";$n=filesize($p);$t=filemtime($p);$s="<?php final class Beta {}";file_put_contents($p,str_pad($s,$n));touch($p,$t);'
    expect_exit 0 clone-content-change.json "${args[@]}"
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);if($a["cache_hit"]!==false||$a["clones"]!==[])exit(1);' "$evidence/clone-content-change.json"
    ;;
comment-options)
    cat > src/Policy.php <<'PHP'
<?php
final class PolicyFixture
{
    public const TEXT = "// SECURITY(auth): this is a string";
    // SECURITY(auth): fixture marker for diagnostic testing
    // TODO(PROJ-142): fixture marker for diagnostic testing
    /**
     * @param string $count
     * @return string
     */
    public function total(int $count): int { return $count; }
}
PHP
    expect_exit 1 comment-policy.json comments --json --policy=strict --doc-mode=parser --fail-on=info --explain src
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);$markers=array_values(array_filter($a["findings"],fn($f)=>$f["type"]==="comment_marker"));if(count($markers)!==2||array_column($markers,"tag")!==["SECURITY","TODO"]||!in_array("phpdoc_signature_mismatch",array_column($a["findings"],"type"),true))exit(1);' "$evidence/comment-policy.json"
    expect_exit 1 comment-filter.json comments --json --tags=TODO --fail-on=info src
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);$m=array_values(array_filter($a["findings"],fn($f)=>$f["type"]==="comment_marker"));if(count($m)!==1||$m[0]["tag"]!=="TODO")exit(1);' "$evidence/comment-filter.json"
    expect_exit 1 comment-sarif.json comments --format=sarif --policy=strict --doc-mode=parser --fail-on=info src
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);$b=json_decode(file_get_contents($argv[2]),true,flags:JSON_THROW_ON_ERROR);if($b["version"]!=="2.1.0"||count($a["findings"])!==count($b["runs"][0]["results"]))exit(1);' "$evidence/comment-policy.json" "$evidence/comment-sarif.json"
    expect_exit 2 comment-invalid-mode.txt comments --doc-mode=invalid src
    printf '%s\n' '<?php final class PolicyFixture { public const TEXT = "// SECURITY(auth): text"; }' > src/Policy.php
    expect_exit 0 comment-clean.json comments --json --policy=strict --doc-mode=parser --fail-on=info src
    ;;
syntax-options)
    mkdir -p src/ignored other
    printf '%s\n' '<?php final class ValidFixture {}' > src/Valid.php
    printf '%s\n' '<?php function invalid( {' > src/Broken.php
    printf '%s\n' '<?php function excluded( {' > src/ignored/Excluded.php
    printf '%s\n' '<?php final class SecondFixture {}' > other/Second.php
    expect_exit 1 syntax-sequential.json syntax --json --parallel=1 --exclude=src/ignored src other
    expect_exit 1 syntax-parallel.json syntax --json --parallel=4 --exclude=src/ignored src other
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);$b=json_decode(file_get_contents($argv[2]),true,flags:JSON_THROW_ON_ERROR);foreach([$a,$b] as $x){if($x["files_checked"]!==3||count($x["failures"])!==1||$x["failures"][0]["file"]!=="src/Broken.php")exit(1);}' "$evidence/syntax-sequential.json" "$evidence/syntax-parallel.json"
    expect_exit 1 syntax-sarif.json syntax --format=sarif --exclude=src/ignored src other
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);if($a["version"]!=="2.1.0"||count($a["runs"][0]["results"])!==1)exit(1);' "$evidence/syntax-sarif.json"
    expect_exit 2 syntax-invalid-timeout.txt syntax --timeout=0 src
    expect_exit 0 syntax-clean.json syntax --json --exclude=src/ignored --exclude=src/Broken.php src other
    php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);if($a["files_checked"]!==2||$a["failures"]!==[])exit(1);' "$evidence/syntax-clean.json"
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
