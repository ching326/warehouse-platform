# Task: Shipping Methods + Rates v1

## Goal

Create a proper shipping method setup module.

The system currently stores shipping method as a simple string on orders, e.g.:

- `yamato`
- `sagawa`
- `japan_post`
- `other`

That is enough for early courier export, but not enough for a real overseas warehouse / 3PL system.

We need shipping methods to support:

- choosing detailed services such as Yamato Nekopos / Yamato Takkyubin / Sagawa
- preventing wrong courier CSV export
- exporting marketplace shipment notification files for Amazon / Rakuten / Shopify
- calculating shipping fees charged to tenants
- later reconciling actual courier invoice charges

This task defines the structure and first implementation stage.

---

## Key Design Decision

Use two levels:

1. **Carrier**
2. **Shipping Method / Service**

Example:

```text
Carrier: Yamato
Shipping methods:
- Yamato Nekopos
- Yamato Takkyubin
- Yamato Compact

Carrier: Sagawa
Shipping methods:
- Sagawa Takkyubin

Carrier: Japan Post
Shipping methods:
- Yu-Pack
- Yu-Packet
```

Reason:

- Courier CSV export works by carrier family.
- Tenant billing works by specific service.
- Marketplace shipment notification often needs carrier code/name.
- A single `shipping_method = yamato` is too broad for charging.

---

## Scope v1

Build:

1. Carrier table
2. Shipping method table
3. Shipping method CRUD page
4. Marketplace carrier-code mapping table
5. Basic flat fee support
6. Sales Orders index/detail should use shipping method records instead of hardcoded options

Do not build full Yamato/Sagawa invoice import in v1.

Do not build full zone/size matrix UI in v1 unless it is simple. Define the tables so it can be added
cleanly in v2.

---

## Data Model

### `carriers`

```php
Schema::create('carriers', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique(); // yamato / sagawa / japan_post / other
    $table->string('name');           // Yamato / Sagawa / Japan Post
    $table->string('country_code', 2)->nullable(); // JP for now
    $table->string('status')->default('active');   // active / inactive
    $table->timestamps();
});
```

Notes:

- `code` is the internal stable key.
- Use `yamato`, `sagawa`, `japan_post`, `other` to match current system values.

### `shipping_methods`

```php
Schema::create('shipping_methods', function (Blueprint $table) {
    $table->id();
    $table->foreignId('carrier_id')->constrained()->restrictOnDelete();
    $table->string('code')->unique(); // yamato_nekopos / yamato_takkyubin
    $table->string('name');           // Yamato Nekopos
    $table->string('service_type')->nullable(); // mail / parcel / compact / other
    $table->boolean('is_trackable')->default(true);
    $table->boolean('requires_size')->default(false);
    $table->boolean('requires_zone')->default(false);
    $table->boolean('supports_courier_csv')->default(true);
    $table->string('status')->default('active'); // active / inactive
    $table->text('note')->nullable();
    $table->timestamps();

    $table->index(['carrier_id', 'status']);
});
```

Recommended initial records:

```text
yamato_nekopos      Yamato Nekopos      carrier yamato, service_type mail, requires_size false, requires_zone false
yamato_takkyubin    Yamato Takkyubin    carrier yamato, service_type parcel, requires_size true, requires_zone true
yamato_compact      Yamato Compact      carrier yamato, service_type compact, requires_size false/true, requires_zone true
sagawa_takkyubin    Sagawa Takkyubin    carrier sagawa, service_type parcel, requires_size true, requires_zone true
japan_post_yupack   Japan Post Yu-Pack  carrier japan_post, service_type parcel, requires_size true, requires_zone true
other               Other               carrier other
```

### `sales_orders`

Add:

```php
$table->foreignId('shipping_method_id')->nullable()->after('shipping_method')->constrained('shipping_methods')->nullOnDelete();
```

Migration strategy:

- Keep old `sales_orders.shipping_method` string for compatibility during transition.
- Add `shipping_method_id`.
- Backfill:
  - `yamato` -> `yamato_takkyubin` or a generic `yamato_takkyubin` default
  - `sagawa` -> `sagawa_takkyubin`
  - `japan_post` -> `japan_post_yupack`
  - `other` -> `other`
- After UI and services use `shipping_method_id`, keep the old string as a denormalized legacy field
  for one or two phases.

Important:

- Do not drop `sales_orders.shipping_method` in v1.
- Courier export service can support both fields during transition.

### `shipping_method_marketplace_mappings`

Used for marketplace shipment notification export.

```php
Schema::create('shipping_method_marketplace_mappings', function (Blueprint $table) {
    $table->id();
    $table->foreignId('shipping_method_id')->constrained()->cascadeOnDelete();
    $table->string('platform');              // amazon / rakuten / shopify / tiktok / yahoo
    $table->string('marketplace')->nullable(); // JP / US / null
    $table->string('carrier_code')->nullable();
    $table->string('carrier_name')->nullable();
    $table->string('service_code')->nullable();
    $table->string('service_name')->nullable();
    $table->text('note')->nullable();
    $table->timestamps();

    $table->unique(
        ['shipping_method_id', 'platform', 'marketplace'],
        'shipping_method_marketplace_unique'
    );
});
```

Examples:

```text
Yamato Nekopos + Amazon JP
- platform: amazon
- marketplace: JP
- carrier_code: Yamato
- carrier_name: Yamato Transport
- service_code: null
- service_name: Nekopos

Yamato Takkyubin + Rakuten
- platform: rakuten
- marketplace: JP
- carrier_code: 1001 or required Rakuten carrier code
- carrier_name: Yamato
- service_name: Takkyubin
```

The exact marketplace code values can be refined when building marketplace shipment notification
exports. The table should exist now so the mapping has a home.

---

## Rate / Charging Model

Shipping method setup should support tenant billing.

There are two different concepts:

1. **Estimated charge**
   - what we expect to charge the tenant based on method/size/zone
   - useful before or during shipment

2. **Actual courier cost**
   - what Yamato/Sagawa actually bills us
   - imported later from courier invoice
   - used for reconciliation and margin checks

For v1, support flat fee rates only.

### `shipping_method_rates`

```php
Schema::create('shipping_method_rates', function (Blueprint $table) {
    $table->id();
    $table->foreignId('shipping_method_id')->constrained()->cascadeOnDelete();
    $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
    $table->string('rate_type')->default('flat'); // flat / zone_size
    $table->string('currency', 3)->default('JPY');
    $table->decimal('price', 10, 2)->nullable();
    $table->string('size_code')->nullable();      // 60 / 80 / 100 / compact etc, v2
    $table->string('origin_zone')->nullable();    // v2
    $table->string('destination_zone')->nullable(); // v2
    $table->date('effective_from')->nullable();
    $table->date('effective_to')->nullable();
    $table->string('status')->default('active'); // active / inactive
    $table->text('note')->nullable();
    $table->timestamps();

    $table->index(['shipping_method_id', 'tenant_id', 'status']);
    $table->index(['rate_type', 'size_code', 'destination_zone']);
});
```

Rules:

- `tenant_id = null` means default rate for all tenants.
- Tenant-specific rate overrides default rate.
- For v1, use `rate_type = flat`.
- For Yamato Nekopos:
  - `price = 198`
  - `currency = JPY`
  - `rate_type = flat`
- For Yamato Takkyubin:
  - create method record now
  - do not require full price matrix in v1
  - either leave rate empty or add only known default placeholder rates

---

## Takkyubin Charging Strategy

Yamato Takkyubin depends on:

- parcel size
- destination area / prefecture / zone
- contract rate
- possible surcharges

Recommended approach: **use both rate table and invoice import**.

### Rate table

Use for:

- estimated tenant charge
- quote before shipment
- generating billing preview
- detecting obvious pricing mistakes

### Courier invoice import

Use later for:

- actual courier cost
- monthly reconciliation
- checking margin between tenant charge and courier cost
- correcting exceptions

Future tables:

```text
courier_invoice_imports
- id
- carrier_id
- invoice_month
- file_name
- imported_by_user_id
- imported_at

courier_invoice_lines
- id
- courier_invoice_import_id
- tracking_no
- platform_order_id nullable
- sales_order_id nullable
- shipping_method_id nullable
- billed_amount
- tax
- surcharge
- raw_data json
```

Matching priority:

1. tracking no.
2. platform order id if invoice includes it
3. manual matching fallback

Do not build invoice import in v1, but keep the design compatible.

---

## CRUD Pages

Create setup pages:

```text
/setup/shipping-methods
/setup/shipping-methods/create
/setup/shipping-methods/{method}/edit
```

Recommended UI fields:

- Carrier
- Code
- Name
- Service type
- Trackable
- Requires size
- Requires zone
- Supports courier CSV
- Status
- Note
- Default flat fee
- Tenant-specific rates section (optional v1, can be read-only/empty)
- Marketplace mappings section

For v1, keep UI simple:

- Main method form
- Flat fee form
- Marketplace mappings table/form

Do not build a complex zone/size matrix editor yet.

---

## Sales Order Integration

Update Sales Orders index/detail/create/import where needed.

### Index

- Shipping method dropdown should use active `shipping_methods`.
- Display method name, e.g. `Yamato Nekopos`.
- Store `shipping_method_id`.
- During transition, also update old `shipping_method` string to the carrier code:
  - Yamato Nekopos -> `yamato`
  - Yamato Takkyubin -> `yamato`
  - Sagawa Takkyubin -> `sagawa`
  - Japan Post -> `japan_post`

Reason:

- Existing courier export service still checks the simple carrier string.
- This keeps old flows working while new data model is introduced.

### Amazon order report import

Current import maps Amazon `ship-service-level` roughly to `shipping_method`.

For v1:

- Keep old behavior if mapping is uncertain.
- If `ship-service-level` clearly maps to a method, set `shipping_method_id`.
- Examples:
  - Nekopos-like service -> Yamato Nekopos
  - Yamato/Takkyubin-like service -> Yamato Takkyubin
  - Sagawa-like service -> Sagawa Takkyubin
  - Standard / unknown -> null

Do not overfit this mapping until real Amazon report values are confirmed.

### Courier export

Courier export should eventually use `shipping_method.carrier.code`.

During transition:

- Accept orders when either:
  - `shipping_method_id` points to a method whose carrier code matches selected carrier, or
  - old `shipping_method` string matches selected carrier

After migration is stable:

- use `shipping_method_id` only

### Marketplace shipment notification

When exporting Amazon / Rakuten / Shopify shipment notification:

- Look up `shipping_method_marketplace_mappings`
- Use the mapped `carrier_code`, `carrier_name`, `service_code`, `service_name`

If no mapping exists:

- block export or show warning
- do not guess carrier code silently

---

## Billing Integration

Do not build tenant billing in v1, but prepare for it.

Future flow:

1. Order is shipped.
2. System determines shipping method.
3. System calculates estimated shipping charge:
   - tenant-specific active rate first
   - fallback to default active rate
4. Create billing line:
   - tenant id
   - sales order id
   - shipping method id
   - quantity / package count
   - unit price
   - amount
5. Later courier invoice import updates actual courier cost.

Important:

- Tenant charge and courier actual cost are not always the same.
- Store both concepts separately in future billing module.

---

## Seed Data

Add seed data for:

### Carriers

```text
yamato       Yamato
sagawa       Sagawa
japan_post   Japan Post
other        Other
```

### Shipping methods

```text
yamato_nekopos
yamato_takkyubin
yamato_compact
sagawa_takkyubin
japan_post_yupack
other
```

### Rates

```text
yamato_nekopos default flat JPY 198
```

Other method rates can be blank initially.

---

## Lang Keys

Add `lang/en/shipping.php`:

```php
return [
    'index_page_title' => 'Shipping Methods',
    'index_page_subtitle' => 'Manage carriers, services, marketplace mappings, and shipping rates.',
    'create_page_title' => 'Create Shipping Method',
    'edit_page_title' => 'Edit Shipping Method',
    'field_carrier' => 'Carrier',
    'field_code' => 'Code',
    'field_name' => 'Name',
    'field_service_type' => 'Service type',
    'field_is_trackable' => 'Trackable',
    'field_requires_size' => 'Requires size',
    'field_requires_zone' => 'Requires zone',
    'field_supports_courier_csv' => 'Supports courier CSV',
    'field_status' => 'Status',
    'field_note' => 'Note',
    'field_flat_fee' => 'Default flat fee',
    'field_currency' => 'Currency',
    'section_marketplace_mappings' => 'Marketplace mappings',
    'section_rates' => 'Rates',
    'status_active' => 'Active',
    'status_inactive' => 'Inactive',
];
```

Other locales can inherit English for now.

---

## Tests

Add tests for:

1. `test_shipping_method_crud_creates_method_with_flat_fee`
   - Create carrier/method/rate.
   - Assert DB rows exist.

2. `test_shipping_method_code_is_unique`
   - Duplicate `shipping_methods.code` rejected.

3. `test_shipping_method_can_store_marketplace_mapping`
   - Add Amazon/Rakuten mapping.
   - Assert DB rows exist.

4. `test_sales_order_index_uses_active_shipping_methods`
   - Active method appears in dropdown.
   - Inactive method does not appear for new selection.

5. `test_sales_order_shipping_method_update_sets_method_id_and_legacy_carrier_string`
   - Select Yamato Nekopos.
   - Assert `shipping_method_id` set.
   - Assert legacy `shipping_method = yamato`.

6. `test_courier_export_accepts_method_by_carrier`
   - Order has `shipping_method_id = yamato_nekopos`.
   - Yamato export validation accepts it.
   - Sagawa export validation rejects it.

7. `test_amazon_report_import_can_leave_unknown_shipping_method_null`
   - Unknown/Standard service should not guess.

8. `test_rate_lookup_prefers_tenant_specific_rate`
   - Default rate exists.
   - Tenant override exists.
   - Lookup returns tenant override.

9. `test_rate_lookup_falls_back_to_default_rate`
   - No tenant override.
   - Lookup returns default rate.

Run full suite:

```bash
php artisan test
```

If `php` is not global:

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test
```

---

## Constraints

- Class-based Livewire only.
- No Volt.
- No TypeScript.
- Keep tenant scoping server-side.
- Do not remove old `sales_orders.shipping_method` in v1.
- Do not break existing courier export.
- Do not build full Yamato/Sagawa invoice import in v1.
- Do not build complex zone/size matrix UI in v1.
- Do not guess marketplace carrier codes silently.
- Keep UI operational and compact.

---

## Follow-up Tasks

1. Detailed Yamato/Sagawa zone + size rate matrix.
2. Tenant-specific shipping contract rates.
3. Courier invoice import and reconciliation.
4. Marketplace shipment notification export.
5. Auto-select shipping method based on shop/order/import rules.
6. Billing line generation after shipment.

