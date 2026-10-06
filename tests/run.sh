#!/usr/bin/env bash
# Runs every SpeedPack Core test. See tests/README.md.
#   tests/run.sh            everything that can run here (suites needing Redis, MariaDB or Chromium are skipped when those are missing)
#   tests/run.sh --strict   a skipped suite counts as a failure
set -u
cd "$(dirname "$0")"
T=$(pwd); ROOT=${SPC_ROOT:-$(dirname "$T")}; MOD="$ROOT/speedpackcore"; export SPC_ROOT="$ROOT"
STRICT=0; [ "${1:-}" = "--strict" ] && STRICT=1
WORK=$(mktemp -d); PIDS=()
cleanup() { for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null; done; rm -rf "$WORK"; }
trap cleanup EXIT
pass=0; fail=0; skip=0; FAILED=()
step() { # name, command...
  local name=$1; shift
  printf '%-40s' "$name"
  if "$@" >"$WORK/out" 2>&1 && ! grep -q '^FAIL' "$WORK/out"; then echo ok; pass=$((pass+1))
  else echo FAIL; fail=$((fail+1)); FAILED+=("$name"); sed 's/^/    /' "$WORK/out" | tail -40; fi
}
skipped() { printf '%-40s%s\n' "$1" "skipped ($2)"; skip=$((skip+1)); }
port() { python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1])'; }
serve() { # port, command... : start a server and wait until it answers
  local p=$1; shift
  "$@" >"$WORK/server-$p.log" 2>&1 & PIDS+=($!)
  for _ in $(seq 50); do curl -s -o /dev/null "http://127.0.0.1:$p/" && return 0; sleep 0.1; done
  echo "server on $p did not start:"; cat "$WORK/server-$p.log"; return 1
}

# 1. syntax
lint_php() { local bad=0; while IFS= read -r f; do php -l "$f" >/dev/null || bad=1; done < <(find "$MOD" "$ROOT/asynccart" "$T" -name '*.php' -not -path '*/vendor/*'); return $bad; }
lint_js() { local bad=0; while IFS= read -r f; do node --check "$f" || bad=1; done < <(find "$MOD/views/js" "$ROOT/asynccart" "$T/browser" -name '*.js'); return $bad; }
step "lint: PHP" lint_php
if command -v node >/dev/null; then step "lint: JavaScript" lint_js; else skipped "lint: JavaScript" "no node"; fi

# 2. Smarty
if [ -z "${SPC_SMARTY:-}" ] && [ ! -f "$T/vendor/autoload.php" ]; then
  echo "Smarty missing: run 'composer install' in tests/ or set SPC_SMARTY"; exit 2
fi
export SPC_TMP="$WORK"

# 3. PHP suites
step "php: module (install, settings)" php php/unit.php
step "php: AsyncCart" php php/asynccart.php
if php -r 'exit(class_exists("Redis") ? 0 : 1);' && (exec 3<>/dev/tcp/127.0.0.1/"${REDIS_PORT:-6390}") 2>/dev/null; then
  step "php: data cache (Redis)" php php/cache.php
else skipped "php: data cache (Redis)" "no Redis on ${REDIS_PORT:-6390} or no phpredis"; fi

AP=$(port); export SPC_ADMIN_VARS="$WORK/admin-vars.json"
if serve "$AP" php -S "127.0.0.1:$AP" -t "$T/php" "$T/php/mock/audit-shop.php"; then
  step "php: speed audit (mock shop)" php php/audit.php "$AP"
else fail=$((fail+1)); FAILED+=("audit mock shop"); fi

if php -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);' && php -r 'try { new PDO(getenv("SPC_DB_DSN") ?: "mysql:host=localhost;dbname=spctest", getenv("SPC_DB_USER") ?: "lp", getenv("SPC_DB_PASS") ?: "lp"); } catch (Exception $e) { exit(1); }' 2>/dev/null; then
  WP=$(port)
  serve "$WP" php -S "127.0.0.1:$WP" "$T/php/mock/weight-shop.php" && step "php: health check (MariaDB)" php php/health.php "$WP"
else skipped "php: health check (MariaDB)" "no database, see README"; fi

# 4. browser
PW=${SPC_PLAYWRIGHT:-playwright}
if command -v node >/dev/null && node -e "require('$PW')" 2>/dev/null && [ -f "$SPC_ADMIN_VARS" ]; then
  php browser/render.php "$SPC_ADMIN_VARS" > "$WORK/admin.html"
  N=$(port); serve "$N" python3 browser/shop.py "$N" "$MOD" "$WORK/admin.html" && step "browser: audit, every configuration" node browser/audit.e2e.js "$N" normal
  C=$(port); serve "$C" python3 browser/shop.py "$C" "$MOD" "$WORK/admin.html" --pagecache && step "browser: audit behind a page cache" node browser/audit.e2e.js "$C" pagecache
  S=$(port); serve "$S" python3 browser/shop.py "$S" "$MOD" "$WORK/admin.html" && step "browser: SmartPrefetch, InstantNav" node browser/shop.e2e.js "$S"
else skipped "browser tests" "no node/playwright (set SPC_PLAYWRIGHT) or no audit vars"; fi

echo; echo "$pass passed, $fail failed, $skip skipped"
[ $fail -gt 0 ] && { printf '  failed: %s\n' "${FAILED[@]}"; exit 1; }
[ $STRICT = 1 ] && [ $skip -gt 0 ] && exit 1
exit 0
