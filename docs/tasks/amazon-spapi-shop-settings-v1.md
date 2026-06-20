# Task: Amazon SP-API Shop Settings v1

## Goal

Build the first Amazon SP-API integration layer for the WMS.

This task is **not** order import yet. The goal is to let an internal user attach Amazon SP-API credentials to an existing Amazon shop, store them securely, and test whether the connection can obtain an SP-API access token.

After this task is done, the next task can safely build "Import Amazon Orders from API".

## Important SP-API Auth Decision

Use the modern SP-API auth model.

Amazon no longer requires AWS IAM or AWS Signature Version 4 for SP-API requests. Since October 2, 2023, SP-API requests can be authorized with the Login with Amazon (LWA) access token. For this v1 task, **do not collect or store AWS access key, AWS secret key, AWS region, or role ARN**.

Required credentials for v1:

- LWA client ID
- LWA client secret
- Refresh token
- Seller ID
- Marketplace ID
- SP-API region / endpoint

Do not build Amazon OAuth redirect flow in v1. The refresh token is pasted manually from the Amazon Solution Provider Portal / Seller Central authorization flow.

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
- AWS IAM / SigV4 credentials

## Data Model

Create a new table:

```text
amazon_spapi_connections
-------------------------
id
tenant_id
shop_id
seller_id
marketplace_id
region              -- na / eu / fe
endpoint            -- https://sellingpartnerapi-fe.amazon.com
lwa_client_id
lwa_client_secret   -- encrypted cast, text column
refresh_token       -- encrypted cast, text column
sync_enabled
status              -- not_tested / connected / failed
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
- `shops.marketplace` is the human marketplace label already used by the app, for example `JP`, `US`, `UK`.
- `amazon_spapi_connections.marketplace_id` is the Amazon API marketplace ID, for example `A1VC38T7YXB528` for Japan.
- Default `marketplace_id` from `shops.marketplace` when possible, but allow internal users to override it.
- Use Laravel encrypted casts for secret fields.
- Encrypted columns must be `text`, not `string(255)`, because encrypted refresh tokens and secrets can exceed 255 characters.
- Never log decrypted secrets.
- `last_error` should be short and sanitized. Do not store raw HTTP response bodies if they may contain secrets.

## Status Model

Keep status and sync flag separate:

- `status` reflects the last connection test result only:
  - `not_tested`
  - `connected`
  - `failed`
- `sync_enabled` controls whether scheduled/API sync is allowed.

Do not add `disabled` to the status enum. If `sync_enabled = false`, the UI can display a disabled sync badge, but the connection test status remains unchanged.

Example:

- `status = connected`, `sync_enabled = false`
  - Credentials tested successfully before.
  - Scheduled sync is currently disabled.
- `status = failed`, `sync_enabled = true`
  - Sync is enabled, but the last test failed and should be fixed before scheduled import runs.

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

Important: Laravel encrypted casts return decrypted plaintext when the model attribute is read. If Spatie activity log records changed attributes with `logFillable()` or `logAll()`, secret values can be serialized into `activity_log.properties`.

Required activity log rule:

- Do not use `logAll()` for this model.
- Prefer a safe whitelist, for example: `seller_id`, `marketplace_id`, `region`, `endpoint`, `sync_enabled`, `status`, `last_tested_at`, `last_test_successful_at`, `last_error`.
- If using `logFillable()`, explicitly exclude `lwa_client_secret` and `refresh_token`.
- Add a regression test proving activity log payloads do not contain the plaintext LWA client secret or refresh token.

Encrypted casts:

```php
protected function casts(): array
{
    return [
        'lwa_client_secret' => 'encrypted',
        'refresh_token' => 'encrypted',
        'sync_enabled' => 'boolean',
        'last_tested_at' => 'datetime',
        'last_test_successful_at' => 'datetime',
    ];
}
```

Use field names like `lwa_client_secret` and `refresh_token`. Do not add `_encrypted` suffix when using encrypted casts, because the model property returns decrypted plaintext.

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

eu:
  label: Europe
  endpoint: https://sellingpartnerapi-eu.amazon.com

fe:
  label: Far East
  endpoint: https://sellingpartnerapi-fe.amazon.com
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
Setup -> Shops -> Edit Shop -> Amazon SP-API panel
```

Option B:

```text
Setup -> Shops -> Edit Shop -> button: Amazon API Settings
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
- Marketplace label, read-only or copied from `shops.marketplace`
- Marketplace ID
- Seller ID
- LWA Client ID
- LWA Client Secret
- Refresh Token
- Sync enabled

For secret fields:

- Do not show the saved decrypted value
- Show placeholder like `Saved - leave blank to keep unchanged`
- If user enters a new value, replace the stored encrypted value
- If user leaves it blank, keep existing value
- Bind secret inputs to transient Livewire properties, for example `$lwaClientSecretInput` and `$refreshTokenInput`, not directly to the model attributes. These transient properties must start blank and should only be copied onto the model when non-blank. This prevents decrypted cast values from entering component state and prevents a blank submit from wiping saved secrets.

Buttons:

- Save Amazon Settings
- Test Connection
- Disable Sync / Enable Sync

Status display:

- Not tested
- Connected
- Failed
- Sync disabled, derived from `sync_enabled = false`
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
- endpoint comes from region preset, not free text, unless an advanced override is explicitly added later
- lwa_client_id required
- lwa_client_secret required when creating a new connection
- refresh_token required when creating a new connection
- sync_enabled boolean
- marketplace_id must be consistent with the selected region. Reject the save when the marketplace ID belongs to a different region (example: JP marketplace `A1VC38T7YXB528` under region `na` or `eu`). Resolve the expected region via the marketplace helper and surface `amazon_spapi.marketplace_region_mismatch`. This is a hard validation, not a warning, because Test Connection only validates LWA credentials and cannot catch a region/marketplace mismatch.

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

`Test Connection` tests the **saved** connection only.

User flow:

1. User enters or updates settings.
2. User clicks `Save Amazon Settings`.
3. User clicks `Test Connection`.

Do not test unsaved form input in v1. This keeps the implementation simple and avoids confusing cases where blank secret fields mean "keep existing" on save but are empty values in the current form.

UI rules:

- Disable `Test Connection` until the connection has been saved at least once.
- If the form has unsaved changes, either disable `Test Connection` or show a clear "Save before testing" validation message.
- `Test Connection` should load the connection from DB, decrypt the saved credentials, and test those saved values.

Implementation steps:

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

Important caveat: `connected` only means the LWA credentials and refresh token are valid. It does **not** prove the selected region, marketplace ID, and seller authorization are mutually consistent, and it does **not** guarantee that Orders API import will work. That deeper validation belongs to the Amazon order import task.

For LWA token failures, it is useful to store a short sanitized value like `invalid_grant: The request has an invalid grant parameter`. Do not store full raw HTTP response bodies or request payloads.

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
- Add tests proving saved secret values are not stored in activity log properties

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
amazon_spapi.field_marketplace_id
amazon_spapi.field_seller_id
amazon_spapi.field_lwa_client_id
amazon_spapi.field_lwa_client_secret
amazon_spapi.field_refresh_token
amazon_spapi.field_sync_enabled
amazon_spapi.btn_save
amazon_spapi.btn_test_connection
amazon_spapi.btn_disable_sync
amazon_spapi.btn_enable_sync
amazon_spapi.status_not_tested
amazon_spapi.status_connected
amazon_spapi.status_failed
amazon_spapi.sync_disabled
amazon_spapi.secret_saved_placeholder
amazon_spapi.connection_saved
amazon_spapi.connection_test_success
amazon_spapi.connection_test_failed
amazon_spapi.amazon_shop_only
amazon_spapi.marketplace_region_mismatch
amazon_spapi.save_before_testing
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
11. Region preset sets endpoint correctly
12. Marketplace ID defaults from shop marketplace when possible
13. Test connection success updates status and timestamps
14. Test connection failure stores sanitized error
15. `sync_enabled = false` does not delete credentials or overwrite connection test status
16. AWS access key, AWS secret key, AWS region, and role ARN fields are not present in the migration, model, UI, or validation
17. Activity log entries do not contain plaintext LWA client secret or refresh token
18. Test Connection is disabled or blocked until the connection is saved
19. Test Connection uses saved DB credentials, not unsaved form input
20. Secret inputs use transient blank form properties and never hydrate decrypted saved values into the rendered component state
21. Save is rejected when `marketplace_id` belongs to a different region than the selected region (marketplace_region_mismatch)

For token service tests:

- Use HTTP fake / mock
- Do not call real Amazon in tests

## Acceptance Criteria

- An internal user can open an Amazon shop and save SP-API credentials
- Secret fields are encrypted and never displayed after save
- AWS IAM / SigV4 credentials are not collected in v1
- User can test connection and see connected / failed status
- Connected status clearly says it validates LWA credentials only, not full order-import readiness
- Tenant users cannot access this setup
- Non-Amazon shops do not show Amazon SP-API settings
- `status` and `sync_enabled` cannot drift into contradictory disabled states
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
