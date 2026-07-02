# Translation Backlog

Tracks UI areas where English lang keys exist but the CJK locales
(`ja`, `zh_TW`, `zh_CN`) still need real translations.

Workflow: while a page/feature is changing, add keys to `lang/en` only;
`fallback_locale = en` keeps the UI readable.
When the page is stable, do one translation pass for all three CJK locales and move the
row to Done.

## Pending

| Area / Page | Added date | Notes |
| --- | --- | --- |
| Fulfillment print history and requeue print | 2026-07-02 | Added English-only `fulfillment.print_history_*` and `fulfillment.requeue_print*` keys. |

`return_orders.php` is the one known module file with no CJK translation yet
(not urgent -- add a row here when that module stabilizes).

## Done

| Area / Page | Completed date | Notes |
| --- | --- | --- |
| Billing (courier cost, fee rates, run) | 2026-07-02 | `billing.php` created for ja/zh_TW/zh_CN; `outbound.courier_cost_*`/`field_courier_cost*`/`btn_edit_courier_cost` and `common.nav_fee_rates`/`nav_billing` translated. Terms recorded in the glossary. |
| Stock adjustment import | 2026-07-02 | `stock_adjustment_import.php` created for ja/zh_TW/zh_CN (bulk stock adjustment import flow). |
| Stock Count | 2026-07-02 | `stock_counts.php` created for ja/zh_TW/zh_CN; `common.nav_stock_count` translated. Term "Stock Count" = 棚卸 (ja) / 盤點 (zh_TW) / 盘点 (zh_CN), recorded in the glossary. |
| SKU misc gaps (available column, stock item name/sku columns, weight short column, platform ID hints, image validation errors) | 2026-07-02 | Filled straggler `skus.php` keys that were added without a backlog row. |
| Reship sales order | 2026-07-02 | Translated `outbound.reship_*`, `shipment_original`, `shipment_reship`, `shipments_heading`, `reason_re_ship`, and `sales_orders.reship_requires_shipped_filter`/`reship_select_one` for ja/zh_TW/zh_CN. Term "Reship" = 再出荷 (ja) / 重發 (zh_TW) / 重发 (zh_CN); corrected 2026-07-02 from an earlier 補寄/补寄 draft per user review. Recorded in the glossary. |
| Reship status badges | 2026-07-02 | Translated `sales_orders.reship_in_progress`/`reshipped` for ja/zh_TW/zh_CN. |
| SKU label print (+ selected action, bulk status toggle) | 2026-07-02 | Rewrote the `skus.php` label-print block for ja/zh_TW/zh_CN -- the key set had drifted from `en` (old `label_content`/`label_include_name`/`label_skip_cells` etc. replaced by `label_type`/`label_skip_used_cells`/`label_skip_modal_*` etc.). Also translated `select_skus_to_print` and `select_same_status_to_toggle`. |
| Fulfillment Label10 address labels | 2026-07-02 | Translated `fulfillment.batch_export_label10` and the `address_label_*` block for ja/zh_TW/zh_CN. |
| Shop ship label sender fields | 2026-07-02 | Translated `shop.field_ship_label_address`/`_phone`/`_postcode` for ja/zh_TW/zh_CN. |
| Shipping method translated names | 2026-07-02 | Translated `shipping.field_name_ja`/`_zh_tw`/`_zh_cn` for ja/zh_TW/zh_CN. |
| Outbound courier label export history | 2026-07-02 | Translated `outbound.section_courier_label_exports*`, `outbound.col_export_*`, `outbound.courier_label_*` for ja/zh_TW/zh_CN. |
| Outbound Orders (full page) | 2026-07-02 | Translated the remaining ~110 keys of `outbound.php` for ja/zh_TW/zh_CN (page/section/field labels, statuses, hold/cancel flows, etc.). "Outbound Orders" (list) = 出庫一覧/出貨清單/出库清单; "Outbound Order" (single) = 出庫オーダー/出庫訂單/出库订单. Also filled the FBA warehouse fields (`field_fba_warehouse`, `select_fba_warehouse`, `fba_warehouse_hint`, `field_status`), which were structurally missing in all three locales. |
| Sales Order paste-import | 2026-07-02 | Translated `sales_orders.paste_import_*`, `import_paste_grid`, `field_line_note`, `field_product_name` for ja/zh_TW/zh_CN. "WeChat Docs" rendered as 騰訊文件/腾讯文档 (Tencent Docs). |
| Fulfillment misc | 2026-07-02 | Translated `fulfillment.btn_hide`/`filter_order_date` for ja/zh_TW/zh_CN. |
