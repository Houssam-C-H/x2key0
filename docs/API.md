# X2KEY Seller API v1

Connect your own tools to your X2KEY store: keep prices and stock in sync, add Storage keys automatically and read
sold orders. Every write goes through exactly the same checks as the website (prices, stock, review rules, price
history, service types, duplicate keys).

## Authentication

1. Seller Center › **API tokens** › New token. Choose a name and only the permissions you need. You will be asked
   for your password again. The token is shown **once** — store it in a secret manager.
2. Send it on every request: `Authorization: Bearer <token>` and `Accept: application/json`.
3. Revoke a token at any time on the same page. Tokens are tied to your account; a suspended or banned store
   gets `403` on every call.

| Permission | Allows |
|---|---|
| `offers:read` | list and read your offers |
| `offers:write` | create and update offers, deactivate / relist |
| `orders:read` | list and read your sold orders (no buyer contact data, never delivered secrets) |
| `storage:write` | add items to your Storage groups — needs two-step verification on the account |

Base URL: `https://<domain>/api/v1/seller`. Limit: **120 requests per minute per token** (`429` with `Retry-After`
above that). Money is in **USD** as a decimal string (`"12.50"`).

## Offers

`GET /offers?status=active&per_page=50&page=1` → `{ data: Offer[], meta: { page, last_page, total } }`

`GET /offers/{id}` → `{ data: Offer }`

```json
{
  "id": "01m8…", "status": "active", "game_category_id": 12, "product_id": 345, "service_type_id": null,
  "title": "Steam key — instant", "unit_price": "12.50", "currency": "USD", "min_qty": 1, "max_qty": null,
  "manual_stock_qty": 20, "stock": 20, "fulfillment": "manual", "delivery_method": "digital_key",
  "delivery_time_minutes": 30, "expires_at": "2026-12-25T10:00:00+00:00", "url": "/en/offer/01m8…"
}
```

`POST /offers` — create. Required: `game_category_id`, `delivery_method`, `title`, `unit_price`,
`manual_stock_qty`; optional: `product_id`, `service_type_id`, `min_qty`, `max_qty`, `delivery_time_minutes`
(one of 15, 30, 45, 60, 120, 180, 360, 720, 1440, 2880, 4320). New offers are manual-delivery; switch to automatic
delivery from Storage on the website. Returns `201 { data: Offer }`.

`PATCH /offers/{id}` — update. Send only what changes (`unit_price`, `manual_stock_qty`, `title`, `min_qty`,
`max_qty`, `delivery_time_minutes`, `service_type_id`). Game, category, product and delivery method cannot change.

`POST /offers/{id}/deactivate` · `POST /offers/{id}/relist` (relists for 30 days).

Errors: `422 { message, field }` with a human-readable reason (same text as the website, in the account language).

## Orders

`GET /orders?status=delivered&per_page=50` → paid orders, newest first:

```json
{ "id": "01m8…", "number": "X2K2611250002", "status": "paid", "offer_id": 77, "title": "Steam key — instant",
  "quantity": 1, "delivered_qty": 0, "unit_price": "12.50", "seller_net": "11.50", "currency": "USD",
  "paid_at": "2026-11-25T14:58:35+00:00" }
```

`GET /orders/{id}` → `{ data: Order }`. Delivering stays on the website (order page) so evidence and chat stay
together.

## Storage

`POST /storage/groups/{groupId}/items` with `{ "items": "KEY-1\nKEY-2\n…" }` (one item per line, same format as
the Storage page) → `201 { data: { batch_id, accepted, duplicates, flagged, invalid, available } }`. Items are
encrypted at rest, duplicates are refused, keys already held by another seller are frozen for review. Responses
never echo items back.
