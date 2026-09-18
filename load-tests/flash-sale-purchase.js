import http from 'k6/http';
import { check, sleep } from 'k6';
import { SharedArray } from 'k6/data';

const baseUrl = (__ENV.BASE_URL || 'http://127.0.0.1:8000/api').replace(/\/$/, '');
const saleId = __ENV.FLASH_SALE_ID || '1';
const productId = __ENV.PRODUCT_ID || '1';
const shippingAddressId = __ENV.SHIPPING_ADDRESS_ID || '1';
const quantity = Number(__ENV.QUANTITY || 1);
const maxVUs = Number(__ENV.MAX_VUS || 250);

const users = new SharedArray('customer credentials', () => {
  const candidatePaths = [
    __ENV.USERS_FILE || './load-tests/users.json',
    './load-tests/users.json',
    './users.json',
  ];

  for (const filePath of candidatePaths) {
    try {
      const parsed = JSON.parse(open(filePath));
      if (Array.isArray(parsed) && parsed.length > 0) {
        const validUsers = parsed.filter((user) => user && user.email && user.password);
        if (validUsers.length > 0) {
          return validUsers;
        }
      }
    } catch (error) {
      // Keep checking the next fallback path.
    }
  }

  throw new Error(
    `Could not load user credentials. Create a JSON array like [{"email":"user@example.com","password":"Password123!"}] in ${candidatePaths.join(' or ')}`,
  );
});

function getUserForRequest() {
  // A VU keeps its identity across iterations. Striding by maxVUs gives each
  // iteration a different user before the credential list wraps around.
  const index = (((__VU - 1) + (__ITER * maxVUs)) % users.length + users.length) % users.length;
  return users[index];
}

function extractToken(response) {
  if (response.status !== 200) {
    return null;
  }

  try {
    const body = response.json();
    return body.token || body.access_token || body.data?.token || body.data?.access_token || body.authorisation?.token || null;
  } catch (error) {
    return null;
  }
}

http.setResponseCallback(http.expectedStatuses(200, 202, 401, 409));

export const options = {
  scenarios: {
    flash_sale_burst: {
      executor: 'ramping-arrival-rate',
      startRate: Number(__ENV.START_RATE || 0),
      timeUnit: '1s',
      preAllocatedVUs: Number(__ENV.PREALLOCATED_VUS || 50),
      maxVUs,
      stages: [
        { target: Number(__ENV.ARRIVAL_RATE || 50), duration: __ENV.RAMP || '5s' },
        { target: Number(__ENV.ARRIVAL_RATE || 50), duration: __ENV.HOLD || '15s' },
        { target: 0, duration: __ENV.COOLDOWN || '5s' },
      ],
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],
    checks: ['rate>0.95'],
    http_req_duration: ['p(95)<1000'],
  },
};

export default function () {
  const user = getUserForRequest();

  const loginResponse = http.post(
    `${baseUrl}/auth/login`,
    JSON.stringify({
      email: user.email,
      password: user.password,
    }),
    {
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      tags: { endpoint: 'auth-login' },
    },
  );

  const token = extractToken(loginResponse);
  const loginSuccessful = check(loginResponse, {
    'login succeeds': (r) => r.status === 200,
    'login returns token': () => !!token,
  });

  if (!loginSuccessful || !token) {
    sleep(0.2);
    return;
  }

  const purchaseResponse = http.post(
    `${baseUrl}/flash-sales/${saleId}/purchase`,
    JSON.stringify({
      product_id: Number(productId),
      quantity,
      shipping_address_id: Number(shippingAddressId),
    }),
    {
      headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      tags: { endpoint: 'flash-sale-purchase' },
    },
  );

  const accepted = purchaseResponse.status === 202;
  const responseBody = purchaseResponse.body || '';
  const soldOut = purchaseResponse.status === 409 && (
    responseBody.includes('sold out') || responseBody.includes('out of stock')
  );
  const protectedDuplicate = purchaseResponse.status === 409 && (
    responseBody.includes('already being processed') || responseBody.includes('already purchased')
  );

  check(purchaseResponse, {
    'purchase accepted or rejected by business rule': () => accepted || soldOut || protectedDuplicate,
    'purchase should not be a server error': (r) => r.status < 500,
  });

  sleep(0.01);
}
