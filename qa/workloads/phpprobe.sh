#!/usr/bin/env bash
set -euo pipefail
root="$RUNNER_TEMP/probe-fixture"
evidence="$RUNNER_TEMP/probe-evidence"
mkdir -p "$root/src" "$evidence"
cd "$root"
composer init --name=experiment/probe --no-interaction
composer require infocyph/phpprobe --no-interaction --prefer-dist --no-progress
composer show --format=json > "$evidence/packages.json"
printf '%s\n' '<?php declare(strict_types=1); final class LabProbe { public function value(): int { return 42; } }' > src/valid.php
printf '%s\n' '<?php function invalid( {' > invalid.php
vendor/bin/phpprobe syntax --parallel=4 --json src > "$evidence/syntax-valid.json"
set +e
vendor/bin/phpprobe syntax --parallel=4 --json invalid.php > "$evidence/syntax-invalid.json"
result=$?
set -e
test "$result" = 1
vendor/bin/phpprobe graph --root="$root" --output="$evidence/graph-1.json" src
vendor/bin/phpprobe graph --root="$root" --output="$evidence/graph-2.json" src
cmp "$evidence/graph-1.json" "$evidence/graph-2.json"
php -r '$a=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR); if ($a===[]) exit(1);' "$evidence/graph-1.json"
echo 'Published QA tool syntax gate and deterministic graph passed'
