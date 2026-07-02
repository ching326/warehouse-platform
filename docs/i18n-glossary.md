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
| Stock Count | 棚卸 | 盤點 | 盘点 | done |
| SKU | SKU | SKU | SKU | done |
| Inbound | 入庫 | 進倉 | 入库 | done |
| Return | 返品 | 退貨 | 退货 | done |
| Outbound | 出庫 | 出倉 | 出库 | done |
| Sales Order | 注文管理 | 訂單管理 | 订单管理 | done |
| Fulfillment | 出荷管理 | 發貨管理 | 发货管理 | done |
| Issue | 問題案件 | 問題案件 | 问题案件 | done |
| Setup | 設定 | 設定 | 设置 | done |

## Setup entities (common.php)

| en | ja | zh_TW | zh_CN | state |
|---|---|---|---|---|
| Tenant | 荷主 | 客戶 | 客户 | done (confirm) |
| Warehouse | 倉庫 | 倉庫 | 仓库 | done |
| Shop | 店舗 | 店舖 | 店铺 | done |
| Shipping Method | 配送方法 | 配送方式 | 配送方式 | done |
| Location | ロケーション | 庫位 | 库位 | done |
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
- Shipped (order status): 出荷済み vs 出庫済み. Confirm when doing sales_orders.php.

Decided: Inbound / Outbound / Fulfillment are split by origin (platform sales order vs manual),
not by quantity; the names reflect the typical case. Fulfillment = ja 出荷管理 / zh_TW 發貨管理 /
zh_CN 发货管理 (ship to customer); Outbound = 出庫 / 出倉 / 出库 (stock leaving the warehouse, e.g.
B2B / transfers); Inbound = 入庫 / 進倉 / 入库. zh_TW uses 進倉 / 出倉; ja and zh_CN use 入庫 / 出庫.

## Inventory movement types (common.movement_types) -- done

| en | ja | zh_TW | zh_CN |
|---|---|---|---|
| Opening Balance | 期首在庫 | 期初庫存 | 期初库存 |
| Receive | 入庫 | 入庫 | 入库 |
| Reserve | 引当 | 分配 | 分配 |
| Release Reserve | 引当解除 | 解除分配 | 解除分配 |
| Ship | 出荷 | 出貨 | 出货 |
| Hold | 保留 | 暫扣 | 挂起 |
| Release Hold | 保留解除 | 解除暫扣 | 解挂 |
| Mark Damaged | 破損計上 | 標記破損 | 标记破损 |
| Adjust | 在庫調整 | 庫存調整 | 库存调整 |

## SKU types (common.sku_types) -- done

| en | ja | zh_TW | zh_CN |
|---|---|---|---|
| Single | 単品 | 單品 | 单品 |
| Virtual bundle | 仮想セット | 虛擬組合 | 虚拟组合 |
| Physical bundle | 実物セット | 實物組合 | 实物组合 |

## Barcode types (common.barcode_types) -- done

JAN / EAN / UPC are kept as-is (international standards) in all locales. Only Unknown is translated:
ja 不明 / zh_TW 未知 / zh_CN 未知.

## Reship terms (outbound.php, sales_orders.php) -- done

Corrected 2026-07-02 per user review: zh uses 重發/重发 (not 補寄/补寄) as the base term.
Verb/noun phrases built on it (create reship, reship warehouse/reason/qty/note, etc.) all
carry 重發/重发. "Original shipment" is 首次發貨/首次发货 (zh) / 初回出荷 (ja) -- i.e. "first
shipment", not "the original one". zh_TW keeps 建立 as the create-verb, zh_CN keeps 创建,
matching the rest of the codebase's existing convention.

| en | ja | zh_TW | zh_CN |
|---|---|---|---|
| Reship | 再出荷 | 重發 | 重发 |
| Create reship | 再出荷を作成 | 訂單重發 | 订单重发 |
| Original (first) shipment | 初回出荷 | 首次發貨 | 首次发货 |
| Add SKU (reship) | SKUを追加 | 追加SKU | 追加SKU |
| Additional items | 追加商品 | 追加商品 | 追加商品 |
| Reship reason: Missing | 紛失 | 遺失 | 遗失 |
| Reship reason: Defect | 不良品 | 瑕疵品 | 瑕疵品 |
| Reship reason: Wrong address | 住所間違い | 地址錯誤 | 地址错误 |
| Reship requested (badge) | 再出荷手配中 | 重發安排中 | 重发安排中 |
| Reshipped (badge) | 再出荷済み | 已重發 | 已重发 |
| Outbound Orders (list/index page, and export history list) | 出庫一覧 | 出貨清單 | 出库清单 |
| Outbound Order (single order, create/detail pages) | 出庫指示 | 出庫單 | 出库单 |

## Billing terms (billing.php) -- done

| en | ja | zh_TW | zh_CN |
|---|---|---|---|
| Fee Rate | 料金レート | 費率 | 费率 |
| Billing | 請求 | 計費 | 计费 |
| Invoice | 請求書 | 發票 | 发票 |
| Storage | 保管 | 倉儲 | 仓储 |
| Inbound handling | 入庫作業 | 進倉作業 | 入库操作 |
| Outbound handling | 出庫作業 | 出倉作業 | 出库操作 |
| QC | 検品 | 檢驗 | 质检 |
| Return shipping | 返品送料 | 退貨運費 | 退货运费 |
| Postage (fee) | 送料 | 運費 | 运费 |
| Courier cost (our cost) | 配送料 | 運費 | 运费 |
| Markup | マークアップ | 加成 | 加价 |
| Currency | 通貨 | 貨幣 | 货币 |
| Effective window | 適用期間 | 生效期間 | 生效期间 |
| Finalize / Finalized | 確定 / 確定済み | 確認 / 已確認 | 确认 / 已确认 |
| Void | 無効 | 作廢 | 作废 |

## Module lang files -- status

- Translated (ja, zh_TW, zh_CN): inbound.php, outbound.php, fulfillment.php (was
  fulfillment_groups.php), setup.php, issues.php, shop.php, locations.php, sales_orders.php,
  skus.php, fulfillment_pack.php, shipping.php, amazon_spapi.php, amazon_spapi_import.php,
  fulfillment_pick.php, media.php, movements.php, stock_adjustments.php, inventory.php,
  billing.php, stock_adjustment_import.php, stock_counts.php.
- Not yet translated (still hold English values or no per-locale file yet):
  return_orders.php. Translate when that module is done, applying this glossary for shared terms
  (Reserve = 引当 / 分配, Ship = 出荷 / 出貨, etc.).
- outbound.php: was incorrectly marked "done" earlier, found to be ~80% untranslated on audit
  (2026-07-02), then given a full translation pass the same day (all locales, including the FBA
  warehouse fields). Now genuinely done, ja/zh_TW/zh_CN, full key parity with lang/en.
- As of 2026-07-02: translation-backlog.md's Pending table is empty. Everything tracked there
  (outbound.php full pass, outbound FBA fields, sales_orders paste-import block, fulfillment misc)
  has been translated. return_orders.php remains the one known not-yet-translated module file.
