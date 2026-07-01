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

## Done

| Area / Page | Completed date | Notes |
| --- | --- | --- |
