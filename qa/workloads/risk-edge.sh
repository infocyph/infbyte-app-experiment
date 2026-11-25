#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
cd "$PROJECT"
export AUTH_TOKEN_SECRET="$(php -r 'echo bin2hex(random_bytes(32));')"
export AUTH_OTP_RECOVERY_HMAC_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
export AUTH_OTP_PROTECTION_KEY="$(php -r 'echo rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");')"
export LAB_EDGE_PASSWORD="$(php -r 'echo bin2hex(random_bytes(24));')"
export LAB_EDGE_AUTHORITY="$(php -r 'echo bin2hex(random_bytes(32));')"
export LAB_EDGE_TELEMETRY="$(php -r 'echo bin2hex(random_bytes(32));')"
export LAB_EDGE_TIME="$(date +%s)"
export APP_ENV=production APP_DEBUG=false
export LAB_EDGE_MEASURE_JA4=0
if [[ "$SCENARIO" == ecosystem-risk-ja4 ]]; then export LAB_EDGE_MEASURE_JA4=1; fi
root="$(mktemp -d "$RUNNER_TEMP/risk-edge.XXXXXXXX")"
cleanup() {
  docker logs lab-risk-fpm > "$EVIDENCE/risk-fpm.log" 2>&1 || :
  docker logs lab-risk-nginx > "$EVIDENCE/risk-nginx.log" 2>&1 || :
  docker logs lab-risk-ja4 > "$EVIDENCE/risk-ja4.log" 2>&1 || :
  docker rm -f lab-risk-ja4 lab-risk-nginx lab-risk-fpm >/dev/null 2>&1 || :
  rm -rf "$root"
}
trap cleanup EXIT
php "$GITHUB_WORKSPACE/qa/workloads/prepare-http.php"
php "$GITHUB_WORKSPACE/qa/workloads/risk-edge-seed.php"
php infbyte config:validate --production
php infbyte optimize --json=1 > "$root/release.json"
php infbyte app:ready
export INFOCYPH_FOUNDATION_RELEASE_ROOT="$(php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);echo $r["release_root"];' "$root/release.json")"
export INFOCYPH_FOUNDATION_RELEASE_MANIFEST_SHA256="$(php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);echo $r["manifest_sha256"];' "$root/release.json")"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=localhost -addext subjectAltName=DNS:localhost -keyout "$root/key.pem" -out "$root/cert.pem" >/dev/null 2>&1
cat > "$root/fpm.conf" <<'CONF'
[global]
daemonize = no
error_log = /proc/self/fd/2
[ordinary]
listen = 127.0.0.1:19000
pm = static
pm.max_children = 2
clear_env = no
env[LAB_POOL] = ordinary
[protected]
listen = 127.0.0.1:19001
pm = static
pm.max_children = 1
clear_env = no
env[LAB_POOL] = protected
CONF
cat > "$root/fastcgi.conf" <<CONF
include /etc/nginx/fastcgi_params;
fastcgi_param SCRIPT_FILENAME $PROJECT/public/index.php;
fastcgi_param SCRIPT_NAME /index.php;
fastcgi_param INFOCYPH_FOUNDATION_RELEASE_ROOT $INFOCYPH_FOUNDATION_RELEASE_ROOT;
fastcgi_param INFOCYPH_FOUNDATION_RELEASE_MANIFEST_SHA256 $INFOCYPH_FOUNDATION_RELEASE_MANIFEST_SHA256;
CONF
cat > "$root/nginx.conf" <<'CONF'
events { worker_connections 128; }
http {
  access_log off;
  server {
    listen 127.0.0.1:18781;
    location = /api/lab/login {
      allow 127.0.0.2;
      deny all;
      include /fixture/fastcgi.conf;
      fastcgi_pass 127.0.0.1:19001;
    }
    location / {
      include /fixture/fastcgi.conf;
      fastcgi_pass 127.0.0.1:19000;
    }
  }
  server {
    listen 127.0.0.1:18782 ssl;
    ssl_certificate /fixture/cert.pem;
    ssl_certificate_key /fixture/key.pem;
    location / {
      proxy_bind 127.0.0.2;
      proxy_set_header Host localhost;
      proxy_set_header X-Lab-Client-IP $remote_addr;
      proxy_set_header X-Lab-JA4 "";
      proxy_set_header X-Lab-ASN "";
      proxy_set_header X-Forwarded-For "";
      proxy_pass http://127.0.0.1:18781;
    }
  }
}
CONF
docker run -d --name lab-risk-fpm --network host --user "$(id -u):$(id -g)" -v "$PROJECT:$PROJECT" -v "$root:/fixture:ro" -w "$PROJECT" \
  -e APP_ENV -e APP_DEBUG -e AUTH_TOKEN_SECRET -e AUTH_OTP_RECOVERY_HMAC_KEY -e AUTH_OTP_PROTECTION_KEY \
  -e LAB_EDGE_PASSWORD -e LAB_EDGE_AUTHORITY -e LAB_EDGE_TELEMETRY -e LAB_EDGE_TIME -e LAB_EDGE_MEASURE_JA4 \
  -e INFOCYPH_FOUNDATION_RELEASE_ROOT -e INFOCYPH_FOUNDATION_RELEASE_MANIFEST_SHA256 \
  infbyte-lab-fpm php-fpm -F -y /fixture/fpm.conf
docker run -d --name lab-risk-nginx --network host -v "$root:/fixture:ro" -v "$root/nginx.conf:/etc/nginx/nginx.conf:ro" nginx:alpine
edge=https://localhost:18782
if [[ "$LAB_EDGE_MEASURE_JA4" == 1 ]]; then
  source "$GITHUB_WORKSPACE/qa/workloads/risk-ja4-start.sh"
fi
for attempt in {1..40}; do
  if curl -fsS --max-time 2 --cacert "$root/cert.pem" "$edge/api/lab/ordinary" > "$root/ordinary.json"; then break; fi
  sleep 1
done
php -r '$r=json_decode(file_get_contents($argv[1]),true,flags:JSON_THROW_ON_ERROR);if($r!==["ok"=>true,"pool"=>"ordinary"])exit(1);' "$root/ordinary.json"
direct="$(curl -sS -o /dev/null -w '%{http_code}' -X POST -H 'X-Lab-Client-IP: 127.0.0.1' http://127.0.0.1:18781/api/lab/login)"
[[ "$direct" == 403 ]]
login() {
  local expected="$1" password="$2" status version="${3:-1.3}"
  local tls=()
  if [[ "$LAB_EDGE_MEASURE_JA4" == 1 ]]; then tls+=(--http1.1 "--tlsv$version" --tls-max "$version"); fi
  status="$(curl -sS "${tls[@]}" --max-time 15 --cacert "$root/cert.pem" -o "$root/login.json" -D "$root/headers" -w '%{http_code}' \
    -H 'X-Lab-Client-IP: 198.51.100.1' -H 'X-Lab-JA4: forged' -H 'X-Lab-ASN: 64500' \
    --data-urlencode "password=$password" "$edge/api/lab/login")"
  [[ "$status" == "$expected" ]]
  grep -Eiq '^cache-control:.*no-store' "$root/headers"
  if [[ "$LAB_EDGE_MEASURE_JA4" == 1 ]]; then
    local observed
    observed="$(awk 'tolower($1)=="x-lab-observed-ja4:" {gsub(/\r/, "", $2); print $2}' "$root/headers")"
    [[ "$observed" == t"${version/./}"d* ]]
    printf '%s\n' "$observed" >> "$root/observed-ja4"
  fi
}
if [[ "$LAB_EDGE_MEASURE_JA4" == 1 ]]; then login 200 "$LAB_EDGE_PASSWORD" 1.2; fi
login 200 "$LAB_EDGE_PASSWORD"
login 401 wrong
login 429 "$LAB_EDGE_PASSWORD"
for i in {1..20}; do curl -fsS --max-time 3 --cacert "$root/cert.pem" "$edge/api/lab/ordinary" | grep -q '"pool":"ordinary"'; done
if [[ "$LAB_EDGE_MEASURE_JA4" == 1 ]]; then
  docker logs lab-risk-ja4 > "$EVIDENCE/risk-ja4.log" 2>&1
  php "$GITHUB_WORKSPACE/qa/workloads/risk-ja4-verify.php" "$root/observed-ja4" "$EVIDENCE/risk-ja4.log"
fi
echo 'Actual verified TLS edge, direct origin rejection, overwritten identity, isolated FPM pools and login-only challenge passed.'
