# Flash Sale Engine API

> Generated from the supplied Postman collection. The source export contains **Products** and **Flash Sales** requests. It references bearer-token variables, but it does not contain authentication request definitions. The source also has empty captured-response arrays, so response examples here are representative rather than recorded server responses.

## Base URL

The supplied environment uses `http://localhost`; the Postman collection description also uses `http://0.0.0.0:8000`.

Protected requests use:

```http
Authorization: Bearer <access-token>
```

The environment defines customer/admin/staff access and refresh-token variables, plus IDs for addresses, products, flash sales, purchases, and admin-created resources. fileciteturn0file1L66-L105

## Products

### List products

```bash
curl --request GET '{{base_url}}/products' \
  --header 'Accept: application/json'
```

Price-range and pagination test variants:

```bash
curl --request GET '{{base_url}}/products?min_price=10&max_price=100&sort=price_asc' \
  --header 'Accept: application/json'

curl --request GET '{{base_url}}/products?per_page=500' \
  --header 'Accept: application/json'
```

The supplied tests expect a `200` response, ascending prices within 10–100 for the price-range request, and `per_page` no greater than 100.

### Create product

```bash
curl --request POST '{{base_url}}/products' \
  --header 'Authorization: Bearer {{admin_access_token}}' \
  --header 'Content-Type: application/json' \
  --data '{"name":"Test Widget 123","description":"A widget created via Postman test","base_price":49.99,"currency":"USD","sku":"WIDGET-123","is_active":true,"quantity_available":100}'
```

Expected success: `201`. The test checks for `id`, `slug`, and `inventory`.

Negative tests include: missing required fields (`422`), customer access (`403`), and no authentication (`401`).

### Get product by ID or slug

```bash
curl --request GET '{{base_url}}/products/{{product_id}}' \
  --header 'Accept: application/json'

curl --request GET '{{base_url}}/products/{{product_slug}}' \
  --header 'Accept: application/json'
```

The collection exercises the same `/products/{...}` route shape with both a numeric ID and a slug, and expects `404` for a nonexistent value.

### Update product

```bash
curl --request PATCH '{{base_url}}/products/{{product_id}}' \
  --header 'Authorization: Bearer {{admin_access_token}}' \
  --header 'Content-Type: application/json' \
  --data '{"base_price":39.99,"is_active":true}'
```

Expected success: `200`; the test expects `base_price` to be `39.99`.

### Delete product

```bash
curl --request DELETE '{{base_url}}/products/{{product_id}}?force=true' \
  --header 'Authorization: Bearer {{admin_access_token}}' \
  --header 'Accept: application/json'
```

The collection also tests customer deletion as forbidden (`403`) and verifies that a deleted product is no longer returned by the default lookup.

## Flash Sales

### Create flash sale

```bash
curl --request POST '{{base_url}}/flash-sales' \
  --header 'Authorization: Bearer {{admin_access_token}}' \
  --header 'Content-Type: application/json' \
  --data '{"title":"Postman Flash Sale","description":"Created via automated test","starts_at":"2020-01-01T00:00:00Z","ends_at":"2099-01-01T00:00:00Z","status":"active"}'
```

Expected success: `201` with an `id`.

Negative tests include end-before-start (`422`) and duplicate product entries in `items` (`422`).

### List flash sales

```bash
curl --request GET '{{base_url}}/flash-sales' \
  --header 'Accept: application/json'

curl --request GET '{{base_url}}/flash-sales?status=active' \
  --header 'Accept: application/json'
```

The collection expects `200`; the filtered test expects every returned sale to have `status = active`.

### Get, update, and delete flash sale

```bash
curl --request GET '{{base_url}}/flash-sales/{{flash_sale_id}}' \
  --header 'Accept: application/json'

curl --request PATCH '{{base_url}}/flash-sales/{{flash_sale_id}}' \
  --header 'Authorization: Bearer {{admin_access_token}}' \
  --header 'Content-Type: application/json' \
  --data '{"title":"Postman Flash Sale (Updated)"}'

curl --request DELETE '{{base_url}}/flash-sales/{{flash_sale_id}}' \
  --header 'Authorization: Bearer {{admin_access_token}}'
```

The tests expect `200` for the title update and delete. A timing change while the sale is active is expected to return `422`.

### Add and remove flash-sale item

```bash
curl --request POST '{{base_url}}/flash-sales/{{flash_sale_id}}/items' \
  --header 'Authorization: Bearer {{admin_access_token}}' \
  --header 'Content-Type: application/json' \
  --data '{"product_id":{{product_id}},"sale_price":19.99,"quantity_limit":20}'

curl --request DELETE '{{base_url}}/flash-sales/{{flash_sale_id}}/items/{{flash_sale_item_id}}' \
  --header 'Authorization: Bearer {{admin_access_token}}' \
  --header 'Accept: application/json'
```

Expected success: add item `201`; remove item `204`.

The tests also cover duplicate item (`422`) and customer-forbidden (`403`).

### Purchase flash-sale item

```bash
curl --request POST '{{base_url}}/flash-sales/{{flash_sale_id}}/purchase' \
  --header 'Authorization: Bearer {{customer_access_token}}' \
  --header 'Content-Type: application/json' \
  --data '{"product_id":{{product_id}},"quantity":1,"shipping_address_id":{{customer_address_id}}}'
```

Expected success: `202 Accepted`, with `reference_id` and `status_url`.

Negative tests include quantity above the sale limit (`422`), product not in sale (`422`), unauthenticated (`401`), and nonexistent sale (`404` in the current test).

### Check purchase status

```bash
curl --request GET '{{base_url}}/flash-sales/purchases/{{purchase_reference}}/status' \
  --header 'Accept: application/json'
```

Expected success: `200`. The tested status values are `pending`, `processing`, `completed`, and `failed`. Unknown references return `404`.

## Response expectations from the Postman tests

| Operation | Success | Tested failure cases |
|---|---:|---|
| Create product | 201 | 401 / 403 / 422 |
| Get/update/delete product | 200 | 403 / 404 |
| Create flash sale | 201 | 401 / 403 / 422 |
| Add flash-sale item | 201 | 401 / 403 / 422 |
| Purchase flash-sale item | 202 | 401 / 403 / 404 / 422 |
| Purchase status | 200 | 404 |

## OpenAPI / Swagger

Use `openapi.yaml` with Swagger UI, Redoc, or another OpenAPI 3.x viewer.

## Source gap to be aware of

The collection metadata describes the suite as covering Auth, Product, and Flash Sale endpoints, but the exported request tree contains only **Products** and **Flash Sales**. There are no login/register/auth requests in the supplied collection. The environment does contain empty customer/admin/staff access and refresh token variables. fileciteturn0file1L66-L99

Therefore this Day 18 documentation package documents the endpoint contracts actually present in the supplied Postman export rather than inventing missing authentication endpoints.
