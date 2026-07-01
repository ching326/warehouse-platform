# Translation Backlog

Tracks UI areas where English lang keys exist but the CJK locales
(`ja`, `zh_TW`, `zh_CN`) still need real translations.

Workflow: while a page/feature is changing, add keys to `lang/en` only;
`fallback_locale = en` keeps the UI readable.
When the page is stable, do one translation pass for all three CJK locales and move the
row to Done.

## Pending

| Area / Page | English keys added | Missing locales | Notes |
| --- | --- | --- | --- |
| Reship sales order | `outbound.reship_*` (button, modal, reasons, qty, note, validation), `sales_orders.reship_*`, `shipment_original`, `shipment_reship`, `shipments_heading` | ja, zh_TW, zh_CN | CJK files currently hold English placeholders. Review wording after the reship UI settles. |
| Reship status badges | `sales_orders.reship_in_progress`, `sales_orders.reshipped` | ja, zh_TW, zh_CN | Added English only for Sales Order index/detail derived reship badges. |
| SKU label print | `skus.btn_print_label`, `skus.label_*` (print page, content, layout, skip cells, session) | ja, zh_TW, zh_CN | Label print feature still WIP. CJK currently English placeholders. Translate once the UI is final. |
| SKU label selected action | `skus.select_skus_to_print` | ja, zh_TW, zh_CN | Added English only for the selected-SKU print action. |
| SKU bulk status toggle | `skus.select_same_status_to_toggle` | ja, zh_TW, zh_CN | Added English only. Translate with the next SKU page pass. |
| Stock adjustment import | `stock_adjustment_import.*` | ja, zh_TW, zh_CN | Added English only for the bulk stock adjustment import flow. |
| Stock Count | `stock_counts.*`, `common.nav_stock_count` | ja, zh_TW, zh_CN | Added English only for Stock Count pages and import flow. |
| Fulfillment Label10 address labels | `fulfillment.address_label_*`, `fulfillment.batch_export_label10` | ja, zh_TW, zh_CN | Added English only for Label10 address label export and skip-cell modal. |
| Shop ship label sender fields | `shop.field_ship_label_address`, `shop.field_ship_label_phone`, `shop.field_ship_label_postcode` | ja, zh_TW, zh_CN | Added English only for shop-specific sender details on address labels and courier CSV. |

## Done

| Area / Page | Completed date | Notes |
| --- | --- | --- |
| Billing (courier cost, fee rates, run) | 2026-07-02 | `billing.php` created for ja/zh_TW/zh_CN; `outbound.courier_cost_*`/`field_courier_cost*`/`btn_edit_courier_cost` and `common.nav_fee_rates`/`nav_billing` translated. Terms recorded in the glossary. |
