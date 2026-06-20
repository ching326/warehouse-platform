# Multi-tenant onboarding runbook

This system supports multiple tenants by giving each one their own database
and (typically) their own deployment folder. Most tenant-specific behavior
is driven by `includes/channels.php` — seller prefixes, warehouses,
item-code format, currencies, etc. The schema and code are shared.

## Prerequisites

- MySQL/MariaDB user that can `CREATE DATABASE` (or a pre-created empty DB).
- PHP CLI access on the server.
- A copy of the codebase ready to point at the new tenant.

## 1. Create the tenant database and load the schema

From the codebase root:

```bash
php bin/install_tenant.php \
    --db-host=localhost \
    --db-name=acme_erp \
    --db-user=acme_erp \
    --db-pass='replace-me' \
    --create-db
```

What this does:

1. Creates the database `acme_erp` (drop `--create-db` if you've already
   created it manually).
2. Loads `database/cubeshk_sys_structure.sql` — the canonical empty schema.
3. Marks every file in `database/migrations/` as already applied
   (the schema dump already reflects their post-state, so re-running them
   would error or duplicate work).

If something blows up partway through, drop the database and re-run.

## 2. Point the tenant's code at their database

Edit `includes/config.php` (in the tenant's deployment folder):

```php
define('DB_SERVER',          'localhost');
define('DB_SERVER_USERNAME', 'acme_erp');
define('DB_SERVER_PASSWORD', 'replace-me');
define('DB_DATABASE',        'acme_erp');
```

If two tenants share one server but each gets their own folder/vhost, this
is the only file that differs in DB credentials between deployments.

> Security note: `includes/config.php` contains credentials. Don't commit
> tenant-specific copies to a shared git repo.

## 3. Configure tenant-specific business rules

Edit `includes/channels.php`. The key knobs:

```php
$CHANNEL_REGISTRY = [
    'amazon' => [
        'label'          => 'Amazon',
        'prefixes'       => ['JP', 'US', /* ... */],   // tenant's amazon-region seller-code prefixes
        'type'           => 'amazon',
        'default_seller' => null,
    ],
    'rakuten' => [
        'label'          => 'Rakuten',
        'prefixes'       => ['RA'],                    // tenant uses 'RA' as their rakuten prefix
        'type'           => 'non_amazon_marketplace',
        'default_seller' => 'RA-MAIN',                  // their primary rakuten seller account
    ],
    // add yahoo, mercari, etc. — or remove channels they don't use
];

$WAREHOUSES        = ['JP', 'CN'];   // their warehouse codes; order matters (1st = primary)
$DEFAULT_WAREHOUSE = 'JP';

$SUPPORTED_CURRENCIES = ['USD', 'JPY', 'CNY'];   // currencies they trade in
$DEFAULT_CURRENCY     = 'JPY';                    // default for new sales

// Item code: 1-32 chars of alnum + underscore by default
$ITEM_CODE_PATTERN_PHP   = '/^[A-Z0-9_]{1,32}$/';
$ITEM_CODE_PATTERN_JS    = '^[A-Z0-9_]{1,32}$';
$ITEM_CODE_PARTS_LENGTHS = [];   // [] = no auto-segmentation
$ITEM_CODE_MAX_LENGTH    = 32;

// Shelf code: same defaults
$SHELF_CODE_PATTERN_PHP   = '/^[A-Z0-9_]{1,32}$/';
$SHELF_CODE_PATTERN_JS    = '^[A-Z0-9_]{1,32}$';
$SHELF_CODE_PARTS_LENGTHS = [];
$SHELF_CODE_MAX_LENGTH    = 32;
```

To enforce the legacy `XXXX_XX` 7-character item-code format for a tenant:

```php
$ITEM_CODE_PATTERN_PHP   = '/^[A-Z0-9]{4}_[A-Z0-9]{2}$/';
$ITEM_CODE_PATTERN_JS    = '^[A-Z0-9]{4}_[A-Z0-9]{2}$';
$ITEM_CODE_PARTS_LENGTHS = [4, 2];  // auto-insert '_' after 4 chars
$ITEM_CODE_MAX_LENGTH    = 7;
```

The shelf-code config has the same shape.

## 4. Verify

1. Open the system in a browser and log in.
2. Add a test item — confirm the item-code field accepts your configured format.
3. Add a test seller — confirm the warehouse dropdown shows your warehouses.
4. Open the inventory dashboard — confirm the qty/location columns show
   your warehouses, in the order you configured.

## 5. Schema changes later

When new schema migrations are added to `database/migrations/`, run:

```bash
php bin/migrate.php --status   # see what's pending
php bin/migrate.php --dry-run  # preview
php bin/migrate.php            # apply
```

Run this against each tenant DB by editing `includes/config.php` to point
at that tenant before running `migrate.php`.

## Operational notes

- **Backups**: each tenant's data lives in their own DB; back them up
  separately. The `mysqldump` command can target a single DB.
- **2FA shared secret**: `AUTH_2FA_SHARED_SECRET` in `includes/config.php`
  is shared across all users of one deployment. For multi-tenant security
  you should regenerate it per tenant.
- **File upload paths**: `FEED_UPLOAD_PATH`, `FILE_UPLOAD_PATH`, and
  `DB_BACKUP_PATH` in `config.php` default to shared `/tmp/...` paths. If
  multiple tenants share one server, give each tenant their own paths to
  prevent file collisions.
- **Cron jobs**: cron scripts read `includes/config.php` from their working
  directory at run time. Run cron in the tenant's folder, not from a
  globally shared location.

## Known limitations

- **2-warehouse assumption in some reports**: the restock and SKU pages
  use SQL aliases `inv_jp` / `inv_cn` (legacy names) that map positionally
  to the 1st and 2nd configured warehouses. Tenants with exactly 2
  warehouses see correct data; tenants with 1 or 3+ warehouses see a
  truncated view in those reports. The inventory dashboard at
  `inventory/index.php` is fully N-warehouse-aware.
- **Hardcoded `Asia/Tokyo` timezone**: ~13 files set timezone explicitly.
  Tenants outside Japan will see wrong "today" boundaries on date-filtered
  reports. Fix is a single-line change but hasn't been applied yet — see
  `docs/multi_tenant_onboarding.md`'s P2 follow-ups.
- **UI labels still say "JP" / "CN" / "L-JP" / "L-CN"** in some reports
  (restock, SKU). Header labels are tied to legacy column aliases. Data
  is correct; labels are misleading for non-JP/CN tenants.
