# Task: Amazon SP-API Shop Settings v1

## Goal

Build the first Amazon SP-API integration layer for the WMS.

This task is **not** order import yet. The goal is to let an internal user attach Amazon SP-API credentials to an existing Amazon shop, store them securely, and test whether the connection can obtain an SP-API access token.

After this task is done, the next task can safely build "Import Amazon Orders from API".

## Background

The WMS already has:

- `shops`
- `sales_orders`
- `sales_order_lines`
- manual / CSV Amazon order import logic
- shipping methods
- multi-tenant permission model

Amazon SP-API should connect at the **shop** level, not globally.

Example:

- Tenant ABC
  - Shop ABC Amazon JP
  - Shop ABC Amazon US
- Tenant XYZ
  - Shop XYZ Amazon JP

Each Amazon shop may have its own seller authorization / refresh token / marketplace.

## Scope

### In scope

- Create Amazon SP-API settings storage for Amazon shops
- Add settings UI to shop edit page or a linked page from shop edit
- Store sensitive credentials encrypted
- Add a Test Connection button
- Save connection test result
- Internal users only
- Tests

### Out of scope

- Import orders from API
- Scheduled sync
- Webhook / notifications
- Restricted Data Token order PII fetching
- Amazon report API
- Seller-facing OAuth authorization flow
- Public app listing flow

## Product Decision

This is v1 for a private/internal integration.

Use manually pasted credentials from Amazon Solution Provider Portal:

- LWA client ID
- LWA client secret
- refresh token
- seller ID
- marketplace ID
- SP-API endpoint / region
- AWS IAM signing credentials if needed by the SP-API client

Do **not** build Amazon OAuth redirect flow in v1.

## Data Model

Create a new table:

```text
amazon_spapi_connections
─────────────────────────────
id
tenant_id
shop_id
seller_id
marketplace_id
region              -- na / eu / fe
endpoint             -- https://sellingpartnerapi-fe.amazon.com
aws_region           -- us-east-1 / eu-west-1 / us-west-2
lwa_client_id
lwa_client_secret_encrypted
refresh_token_encrypted
aws_access_key_id_encrypted
aws_secret_access_key_encrypted
role_arn_encrypted   -- nullable, if using assume-role flow
sync_enabled
status               -- not_tested / connected / failed / disabled
last_tested_at
last_test_successful_at
last_error
created_at
updated_at

unique: shop_id
index: tenant_id
index: status
```

Notes:

- `tenant_id` is denormalized from `shops.tenant_id` for simple scoping and audit.
- `shop_id` must be unique: one Amazon SP-API connection per shop.
- Only allow this for shops where `platform = amazon`.
- Use Laravel encrypted casts or explicit `Crypt::encryptString()` / `Crypt::decryptString()` for secret fields.
- Never log decrypted secrets.
- `last_error` should be short and sanitized. Do not store raw HTTP response bodies if they may contain secrets.

## Model

Create model:

```text
App\Models\AmazonSpapiConnection
```

Relationships:

```php
AmazonSpapiConnection belongsTo Tenant
AmazonSpapiConnection belongsTo Shop
Shop hasOne AmazonSpapiConnection
```

Add relationship to `Shop`:

```php
public function amazonSpapiConnection(): HasOne
```

Use activity log if consistent with existing models, but do not log secret field values.

## Region Presets

Add a small support class or enum-like helper:

```text
App\Support\AmazonSpapiRegion
```

Values:

```text
na:
  label: North America
  endpoint: https://sellingpartnerapi-na.amazon.com
  aws_region: us-east-1

eu:
  label: Europe
  endpoint: https://sellingpartnerapi-eu.amazon.com
  aws_region: eu-west-1

fe:
  label: Far East
  endpoint: https://sellingpartnerapi-fe.amazon.com
  aws_region: us-west-2
```

Marketplace helper can include common values:

```text
JP: A1VC38T7YXB528
US: ATVPDKIKX0DER
UK: A1F83G8C2ARO7P
DE: A1PA6795UKMFR9
FR: A13V1IB3VIYZZH
IT: APJ6JRA9NG5V4
ES: A1RKKUPIHCS9HS
CA: A2EUQ1WTGCTBG2
MX: A1AM78C64UM0Y8
AU: A39IBJ37TRP1C6
SG: A19VAU5U5O7RUS
```

Do not overbuild marketplace management in v1. A select with common values plus manual text input is enough.

## UI

Add Amazon API settings to either:

Option A, preferred:

```text
Setup → Shops → Edit Shop → Amazon SP-API panel
```

Option B:

```text
Setup → Shops → Edit Shop → button: Amazon API Settings
/setup/shops/{shop}/amazon-spapi
```

Use Option A unless the edit form becomes too crowded.

### Visibility

Only show the Amazon SP-API panel when:

```text
shop.platform === amazon
```

For non-Amazon shops, do not show the panel.

### Fields

Panel title:

```text
Amazon SP-API Connection
```

Fields:

- Region
- Marketplace
- Seller ID
- LWA Client ID
- LWA Client Secret
- Refresh Token
- AWS Access Key ID
- AWS Secret Access Key
- Role ARN, nullable
- Sync enabled

For secret fields:

- Do not show the saved decrypted value
- Show placeholder like `Saved - leave blank to keep unchanged`
- If user enters a new value, replace the stored encrypted value
- If user leaves it blank, keep existing value

Buttons:

- Save Amazon Settings
- Test Connection
- Disable Sync

Status display:

- Not tested
- Connected
- Failed
- Disabled
- Last tested at
- Last error

## Validation

Rules:

- Only internal users can view/update Amazon SP-API settings
- Shop must exist and be in allowed scope
- Shop must be `platform = amazon`
- seller_id required
- marketplace_id required
- region required and must be one of `na`, `eu`, `fe`
- endpoint and aws_region should come from region preset, not free text, unless an advanced override is explicitly added later
- lwa_client_id required
- lwa_client_secret required when creating a new connection
- refresh_token required when creating a new connection
- AWS credentials required when creating a new connection, unless implementation uses a different supported auth strategy
- sync_enabled boolean

Editing existing connection:

- Secret fields are optional
- Blank secret field means "keep existing"

## Service Layer

Create:

```text
App\Services\Amazon\AmazonSpapiTokenService
```

Responsibilities:

- Accept an `AmazonSpapiConnection`
- Exchange `refresh_token` for an LWA access token
- Return access token metadata
- Throw a clean domain exception on failure

Suggested result object:

```php
AmazonAccessTokenResult
  accessToken
  expiresIn
  tokenType
```

Do not store the short-lived access token in DB in v1 unless necessary.

### Test Connection

`Test Connection` should:

1. Decrypt required credentials
2. Call Amazon LWA token endpoint to exchange refresh token for access token
3. If token exchange succeeds:
   - status = connected
   - last_tested_at = now()
   - last_test_successful_at = now()
   - last_error = null
4. If token exchange fails:
   - status = failed
   - last_tested_at = now()
   - last_error = sanitized message

Do not call Orders API in v1 test connection unless the token exchange is already working and implementation is simple. The goal is to validate credentials first.

## Security

Important:

- Never display decrypted secrets after save
- Never log decrypted secrets
- Never include secrets in validation errors
- Never expose this page to tenant users
- Do not put secrets in query strings
- Do not add secrets to activity log
- Use encrypted DB storage
- Add tests proving saved secret values are not rendered in HTML

If using Laravel casts:

```php
protected function casts(): array
{
    return [
        'lwa_client_secret_encrypted' => 'encrypted',
        'refresh_token_encrypted' => 'encrypted',
        'aws_access_key_id_encrypted' => 'encrypted',
        'aws_secret_access_key_encrypted' => 'encrypted',
        'role_arn_encrypted' => 'encrypted',
        'sync_enabled' => 'boolean',
        'last_tested_at' => 'datetime',
        'last_test_successful_at' => 'datetime',
    ];
}
```

Column names can omit `_encrypted` if using encrypted casts, but be explicit and consistent.

## Routes

If using Option A:

- No new route required
- Extend existing shop edit component

If using Option B:

```text
GET /setup/shops/{shop}/amazon-spapi
name: setup.shops.amazon-spapi
Livewire component: ShopAmazonSpapiSettings
```

## UI Copy

Add language keys instead of hardcoded text.

Suggested keys:

```php
amazon_spapi.panel_title
amazon_spapi.panel_hint
amazon_spapi.field_region
amazon_spapi.field_marketplace
amazon_spapi.field_seller_id
amazon_spapi.field_lwa_client_id
amazon_spapi.field_lwa_client_secret
amazon_spapi.field_refresh_token
amazon_spapi.field_aws_access_key_id
amazon_spapi.field_aws_secret_access_key
amazon_spapi.field_role_arn
amazon_spapi.field_sync_enabled
amazon_spapi.btn_save
amazon_spapi.btn_test_connection
amazon_spapi.status_not_tested
amazon_spapi.status_connected
amazon_spapi.status_failed
amazon_spapi.status_disabled
amazon_spapi.secret_saved_placeholder
amazon_spapi.connection_saved
amazon_spapi.connection_test_success
amazon_spapi.connection_test_failed
amazon_spapi.amazon_shop_only
```

## Tests

Add tests for:

1. Internal user can see Amazon SP-API panel on Amazon shop edit page
2. Panel is hidden for non-Amazon shop
3. Tenant user cannot access or update Amazon settings
4. Can create connection for Amazon shop
5. Can update non-secret fields without replacing existing secrets
6. Can replace a secret by entering a new value
7. Secret values are encrypted in DB and not stored as plain text
8. Secret values are not rendered in HTML after save
9. Duplicate connection for same shop is updated, not duplicated
10. Cannot create connection for non-Amazon shop
11. Region preset sets endpoint and aws_region correctly
12. Test connection success updates status and timestamps
13. Test connection failure stores sanitized error
14. `sync_enabled = false` does not delete credentials

For token service tests:

- Use HTTP fake / mock
- Do not call real Amazon in tests

## Acceptance Criteria

- An internal user can open an Amazon shop and save SP-API credentials
- Secret fields are encrypted and never displayed after save
- User can test connection and see connected / failed status
- Tenant users cannot access this setup
- Non-Amazon shops do not show Amazon SP-API settings
- No order import is implemented in this task
- Full test suite passes

## Future Task: Amazon SP-API Order Import v1

After this task, create a separate task for:

- Pull recent Amazon orders via Orders API
- Map Amazon order fields to `sales_orders`
- Map Amazon lines to `sales_order_lines`
- Skip duplicates
- Missing SKU exception handling
- Cancel requested / cancelled status mapping
- Optional RDT handling for buyer name, address, phone
- Sync logs and retry handling

