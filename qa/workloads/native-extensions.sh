#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 5 && !PHP_ZTS ? 0 : 1);'
mode="${1:?native extension name required}"
root="$(mktemp -d "$RUNNER_TEMP/native-extension.XXXXXXXX")"
trap 'rm -rf "$root"' EXIT
case "$mode" in
  cassandra)
    . /etc/os-release
    test "$ID" = ubuntu
    test "$(uname -m)" = x86_64
    sudo apt-get update
    sudo apt-get install -y libuv1-dev
    asset="ubuntu-$VERSION_ID-php8.5-nts-scylladb.tar.gz"
    curl -fsSL --retry 3 https://api.github.com/repos/he4rt/scylladb-php-driver/releases/latest -o "$root/release.json"
    python3 - "$root" "$asset" <<'PY'
import json
import pathlib
import sys

root = pathlib.Path(sys.argv[1])
release = json.loads((root / "release.json").read_text())
assets = [item for item in release["assets"] if item["name"] == sys.argv[2]]
if len(assets) != 1:
    raise SystemExit("No matching published PHP 8.5 driver for this Ubuntu runner.")
asset = assets[0]
if not asset["digest"].startswith("sha256:"):
    raise SystemExit("Missing published asset digest.")
(root / "url").write_text(asset["browser_download_url"])
(root / "sha256").write_text(asset["digest"].removeprefix("sha256:") + "  driver.tar.gz\n")
print("Selected published Cassandra driver:", release["tag_name"], asset["name"], asset["digest"])
PY
    curl -fsSL --retry 3 "$(cat "$root/url")" -o "$root/driver.tar.gz"
    (cd "$root"; sha256sum -c sha256)
    mkdir "$root/driver"
    tar -xzf "$root/driver.tar.gz" -C "$root/driver"
    mapfile -t modules < <(find "$root/driver" -type f -name cassandra.so)
    test "${#modules[@]}" = 1
    ldd "${modules[0]}" | tee "$EVIDENCE/cassandra-linkage.txt"
    if grep -q 'not found' "$EVIDENCE/cassandra-linkage.txt"; then exit 1; fi
    sudo install -m 0644 "${modules[0]}" "$(php-config --extension-dir)/cassandra.so"
    printf 'extension=cassandra.so\n' | sudo tee /etc/php/8.5/mods-available/cassandra.ini >/dev/null
    sudo chmod 0644 /etc/php/8.5/mods-available/cassandra.ini
    sudo phpenmod -v 8.5 cassandra
    php --ini | tee "$EVIDENCE/cassandra-ini.txt"
    php --ri cassandra 2>&1 | tee "$EVIDENCE/cassandra-probe.txt"
    ;;
  quic)
    sudo apt-get update
    sudo apt-get install -y build-essential pkg-config libssl-dev php8.5-dev
    pkg-config --atleast-version=3.5 openssl
    curl -fsSL --retry 3 https://github.com/php/pie/releases/latest/download/pie.phar -o "$root/pie.phar"
    sudo -H php "$root/pie.phar" install --no-interaction mikepultz/php-quic
    test -f "$(php-config --extension-dir)/quic.so"
    if ! php -r 'exit(extension_loaded("quic") ? 0 : 1);'; then
      printf 'extension=quic.so\n' | sudo tee /etc/php/8.5/mods-available/quic.ini >/dev/null
      sudo chmod 0644 /etc/php/8.5/mods-available/quic.ini
      sudo phpenmod -v 8.5 quic
    fi
    php --ini | tee "$EVIDENCE/quic-ini.txt"
    php --ri quic 2>&1 | tee "$EVIDENCE/quic-probe.txt"
    ;;
  *) echo "Unknown native extension: $mode" >&2; exit 1 ;;
esac
php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$mode"
