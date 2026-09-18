import http from 'k6/http';
import { check, sleep } from 'k6';
import { SharedArray } from 'k6/data';

const baseUrl = (__ENV.BASE_URL || 'http://127.0.0.1').replace(/\/$/, '');
const saleId = __ENV.FLASH_SALE_ID || '1';
const productId = __ENV.PRODUCT_ID || '1';
const shippingAddressId = Number(__ENV.SHIPPING_ADDRESS_ID || 1);
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
      // Try the next path so the script works from the repository or load-tests directory.
    }
  }

  throw new Error(
    `Could not load user credentials. Create a JSON array like [{"email":"user@example.com","password":"Password123!"}] in ${candidatePaths.join(' or ')}`,
  );
});

function getUserForRequest() {
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

http.setResponseCallback(http.expectedStatuses(200, 202, 401, 409, 429));

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

let session = null;

function createSession(user) {
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
    return null;
  }

  const addressesResponse = http.get(`${baseUrl}/addresses`, {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
    },
    tags: { endpoint: 'addresses-index' },
  });

  let addresses = [];
  try {
    addresses = addressesResponse.json();
  } catch (error) {
    addresses = [];
  }

  const shippingAddress = Array.isArray(addresses)
    ? addresses.find((address) => address.is_default_shipping) || addresses[0]
    : null;
  const addressLoaded = check(addressesResponse, {
    'address lookup succeeds': (r) => r.status === 200,
    'user has a shipping address': () => !!shippingAddress,
  });

  if (!addressLoaded) {
    return null;
  }

  return { token, shippingAddressId: shippingAddress.id };
}

export default function () {
  if (!session) {
    session = createSession(getUserForRequest());
  }

  if (!session) {
    sleep(0.2);
    return;
  }

  const purchaseResponse = http.post(
    `${baseUrl}/flash-sales/${saleId}/purchase`,
    JSON.stringify({
      product_id: Number(productId),
      quantity,
      shipping_address_id: Number(session.shippingAddressId || shippingAddressId),
    }),
    {
      headers: {
        Authorization: `Bearer ${session.token}`,
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
  const rateLimited = purchaseResponse.status === 429;

  check(purchaseResponse, {
    'purchase accepted or rejected by business rule': () => accepted || soldOut || protectedDuplicate || rateLimited,
    'purchase should not be a server error': (r) => r.status < 500,
  });

  sleep(0.01);
}
