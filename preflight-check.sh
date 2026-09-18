#!/usr/bin/env bash
# Preflight check for the flash-sale k6 load test.
# Run this BEFORE k6 to find out which layer is actually failing:
# network / auth token / sale window / product-item mapping.
#
# Usage:
#   BASE_URL=http://localhost \
#   TEST_EMAIL=you@example.com TEST_PASSWORD=secret \
#   FLASH_SALE_ID=1 PRODUCT_ID=1 SHIPPING_ADDRESS_ID=1 \
#   ./preflight-check.sh

set -uo pipefail

BASE_URL="${BASE_URL:-http://localhost}"
FLASH_SALE_ID="${FLASH_SALE_ID:?set FLASH_SALE_ID}"
PRODUCT_ID="${PRODUCT_ID:?set PRODUCT_ID}"
SHIPPING_ADDRESS_ID="${SHIPPING_ADDRESS_ID:?set SHIPPING_ADDRESS_ID}"
TEST_EMAIL="${TEST_EMAIL:?set TEST_EMAIL to a seeded, email-verified test user}"
TEST_PASSWORD="${TEST_PASSWORD:?set TEST_PASSWORD}"

pass() { printf '  \033[32mOK\033[0m  %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; }

echo "== 1. Is anything even listening at $BASE_URL ? =="
health_code=$(curl -s -o /tmp/health.json -w '%{http_code}' "$BASE_URL/health" --max-time 5)
if [ "$health_code" = "000" ]; then
  fail "Connection failed entirely (curl code 000). Wrong port/host, or the nginx/app containers aren't up."
  echo "  -> This app runs behind nginx on port 80 (see nginx/conf.d/default.conf)."
  echo "     'app:8000' in docker-compose is NOT an HTTP port (php-fpm speaks FastCGI on 9000 internally)."
  echo "     Try BASE_URL=http://localhost (not :8000), and run: docker compose ps"
  exit 1
elif [ "$health_code" != "200" ]; then
  fail "Got HTTP $health_code from /health"
  cat /tmp/health.json
else
  pass "Reachable, /health returned 200"
fi

echo "== 2. Logging in as $TEST_EMAIL to mint a real JWT =="
login_resp=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"email\":\"$TEST_EMAIL\",\"password\":\"$TEST_PASSWORD\"}")
token=$(echo "$login_resp" | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
if [ -z "${token:-}" ]; then
  token=$(echo "$login_resp" | grep -o '"token":"[^"]*"' | head -1 | cut -d'"' -f4)
fi
if [ -z "${token:-}" ]; then
  fail "No token in login response. Raw response:"
  echo "  $login_resp"
  echo "  -> If this says 'Invalid credentials', the user doesn't exist / wrong password."
  echo "  -> If it mentions email verification, the seeded user needs email_verified_at set."
  exit 1
else
  pass "Got a bearer token (${token:0:20}...)"
fi

echo "== 3. Checking the flash sale's active window =="
sale_resp=$(curl -s "$BASE_URL/flash-sales/$FLASH_SALE_ID" -H "Authorization: Bearer $token" -H 'Accept: application/json')
echo "  $sale_resp" | head -c 500; echo
status=$(echo "$sale_resp" | grep -o '"status":"[^"]*"' | head -1 | cut -d'"' -f4)
starts_at=$(echo "$sale_resp" | grep -o '"starts_at":"[^"]*"' | head -1 | cut -d'"' -f4)
ends_at=$(echo "$sale_resp" | grep -o '"ends_at":"[^"]*"' | head -1 | cut -d'"' -f4)
echo "  status=$status starts_at=$starts_at ends_at=$ends_at  (now: $(date -u +%Y-%m-%dT%H:%M:%SZ))"
if [ "$status" = "cancelled" ]; then
  fail "Sale status is 'cancelled' -> every purchase call will get 410 Gone."
elif [ -n "$starts_at" ] && [[ "$starts_at" > "$(date -u +%Y-%m-%dT%H:%M:%SZ)" ]]; then
  fail "starts_at is in the future -> every purchase call will get 403 (not started yet)."
else
  pass "Sale looks live (double-check ends_at hasn't passed too)"
fi

echo "== 4. Firing ONE real purchase request =="
purchase_resp=$(curl -s -w '\nHTTP_STATUS:%{http_code}' -X POST \
  "$BASE_URL/flash-sales/$FLASH_SALE_ID/purchase" \
  -H "Authorization: Bearer $token" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"product_id\":$PRODUCT_ID,\"quantity\":1,\"shipping_address_id\":$SHIPPING_ADDRESS_ID}")
body=$(echo "$purchase_resp" | sed '$d')
code=$(echo "$purchase_resp" | tail -1 | cut -d: -f2)
echo "  HTTP $code"
echo "  $body"
case "$code" in
  202) pass "Accepted — the purchase path works end-to-end." ;;
  401) fail "Unauthenticated — token is invalid/expired, or JWT_SECRET mismatch." ;;
  403) fail "Forbidden — sale hasn't started yet (see step 3)." ;;
  404) fail "Not found — either the flash sale, or no FlashSaleItem row links this PRODUCT_ID to this FLASH_SALE_ID." ;;
  409) pass "409 sold-out/duplicate — this is a VALID business outcome, not a bug." ;;
  410) fail "Gone — sale is cancelled or its window has ended." ;;
  422) fail "Validation error — check the body against docs/API.md." ;;
  429) fail "Rate limited — you've already used this user's 5/min purchase quota. Use a fresh token or wait." ;;
  5*) fail "Server error — check: docker compose logs app --tail=100" ;;
  *) fail "Unexpected status $code" ;;
esac
