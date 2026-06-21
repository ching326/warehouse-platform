# Task: Issues Order Search UI v1

## Goal

Improve the Issues screens so staff search/link orders by real operational order numbers, not database IDs.

Current problem:

- `/issues` has `Sales order` and `Outbound order` number inputs.
- These appear to filter by internal table IDs (`sales_orders.id`, `outbound_orders.id`).
- Staff do not know these IDs.
- Staff know:
  - Sales order platform/order number
  - Outbound order reference number
  - Tracking number
  - Recipient details

Change the UI and filtering to use real order identifiers.

---

## Scope

Update:

- `IssueIndex`
- `IssueCreate`
- issue index Blade view
- issue create Blade view
- translations
- tests

Do not change inventory logic.
Do not rename Issues again.

---

## Part A: `/issues` Index Filters

### 1. Replace internal ID filters

Remove or stop using these as user-facing filters:

- `sales_order_id` numeric input
- `outbound_order_id` numeric input

Replace with text filters:

```php
public string $salesOrderSearch = '';
public string $outboundOrderSearch = '';
```

Suggested query-string aliases:

```php
#[Url(as: 'sales_order', except: '')]
public string $salesOrderSearch = '';

#[Url(as: 'outbound_order', except: '')]
public string $outboundOrderSearch = '';
```

Keep backward compatibility if easy:

- If old `sales_order_id` appears in URL, it may still filter by ID internally.
- But the visible UI should not show ID-based search.

### 2. Sales order filter should search actual order fields

When `salesOrderSearch` is not empty, filter Issues by linked sales order:

Search fields:

- `sales_orders.platform_order_id`
- `sales_orders.tracking_no`
- `sales_orders.recipient_name`
- `sales_orders.recipient_phone`

Example:

```php
->when($this->salesOrderSearch !== '', function ($query) {
    $like = '%'.$this->salesOrderSearch.'%';

    $query->whereHas('salesOrder', fn ($q) => $q
        ->where('platform_order_id', 'like', $like)
        ->orWhere('tracking_no', 'like', $like)
        ->orWhere('recipient_name', 'like', $like)
        ->orWhere('recipient_phone', 'like', $like));
})
```

### 3. Outbound order filter should search actual outbound fields

When `outboundOrderSearch` is not empty, filter Issues by linked outbound order:

Search fields:

- `outbound_orders.ref`
- any outbound order number/reference field that exists in the current model

If the outbound order has no other order number field, use `ref`.

Do not expose `outbound_orders.id` as the main user-facing filter.

### 4. Layout change

On `/issues`:

- Put Sales order filter on a new row.
- Put Outbound order filter beside Sales order if there is enough width.
- The global Search box should align to the right.
- Make the global Search box wider than the other filters.

Suggested layout:

Row 1:

```text
Tenant | Issue type | Status |                         [Global search.............]
```

Row 2:

```text
Sales order search........ | Outbound order search........ | [Create issue]
```

If mobile/narrow screen:

- Stack naturally.
- No overlap.
- Inputs should remain readable.

### 5. Placeholder text

Use clear placeholders:

```text
Sales order no, tracking, recipient...
Outbound ref...
Issue no, order ID, SKU, stock, note...
```

---

## Part B: `/issues/create` Order Linking

### 1. Sales order must not be a full dropdown

Do not preload all sales orders.

Replace sales order dropdown with an async/search picker.

Search only after the user enters at least 2 or 3 characters.

Limit results:

```php
limit 20
```

Search fields:

- `platform_order_id`
- `tracking_no`
- `recipient_name`
- `recipient_phone`

Display result as:

```text
{platform_order_id}
{recipient_name} / {tracking_no}
```

When selected:

- Store selected `sales_order_id`
- Show selected order summary
- Load sales order lines as currently done

### 2. Outbound order should also become async/search picker

Replace outbound order dropdown with an async/search picker too.

Search only after user enters at least 2 or 3 characters.

Limit results:

```php
limit 20
```

Search fields:

- `outbound_orders.ref`
- linked sales order `platform_order_id` if relationship exists
- recipient/tracking if available through linked sales order or fulfillment group

Display result as:

```text
{outbound ref}
{warehouse / status / related sales order if available}
```

When selected:

- Store selected `outbound_order_id`
- Do not require sales order to also be selected

### 3. Unknown order support

Issues can be related to:

- sales order only
- outbound order only
- both
- neither / unknown order

Current validation has two separate requirements:

- related order is required
- at least one line is required

Both rules must be adjusted together.

New validation truth table:

| Linked order state | Required content |
|---|---|
| sales order selected | at least one selected sales order line or manual line |
| outbound order selected | at least one line; in outbound-only mode this means a manual line unless sales-order lines can be derived from the outbound order |
| sales order and outbound order selected | at least one selected sales order line or manual line |
| no sales order and no outbound order | note OR at least one manual line |

Rules:

- Allow creating an Issue with no sales order and no outbound order.
- If no order is linked, do **not** always require a line.
- If no order is linked, require either:
  - non-empty `note`, or
  - at least one valid manual line
- If an order is linked, keep the current line requirement.

Recommended validation:

- `validation_related_required` should no longer be used for the no-order path.
- Add/replace with a clearer message such as:

```php
'validation_unknown_issue_requires_note_or_line' => 'Add a note or a manual line for an issue without a linked order.',
```

This supports unknown parcels / customer claims where order cannot be identified yet.

### 4. Tenant scoping

All search picker queries must use allowed tenant scope.

Internal user:

- may search all allowed tenants
- if tenant filter is selected, restrict to that tenant

Tenant user:

- can only search own active tenant orders

Do not treat guest users as internal.

---

## Part C: Tests

Add/update tests:

1. Issue index sales order filter searches by `platform_order_id`, not internal ID.
2. Issue index sales order filter searches by tracking number.
3. Issue index sales order filter searches by recipient name or phone.
4. Issue index outbound order filter searches by outbound `ref`, not internal ID.
5. Global search input remains working.
6. Global search input is rendered wider / has expected CSS class.
7. Sales order filter appears on second filter row.
8. Issue create does not preload all sales orders.
9. Issue create sales order picker returns max 20 results.
10. Issue create sales order picker respects tenant scope.
11. Issue create outbound order picker returns max 20 results.
12. Issue create outbound order picker respects tenant scope.
13. Selecting a sales order loads sales order lines.
14. Selecting an outbound order stores `outbound_order_id`.
15. Issue can be created with outbound order only.
16. Issue can be created with unknown order if a note is provided.
17. Issue can be created with unknown order if a manual line is provided.
18. Issue without order, note, or manual line is rejected with the new unknown-issue validation message.
19. Linked sales/outbound order still requires at least one line.

Run:

```bash
php artisan test tests/Feature/IssueTest.php
php artisan test
```

---

## Acceptance Criteria

- Staff no longer need to know internal table IDs.
- `/issues` filters use real sales/outbound order identifiers.
- Sales order filter is moved to the next row.
- Global Search is aligned right and wider.
- `/issues/create` does not load massive sales/outbound dropdowns.
- Sales order and outbound order are both async/search pickers.
- Tenant scoping remains enforced.
