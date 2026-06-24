# Task: Outbound Unification Phase 8 - Manual Outbound Reason + Ship Mode Chooser

Parent plan: docs/tasks/outbound-unification-v1.md. Done: Phase 0-7 (rename, schema, populate, pack,
printed flag, printed readers, courier export, tracking import). Backend is fully decoupled from
sales orders. This phase adds the reason chooser + ship_mode to MANUAL outbound creation, so manual
outbounds carry the purpose taxonomy (reporting/audit) and the parcel-vs-bulk mode. It is bounded to
OutboundOrderCreate + its display, independent of the (decision-heavy) index merge.

This realizes the "create chooser" we designed: manual creation is always an OutboundOrder (never a
phantom sales order). Platform/marketplace orders still arrive via sales-order import, not this form,
so there is no platform branch here - the choice is the reason.

## Stack
- Laravel 13, Livewire 4, Flux UI 2, PHP 8.3, SQLite (dev). Tenant scoping unchanged. ASCII only.

## Background
- OutboundOrder already has reason + ship_mode columns (Phase 1) and REASON_* / SHIP_MODE_*
  constants. Consolidation sets reason=customer_order, ship_mode=parcel (Phase 2).
- app/Livewire/OutboundOrderCreate.php is the manual create form (recipient, shipping_method string,
  lines). Its save() does OutboundOrder::create([...]) - currently does NOT set reason/ship_mode, so
  manual outbounds get reason=null, ship_mode=parcel (DB default).

## Change A: OutboundOrderCreate - reason + ship_mode
- Add public properties: $reason = '' (required), $shipMode = '' (defaults from reason, see below).
- Reason options offered in the form (exclude customer_order - that is consolidation only):
  re_ship, replacement, gift, fba, return_to_tenant, b2b, sample, other.
- ship_mode default derived from reason (user can override via a parcel/bulk control):
  re_ship | replacement | gift | other -> parcel; fba | return_to_tenant | b2b -> bulk.
- Validate: reason in the allowed set; ship_mode in [parcel, bulk].
- In save(), set 'reason' => $this->reason and 'ship_mode' => $this->shipMode on OutboundOrder::create.
- Blade (outbound-order-create): add a reason <select> (labels below) and a parcel/bulk control,
  near the top of the form. When reason changes, prefill ship_mode with the derived default
  (a Livewire updatedReason() hook), still editable.

## Change B: show reason (+ ship_mode) on list/detail
- OutboundOrderIndex: show the reason label (and optionally a parcel/bulk badge) per row. Eager-load
  nothing new (reason/ship_mode are columns).
- OutboundOrderDetail: show reason + ship_mode in the order summary section.

## Change C: lang (lang/*/outbound.php, all four locales en/ja/zh_TW/zh_CN)
- reason labels (key e.g. reason_re_ship, reason_replacement, reason_gift, reason_fba,
  reason_return_to_tenant, reason_b2b, reason_sample, reason_other):
  - re_ship:          en "Re-ship",        ja "再送",       zh_TW "重新出貨",   zh_CN "重新出货"
  - replacement:      en "Replacement",    ja "交換品",     zh_TW "換貨",       zh_CN "换货"
  - gift:             en "Gift",           ja "ギフト",     zh_TW "贈品",       zh_CN "赠品"
  - fba:              en "Ship to FBA",    ja "FBA納品",    zh_TW "FBA 入倉",   zh_CN "FBA 入仓"
  - return_to_tenant: en "Return to tenant", ja "荷主返却", zh_TW "退回客戶",   zh_CN "退回客户"
  - b2b:              en "B2B / wholesale", ja "B2B / 卸",  zh_TW "B2B / 批發", zh_CN "B2B / 批发"
  - sample:           en "Sample",         ja "サンプル",   zh_TW "樣品",       zh_CN "样品"
  - other:            en "Other",          ja "その他",     zh_TW "其他",       zh_CN "其他"
  (confirm wording; these follow the glossary - Tenant = 荷主/客戶/客户.)
- ship_mode labels: ship_mode_parcel (en "Parcel", ja "宅配", zh_TW "包裹", zh_CN "包裹"),
  ship_mode_bulk (en "Bulk", ja "大口", zh_TW "大宗", zh_CN "大宗"). Confirm wording.
- field labels: field_reason ("Reason"/"理由"/"原因"/"原因"), field_ship_mode
  ("Ship mode"/"出荷区分"/"出貨類型"/"出货类型"). Confirm.
- Keep ASCII punctuation inside CJK values; verify the CJK files still php -l clean (encoding).

## Out of scope (later phases)
- A unified cross-flow "platform vs non-platform" launcher in front of both create buttons - not
  needed; reason select on the outbound form is the chooser.
- source_sales_order_id linking for re_ship/replacement (needs a sales-order picker; do when
  replacement-from-Issue is built).
- The index merge (one Outbound queue), pack/courier for manual parcels, bulk direct-ship routing by
  ship_mode, nav changes, dropping fulfillment_groups. Those are the remaining big sub-phases.

## Tests (tests/Feature)
- Creating a manual outbound with a chosen reason persists reason + ship_mode on the OutboundOrder.
- ship_mode default derives from reason (fba -> bulk, gift -> parcel) and is overridable.
- Validation rejects an unknown reason / ship_mode and rejects customer_order in the manual form.
- OutboundOrderIndex / Detail render the reason label.
- Existing OutboundOrderTest / outbound create+ship tests pass.

## Acceptance Criteria
- Manual outbound creation requires a reason (from the non-customer_order set) and records reason +
  ship_mode; ship_mode defaults from reason and is overridable.
- Reason is visible on the outbound list and detail; labels exist in all four locales (CJK files
  parse, ASCII punctuation).
- Full suite green; consolidation-created parcels are unaffected (still customer_order / parcel).
