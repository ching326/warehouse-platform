# i18n Glossary / 術語表

Canonical translations for shared UI terms. When translating any module lang
file, look the term up here first so wording stays consistent across the app.

Locales: source = `en`; targets = `ja`, `zh_TW`, `zh_CN`. `fallback_locale = en`,
so any missing key falls back to English automatically.

Rules:
- ASCII punctuation only in lang files and docs (no em-dash, smart quotes, encoded arrows). CJK characters are fine.
- One canonical term per concept. Do not introduce a synonym; update this table instead.
- `[done]` = already written into a lang file. `[pending]` = agreed wording, not yet in files.

## Status legend

| status | meaning |
|---|---|
| done | written into a lang file and verified at runtime |
| pending | wording agreed here, apply when that module is translated |
| open | not decided yet, needs user confirmation |

## Domain / module names (common.php)

| en | ja | zh_TW | zh_CN | state |
|---|---|---|---|---|
| Inventory | 在庫 | 庫存 | 库存 | done |
| Overview | 概要 | 總覽 | 总览 | done |
| Inventory Record (Movements) | 在庫履歴 | 庫存記錄 | 库存记录 | done |
| Stock Adjustment | 在庫調整 | 庫存調整 | 库存调整 | done |
| SKU | SKU | SKU | SKU | done |
| Inbound | 入庫 | 入庫 | 入库 | done |
| Return | 返品 | 退貨 | 退货 | done |
| Outbound | 出庫 | 出庫 | 出库 | done |
| Sales Order | 注文管理 | 訂單管理 | 订单管理 | done |
| Fulfillment | (not translated) | (not translated) | (not translated) | open |
| Issue | 問題案件 | 問題案件 | 问题案件 | done |
| Setup | 設定 | 設定 | 设置 | done |

## Setup entities (common.php)

| en | ja | zh_TW | zh_CN | state |
|---|---|---|---|---|
| Tenant | 荷主 | 客戶 | 客户 | done (confirm) |
| Warehouse | 倉庫 | 倉庫 | 仓库 | done |
| Shop | 店舗 | 店舖 | 店铺 | done |
| Shipping Method | 配送方法 | 配送方式 | 配送方式 | done |
| Location | ロケーション | 儲位 | 储位 | done |
| Packaging | 梱包資材 | 包裝材料 | 包装材料 | done |
| Other Setting | その他の設定 | 其他設定 | 其他设置 | done |

## Common UI labels (common.php)

| en | ja | zh_TW | zh_CN | state |
|---|---|---|---|---|
| Status | ステータス | 狀態 | 状态 | done |
| Actions | 操作 | 操作 | 操作 | done |
| View | 表示 | 檢視 | 查看 | done |
| No note | メモなし | 無備註 | 无备注 | done |
| Select tenant | 荷主を選択 | 選擇客戶 | 选择客户 | done |
| All ... (filters) | すべての... | 全部... | 全部... | done |

## Buttons

`Search` / `Clear` / `Cancel` live in common.php and are done. `Create` / `Save`
/ `Edit` / `Delete` live in per-module files (e.g. `skus.btn_create`); apply the
wording below when each module is translated.

| en | ja | zh_TW | zh_CN | state | lives in |
|---|---|---|---|---|---|
| Search | 検索 | 搜尋 | 搜索 | done | common.php |
| Clear | クリア | 清除 | 清除 | done | common.php |
| Cancel | キャンセル | 取消 | 取消 | done | common.php |
| Remove | 削除 | 移除 | 移除 | done | common.php |
| Create | 新規作成 | 新增 | 新增 | pending | module files |
| Save | 保存 | 儲存 | 保存 | pending | module files |
| Edit | 編集 | 編輯 | 编辑 | pending | module files |
| Delete | 削除 | 刪除 | 删除 | pending | module files |

Note: in ja, both Remove and Delete map to 削除; this is acceptable and matches
common Japanese UI usage. In zh they stay distinct (移除 vs 刪除/删除).

## Statuses

Master-data statuses (Active/Inactive/Draft/Archived) live in `common.statuses`
and are done. Order statuses (Pending/Shipped/Cancelled and others) live in
`sales_orders.php`; apply the wording below when that module is translated.

| en | ja | zh_TW | zh_CN | state | lives in |
|---|---|---|---|---|---|
| Active | 有効 | 啟用 | 启用 | done | common.statuses |
| Inactive | 無効 | 停用 | 停用 | done | common.statuses |
| Draft | 下書き | 草稿 | 草稿 | done | common.statuses |
| Archived | アーカイブ済み | 已封存 | 已归档 | done | common.statuses |
| Pending | 処理待ち | 待處理 | 待处理 | pending | sales_orders.php |
| Shipped | 出荷済み | 已出貨 | 已发货 | pending | sales_orders.php |
| Cancelled | キャンセル済み | 已取消 | 已取消 | pending | sales_orders.php |

Note: the Outbound module name uses 出庫 (stock leaving the warehouse), while the
order status Shipped uses 出荷済み (dispatched to the customer). These are
intentionally different concepts in Japanese logistics; confirm when translating
sales_orders.php.

## Open items (need confirmation)

- Tenant: using 荷主 (ja) / 客戶 (zh). Alternative: テナント / 租戶. Confirm.
- Sales Order: using 訂單管理 (zh) to mirror 注文管理 (ja). Alternative: 銷售訂單 (closer to the literal English). Confirm.
- Fulfillment: not translated yet, falls back to English. Decide later.
- Shipped (order status): 出荷済み vs 出庫済み. Confirm when doing sales_orders.php.

## Not yet translated (future module rounds)

- `common.movement_types` (Receive / Reserve / Ship / Hold / ...) -- inventory module round.
- `common.sku_types` (Single / Virtual bundle / Physical bundle) -- SKU module round.
- `common.barcode_types` (JAN / EAN / UPC) -- mostly proper nouns, likely kept as-is.
