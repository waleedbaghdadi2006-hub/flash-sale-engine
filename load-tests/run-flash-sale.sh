#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
K6_BIN="${K6_BIN:-k6}"

"$K6_BIN" run \
  -e "BASE_URL=${BASE_URL:-http://127.0.0.1}" \
  -e "FLASH_SALE_ID=${FLASH_SALE_ID:-8}" \
  -e "PRODUCT_ID=${PRODUCT_ID:-5}" \
  -e "SHIPPING_ADDRESS_ID=${SHIPPING_ADDRESS_ID:-1}" \
  -e "TOKENS_FILE=${TOKENS_FILE:-${SCRIPT_DIR}/tokens.json}" \
  -e "ARRIVAL_RATE=${ARRIVAL_RATE:-50}" \
  -e "MAX_VUS=${MAX_VUS:-250}" \
  "${SCRIPT_DIR}/flash-sale-purchase.js" \
  "$@"
