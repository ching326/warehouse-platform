# Task: Fix Amazon SP-API order list fetched without RDT (PII / shipping address)

## Context

Laravel 13 + Livewire 4 warehouse app. The Amazon SP-API order import fetches Amazon orders, maps the
shipping address into WMS recipient fields, and imports them. Shipping address and buyer contact data
are restricted PII in SP-API and require a Restricted Data Token (RDT), not just the normal Login with
Amazon (LWA) access token.

There is a bug: the order LIST (which carries the shipping address the mapper needs) is fetched with
the plain LWA access token, while the RDT is created afterward and only used for the order ITEMS call.
With real Amazon responses this means the shipping address comes back redacted/empty, and the mapper
then fails every actionable order with a "PII missing" error. Current tests pass only because the HTTP
fake returns addresses regardless of which token is sent; the test never asserts the RDT is used on
the order list.

Use plain ASCII punctuation only in code and any text you add. No em-dashes, smart quotes, or arrows.

## Affected file

`app/Services/Amazon/AmazonSpapiOrdersClient.php`

Current flow in `fetch()`:

```php
$accessToken = $this->tokenService->exchangeRefreshToken($connection)->accessToken;
$orders = $this->fetchOrders($connection, $accessToken, $windowType, $from, $to); // plain LWA token
$actionableOrders = array_values(array_filter($orders['orders'], ...));

if ($actionableOrders === []) {
    return ['orders' => $orders['orders'], 'items' => [], 'capped' => $orders['capped']];
}

$rdt = $this->restrictedToken($connection, $accessToken, $actionableOrders); // created too late
$items = [];
foreach ($actionableOrders as $order) {
    $items[$amazonOrderId] = $this->fetchItems($connection, $rdt, $amazonOrderId); // RDT used here, not needed here
}
```

`fetchOrders()` calls `GET {endpoint}/orders/v0/orders` with the token passed in. `getWithRetry()`
sends the token in the `x-amz-access-token` header. The mapper (`AmazonOrderMapper`) reads
`$order['ShippingAddress']` from each order-list entry and throws `pii_missing` when `AddressLine1`
is empty on an actionable order.

## SP-API facts (so the fix is correct)

- `getOrders` (the list, `/orders/v0/orders`) returns `ShippingAddress` and `BuyerInfo` only when
  called with an RDT whose `restrictedResources` declares those `dataElements`. With a plain LWA token
  those PII fields are redacted/omitted.
- `getOrderItems` (`/orders/v0/orders/{orderId}/orderItems`) core fields (`SellerSKU`,
  `QuantityOrdered`, `Title`, `ItemPrice`, `OrderItemId`) are NOT restricted and return with a plain
  LWA token. Only `BuyerInfo` on order items is restricted, and this WMS does not read it.
- An RDT is short-lived (about 1 hour) and is scoped to the `restrictedResources` it was created for.
  A single RDT created for the `/orders/v0/orders` path covers all paginated pages of the list call.
- The RDT must be sent in the same `x-amz-access-token` header in place of the LWA token for the
  restricted call.

## Required fix

Make the order LIST use the RDT, and let order ITEMS use the plain LWA token.

Steps in `AmazonSpapiOrdersClient::fetch()`:

1. Exchange the refresh token for the LWA access token as today.
2. Create the RDT BEFORE fetching the order list, with a restricted resource for the search path:

   ```php
   $resources = [[
       'method' => 'GET',
       'path' => '/orders/v0/orders',
       'dataElements' => ['buyerInfo', 'shippingAddress'],
   ]];
   $rdt = $this->rdtService->create($connection, $accessToken, $resources);
   ```

   Note: for the search operation, the restricted resource path is just `/orders/v0/orders` with no
   query string and no order id.

3. Fetch the order list using the RDT, not the plain access token:

   ```php
   $orders = $this->fetchOrders($connection, $rdt, $windowType, $from, $to);
   ```

4. Fetch order items with the plain LWA access token (they are not restricted for the fields used):

   ```php
   $items[$amazonOrderId] = $this->fetchItems($connection, $accessToken, $amazonOrderId);
   ```

5. Remove the old per-order-items RDT resource building. The previous `restrictedToken()` helper that
   added `/orders/v0/orders/{id}/orderItems` resources is no longer needed. If you keep a helper, it
   should only build the single `/orders/v0/orders` resource.

6. If RDT creation fails (missing role/permission), the existing `AmazonRestrictedDataTokenService`
   should throw, and `fetch()` should surface it as an `AmazonSpapiApiException` so the Livewire
   preview shows the PII-missing message and imports nothing. Keep that behavior.

### Alternative design (only if the list-with-RDT approach does not fit the environment)

Fetch order IDs first with the plain token, then call `getOrder` detail (`/orders/v0/orders/{orderId}`)
per order using an RDT scoped to that path to obtain the address. This is more calls and more rate-limit
pressure, so prefer the list-with-RDT approach above. Do not implement both.

## Tests to update

File: `tests/Feature/AmazonSpapiOrderImportTest.php` (and any orders-client test).

The HTTP fake currently returns addresses no matter what token is sent, so it hides the bug. Update
the tests so they prove the RDT is used on the order list:

1. Assert the RDT endpoint (`createRestrictedDataToken`) is called before the order-list call.
2. Assert the `GET /orders/v0/orders` request carries the RDT value in `x-amz-access-token`, not the
   plain LWA access token. Use distinct fake values for the LWA token and the RDT so they can be told
   apart (for example access token `LWA-ACCESS` vs RDT `RDT-TOKEN`), then assert via
   `Http::assertSent(...)` that the orders request header equals the RDT value.
3. Assert the `GET /orders/v0/orders/{id}/orderItems` request carries the plain LWA access token.
4. Keep an existing end-to-end preview/import test green (address still maps, orders still import).

Use `Http::fake()` and `Http::assertSent()`. Do not call real Amazon.

## How to run tests

```bash
php artisan test tests/Feature/AmazonSpapiOrderImportTest.php
```

If `php` is not on PATH (this project uses Laragon):

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Feature/AmazonSpapiOrderImportTest.php
```

Then run the full suite to confirm nothing else broke:

```bash
php artisan test
```

## Constraints

- Send the RDT in `x-amz-access-token` for the order-list (restricted) call.
- Order items use the plain LWA access token; do not request an RDT for them.
- Do not store or log the RDT, the refresh token, or the access token.
- Do not change the mapper's behavior of failing/blocking when an actionable order still has no
  address after the RDT call (that path stays as the genuine PII-denied signal).
- Keep all added text/code in plain ASCII punctuation.
