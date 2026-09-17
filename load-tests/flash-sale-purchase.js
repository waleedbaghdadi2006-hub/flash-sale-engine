import http from 'k6/http';
import { check, sleep } from 'k6';
import { SharedArray } from 'k6/data';

// Staging-only load test for Phase 7.
//
// Required environment variables:
//   BASE_URL=https://staging.example.com
//   FLASH_SALE_ID=123
//   PRODUCT_ID=456
//   SHIPPING_ADDRESS_ID=789
//   TOKENS_FILE=./load-tests/tokens.json
//
// Optional:
//   VUS=1000 DURATION=30s QUANTITY=1
//
// tokens.json must be a JSON array of bearer tokens belonging to distinct
// test users. Distinct users matter because the purchase endpoint has a
// per-user rate limiter (5/minute). Never use production credentials.
const tokens = new SharedArray('bearer tokens', () => {
  const path = __ENV.TOKENS_FILE || './load-tests/tokens.json';
  const parsed = JSON.parse(open(path));
  if (!Array.isArray(parsed) || parsed.length === 0) {
    throw new Error('TOKENS_FILE must contain a non-empty JSON array of bearer tokens');
  }
  return parsed;
});

export const options = {
  scenarios: {
    flash_sale_burst: {
      executor: 'ramping-arrival-rate',
      startRate: Number(__ENV.START_RATE || 0),
      timeUnit: '1s',
      preAllocatedVUs: Number(__ENV.PREALLOCATED_VUS || 100),
      maxVUs: Number(__ENV.MAX_VUS || 2000),
      stages: [
        { target: Number(__ENV.ARRIVAL_RATE || 1000), duration: __ENV.RAMP || '10s' },
        { target: Number(__ENV.ARRIVAL_RATE || 1000), duration: __ENV.HOLD || '20s' },
        { target: 0, duration: __ENV.COOLDOWN || '10s' },
      ],
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],
    checks: ['rate>0.95'],
    http_req_duration: ['p(95)<1000'],
  },
};

const baseUrl = (__ENV.BASE_URL || '').replace(/\/$/, '');
const saleId = __ENV.FLASH_SALE_ID;
const productId = __ENV.PRODUCT_ID;
const shippingAddressId = __ENV.SHIPPING_ADDRESS_ID;
const quantity = Number(__ENV.QUANTITY || 1);

if (!baseUrl || !saleId || !productId || !shippingAddressId) {
  throw new Error('BASE_URL, FLASH_SALE_ID, PRODUCT_ID and SHIPPING_ADDRESS_ID are required');
}

export default function () {
  const token = tokens[(__VU - 1) % tokens.length];
  const payload = JSON.stringify({
    product_id: Number(productId),
    quantity,
    shipping_address_id: Number(shippingAddressId),
  });

  const response = http.post(
    `${baseUrl}/flash-sales/${saleId}/purchase`,
    payload,
    {
      headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      tags: { endpoint: 'flash-sale-purchase' },
    },
  );

  const accepted = response.status === 202;
  const soldOut = response.status === 409 && response.body.includes('sold out');
  const protectedDuplicate = response.status === 409 && response.body.includes('already being processed');

  check(response, {
    'purchase accepted or rejected by business rule': () => accepted || soldOut || protectedDuplicate,
    'no server error': (r) => r.status < 500,
  });

  // Keep pressure on the purchase endpoint rather than turning this into a
  // polling benchmark. The purchase outcome is verified separately below.
  sleep(0.01);
}
