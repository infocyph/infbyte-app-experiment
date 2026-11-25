# Sourced by risk-edge.sh after the origin is started.
curl --fail --silent --show-error --location --proto '=https' --max-time 45 https://raw.githubusercontent.com/FriendlyCaptcha/ja4-haproxy/main/ja4.lua -o "$root/ja4.lua"
sha256sum "$root/ja4.lua" > "$EVIDENCE/risk-ja4-module.sha256"
cat "$root/cert.pem" "$root/key.pem" > "$root/haproxy.pem"
cat > "$root/haproxy.cfg" <<'CONF'
global
  log stdout format raw local0
  tune.ssl.capture-buffer-size 1024
  tune.lua.bool-sample-conversion normal
  lua-load-per-thread /fixture/ja4.lua raw
defaults
  mode http
  log global
  timeout connect 5s
  timeout client 20s
  timeout server 20s
frontend tls
  bind 127.0.0.1:18783 ssl crt /fixture/haproxy.pem alpn http/1.1 ssl-min-ver TLSv1.2
  acl protected path /api/lab/login
  http-request del-header X-Lab-JA4
  http-request del-header X-Lab-ASN
  http-request del-header X-Forwarded-For
  http-request set-header X-Lab-Client-IP %[src]
  http-request set-header Host localhost
  http-request lua.ja4 if protected
  http-request set-header X-Lab-JA4 %[var(txn.ja4)] if protected
  log-format "ja4=%[var(txn.ja4)] raw=%[var(txn.ja4_r)]"
  default_backend origin
backend origin
  server origin 127.0.0.1:18781 source 127.0.0.2
CONF
docker run --rm --network host --user "$(id -u):$(id -g)" --read-only --cap-drop ALL --security-opt no-new-privileges -v "$root:/fixture:ro" haproxy:3.2 haproxy -c -f /fixture/haproxy.cfg
docker run -d --name lab-risk-ja4 --network host --user "$(id -u):$(id -g)" --read-only --cap-drop ALL --security-opt no-new-privileges -v "$root:/fixture:ro" haproxy:3.2 haproxy -db -f /fixture/haproxy.cfg
edge=https://localhost:18783
