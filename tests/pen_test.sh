#!/usr/bin/env bash
# Black-box pen test against a running WordPress + SignDocs plugin.
# Exits 0 if no vulnerabilities were demonstrated; 1 otherwise.

set -uo pipefail

BASE="${WP_URL:-http://localhost:18080}"
ADMIN_USER="admin"
ADMIN_PASS="p3ntest"
COOKIE_JAR="/tmp/pentest-cookies.txt"
FAILED=0

rm -f "$COOKIE_JAR"

say() { printf '\n\033[1;36m%s\033[0m\n' "$*"; }
pass() { printf '  \033[32mPASS\033[0m  %s\n' "$*"; }
fail() { printf '  \033[31mFAIL\033[0m  %s\n' "$*"; FAILED=$((FAILED+1)); }
info() { printf '  \033[90m%s\033[0m\n' "$*"; }

assert_no_match() {
  local label="$1" needle="$2" haystack="$3"
  if printf '%s' "$haystack" | grep -qF -- "$needle"; then
    fail "$label (payload leaked into response: '$needle')"
  else
    pass "$label"
  fi
}

assert_status() {
  local label="$1" expected="$2" actual="$3"
  if [[ "$actual" == "$expected" ]]; then
    pass "$label (HTTP $actual)"
  else
    fail "$label (expected HTTP $expected, got $actual)"
  fi
}

wait_for_wp() {
  for _ in $(seq 1 30); do
    if curl -sf "$BASE/wp-login.php" -o /dev/null; then return 0; fi
    sleep 1
  done
  echo "WP never became reachable at $BASE"; exit 2
}

login() {
  curl -s -c "$COOKIE_JAR" \
    -d "log=$ADMIN_USER&pwd=$ADMIN_PASS&wp-submit=Log+In&redirect_to=$BASE/wp-admin&testcookie=1" \
    -b "wordpress_test_cookie=WP%20Cookie%20check" \
    "$BASE/wp-login.php" -o /dev/null
  # Session cookie check
  if grep -q "wordpress_logged_in" "$COOKIE_JAR"; then
    info "logged in as $ADMIN_USER"
  else
    echo "Login failed"; exit 2
  fi
}

# ───────────────────────────────────────────────────────────────
wait_for_wp

say "Authenticating…"
login

say "[T1] SQLi via audit-log list-table filters (authenticated admin)"
for payload in \
  "' OR 1=1 --" \
  "'; DROP TABLE wp_users; --" \
  "' UNION SELECT user_login,user_pass,user_email,NULL,NULL FROM wp_users --" \
  "1' AND (SELECT SLEEP(5)) AND '1'='1" \
  "<script>alert(1)</script>" \
  "../../../../etc/passwd"
do
  label="payload=[${payload:0:40}…]"
  start=$(date +%s)
  resp=$(curl -s -b "$COOKIE_JAR" \
    --data-urlencode "post_type=signdocs_signing" \
    --data-urlencode "page=signdocs-audit-log" \
    --data-urlencode "level=${payload}" \
    --data-urlencode "event_type=${payload}" \
    --data-urlencode "from=${payload}" \
    --data-urlencode "to=${payload}" \
    --data-urlencode "orderby=${payload}" \
    --data-urlencode "order=${payload}" \
    -G "$BASE/wp-admin/edit.php")
  elapsed=$(( $(date +%s) - start ))

  # No admin_pass field ever leaks (UNION-SELECT attempt surfacing user hash)
  assert_no_match "$label — password hash not disclosed" '$P$' "$resp"
  assert_no_match "$label — raw payload not reflected into SQL error" 'You have an error in your SQL syntax' "$resp"
  # Time-blind SLEEP(5) shouldn't actually block the page > 3s
  if (( elapsed >= 4 )); then
    fail "$label — request took ${elapsed}s (time-blind SQLi suspected)"
  else
    pass "$label — request completed in ${elapsed}s"
  fi
  # wp_users table should still exist (DROP TABLE would have killed it).
  # Query through the MariaDB container directly — wp-cli's db.query
  # needs the mysql client which isn't bundled in the wordpress image.
  wp_users_count=$(podman exec sdb-pentest-db mariadb -uwp -pwppw wp -sN -e 'SELECT COUNT(*) FROM wp_users' 2>/dev/null)
  if [[ "$wp_users_count" -ge "1" ]] 2>/dev/null; then
    pass "$label — wp_users table intact (count=$wp_users_count)"
  else
    fail "$label — wp_users table damaged (count='$wp_users_count')"
  fi
done

say "[T2] SQLi via CSV export endpoint (admin-post.php signdocs_audit_export)"
# Get a fresh nonce
nonce=$(curl -s -b "$COOKIE_JAR" "$BASE/wp-admin/edit.php?post_type=signdocs_signing&page=signdocs-audit-log" \
  | grep -oE 'signdocs_audit_export[^"]*_wpnonce=[a-f0-9]{10}' | head -1 | grep -oE '[a-f0-9]{10}$')
info "audit export nonce: $nonce"
if [[ -z "$nonce" ]]; then
  info "couldn't extract nonce — admin UI may not be rendering filters (still valid test for unauth rejection)"
fi

payload="' UNION SELECT user_login,user_pass,NULL,NULL,NULL FROM wp_users --"
resp=$(curl -s -b "$COOKIE_JAR" \
  --data-urlencode "action=signdocs_audit_export" \
  --data-urlencode "_wpnonce=$nonce" \
  --data-urlencode "event_type=$payload" \
  -G "$BASE/wp-admin/admin-post.php")
assert_no_match "CSV export — no password hash disclosure via UNION" '$P$' "$resp"

say "[T3] Webhook HMAC bypass attempts"
hmac_endpoint="$BASE/wp-json/signdocs/v1/webhook"
# Precondition: a webhook secret must be configured. If not, authorize
# returns 500 "secret not configured" before ever checking HMAC — which
# is correct defensive behavior, but makes the HMAC-bypass test cases
# vacuous. The test runner is expected to have configured the secret
# via wp-cli before calling this script.
WEBHOOK_SECRET="${WEBHOOK_SECRET:-test-webhook-secret-for-pentest-not-real}"

# No HMAC at all — should 401 (signature header empty → HMAC fails)
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$hmac_endpoint" \
  -H 'Content-Type: application/json' \
  -H "X-SignDocs-Timestamp: $(date +%s)" \
  -d '{"eventType":"TRANSACTION.COMPLETED"}')
assert_status "webhook without HMAC rejected" "401" "$code"

# Wrong signature
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$hmac_endpoint" \
  -H 'Content-Type: application/json' \
  -H "X-SignDocs-Timestamp: $(date +%s)" \
  -H 'X-SignDocs-Signature: 0000000000000000000000000000000000000000000000000000000000000000' \
  -d '{"eventType":"TRANSACTION.COMPLETED"}')
assert_status "webhook with wrong HMAC rejected" "401" "$code"

# Old timestamp — should 400 (drift gate fires before HMAC check)
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$hmac_endpoint" \
  -H 'Content-Type: application/json' \
  -H "X-SignDocs-Timestamp: 1" \
  -H 'X-SignDocs-Signature: 0000' \
  -d '{}')
assert_status "webhook with stale timestamp rejected" "400" "$code"

# Empty / non-digit timestamp — should 400
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$hmac_endpoint" \
  -H 'Content-Type: application/json' \
  -H 'X-SignDocs-Timestamp: not-a-number' \
  -H 'X-SignDocs-Signature: 0000' \
  -d '{}')
assert_status "webhook with garbage timestamp rejected" "400" "$code"

# Positive control: valid HMAC should 200. Also exercises the dedup path.
body='{"eventType":"TRANSACTION.COMPLETED","data":{"sessionId":"s_pen_1","transactionId":"t_pen_1"}}'
ts=$(date +%s)
wid="wh_pen_${ts}_$$"
sig=$(printf '%s.%s' "$ts" "$body" | openssl dgst -sha256 -hmac "$WEBHOOK_SECRET" | awk '{print $2}')
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$hmac_endpoint" \
  -H 'Content-Type: application/json' \
  -H "X-SignDocs-Timestamp: $ts" \
  -H "X-SignDocs-Signature: $sig" \
  -H "X-SignDocs-Webhook-Id: $wid" \
  -d "$body")
assert_status "webhook with valid HMAC accepted" "200" "$code"

# Replay with same webhook-id — should still 200 but with deduped=true.
resp=$(curl -s -X POST "$hmac_endpoint" \
  -H 'Content-Type: application/json' \
  -H "X-SignDocs-Timestamp: $ts" \
  -H "X-SignDocs-Signature: $sig" \
  -H "X-SignDocs-Webhook-Id: $wid" \
  -d "$body")
if printf '%s' "$resp" | grep -q '"deduped":true'; then
  pass "webhook replay de-duplicated via X-SignDocs-Webhook-Id"
else
  fail "webhook replay was NOT de-duplicated (response: $resp)"
fi

say "[T4] CSRF — audit export without nonce should 403"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" \
  --data-urlencode "action=signdocs_audit_export" \
  "$BASE/wp-admin/admin-post.php")
assert_status "audit export without valid nonce" "403" "$code"

say "[T5] Auth — audit-log view as subscriber (no signdocs_view_logs cap)"
podman exec sdb-pentest-wp wp --allow-root user create bob bob@example.com --role=subscriber --user_pass=bobpw 2>&1 | tail -1
SUB_JAR="/tmp/pentest-sub-cookies.txt"
rm -f "$SUB_JAR"
curl -s -c "$SUB_JAR" -d "log=bob&pwd=bobpw&wp-submit=Log+In&redirect_to=$BASE/wp-admin&testcookie=1" \
  -b "wordpress_test_cookie=WP%20Cookie%20check" "$BASE/wp-login.php" -o /dev/null
resp=$(curl -s -b "$SUB_JAR" "$BASE/wp-admin/edit.php?post_type=signdocs_signing&page=signdocs-audit-log")
if printf '%s' "$resp" | grep -qE 'Sem permissão|do not have (sufficient permissions|permission)|Sorry, you are not allowed'; then
  pass "subscriber blocked from audit log"
else
  fail "subscriber may be seeing audit log (check response)"
fi

say "Summary"
if (( FAILED == 0 )); then
  printf '\033[1;32mALL CHECKS PASSED\033[0m\n'
  exit 0
else
  printf '\033[1;31m%d FAILURE(S)\033[0m\n' "$FAILED"
  exit 1
fi
