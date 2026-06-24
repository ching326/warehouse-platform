# Task: Outbound Unification Phase 13 - Manual Outbound Courier Export End to End

Parent plan: docs/tasks/done/outbound-unification-v1.md. Done so far: rename arranged, schema,
populate, pack lines, printed flag/readers, tracking import decouple, manual reason + ship mode,
nav fold, detail consolidation, queue re-key (Phase 10), pack entry re-key (Phase 11, commit
b0dbd52), courier export service re-key (Phase 12, commit 0df6ad6). Phase 12 made
CourierExportService CAPABLE of exporting a group-less outbound, but no real UI flow can actually
produce an exportable manual outbound yet. This phase closes that gap so a manually created outbound
(Replacement / Re-ship / Gift / Sample, etc.) can be created, given a real courier shipping method,
and courier-exported end to end through the UI.

Dropping fulfillment_groups / fulfillment_group_orders, dropping sales_orders.courier_csv_exported_at,
unifying the tracking store, and the module/route/class rename remain LATER phases.

## Why now (the gap Phase 12 left)
Two concrete blockers prevent a manual outbound from being courier-exported via the UI today:

1. Creation never sets shipping_method_id. OutboundOrderCreate.save() writes only the free-text
   shipping_method string (app/Livewire/OutboundOrderCreate.php:149) and never the shipping_method_id
   foreign key (added in migration 2026_06_24_000004_add_unified_shipping_fields_to_outbound_orders_table).
   OutboundOrderObserver does not backfill it. OutboundOrderDetail.saveShipping() also only edits
   free-text courier / tracking_no / note (app/Livewire/OutboundOrderDetail.php:143), never the FK.
   CourierExportService.orderCarrierMatches() gates carrier on
   $order->shippingMethod?->carrier?->code (the shipping_method_id relation). With shipping_method_id
   null, the carrier never matches, so a UI-created manual outbound lands in the wrongCarrierOrderIds
   HARD-BLOCK bucket and can never be exported. Phase 12's acceptance test masked this by setting
   shipping_method_id directly on a factory outbound, which the real create flow does not do.

2. No export entry point lists manual outbounds. FulfillmentGroupIndex (the operational screen with
   courier export / tracking import / mark shipped) filters whereNotNull('fulfillment_group_id') in
   both its listing query (app/Livewire/FulfillmentGroupIndex.php:351) and its selection helper
   selectedOutboundOrderIds() (around app/Livewire/FulfillmentGroupIndex.php:542), so manual
   outbounds are invisible and unselectable there. OutboundOrderIndex lists ALL outbounds including
   manual ones but has no selection, no export action.

## Scope guard
- This phase enables the manual export flow; it does not retire FulfillmentGroup. FulfillmentGroup
  stays 1:1 with group-backed (customer_order) outbounds for back-compat.
- Do not change CourierExportService behavior or CSV byte output. Phase 12 already keys it on
  OutboundOrder; this phase only ensures manual outbounds arrive at it with the data it needs and
  gives them a UI path in.
- Keep tenant scoping identical to the existing screens. ASCII punctuation only.
- All data is dummy / reseeded - no production backfill. Reseed rather than migrate data.

## Part A: capture shipping_method_id on manual outbounds (required)
- OutboundOrderCreate: the shipping method selector must store a real ShippingMethod id into
  shipping_method_id, not (or in addition to) the free-text shipping_method string. shippingMethodOptions()
  already loads real ShippingMethod models with carrier - bind the select to the id. Decide the fate of
  the free-text shipping_method column (see Open question 2). Validate shipping_method_id with
  Rule::exists('shipping_methods','id')->where('status','active') and keep it nullable only if a
  no-carrier manual outbound is still allowed to be created (it just will not be exportable).
- OutboundOrderDetail: add shipping_method_id to the editable shipping section (saveShipping), so an
  existing manual outbound can be assigned / corrected to a courier shipping method before export.
  Keep the existing courier / tracking_no / note edits.
- Confirm the OutboundOrder model exposes shipping_method_id in $fillable and the shippingMethod()
  belongsTo (it is already used by Phase 12); no model change expected, verify only.

## Part B: export entry point for manual outbounds (required)
Give manual (group-less) outbounds a UI path into CourierExportService. Choose ONE home (see Open
question 1); recommended: build it on the outbound-native screens rather than FulfillmentGroupIndex,
since the fulfillment-group screens are slated for later retirement.
- Recommended: add a per-order "Export courier CSV" action on OutboundOrderDetail that calls
  validateOrderExport / exportOrders for the single outbound id, surfaces the same validation buckets
  (wrong carrier, unsupported courier, no ready lines, already exported -> re-export confirmation),
  and redirects to courier-export-batches.download on success. Optionally add batch selection +
  export to OutboundOrderIndex reusing the same validation/confirmation flow.
- Whichever screen is chosen, reuse the existing CourierExportValidationResult buckets and the
  re-export confirmation pattern already in FulfillmentGroupIndex; do not fork the logic.
- Manual outbounds have no linked sales orders, so the export writes a batch-order row with
  sales_order_id null and platform_order_id = outbound ref (Phase 12 already handles this path).

## Tests
- End-to-end through the REAL create flow (not a hand-set factory): drive OutboundOrderCreate with a
  Replacement reason, parcel ship mode, a supported-courier ShippingMethod, and ready lines; assert
  the created outbound has shipping_method_id set; then run validateOrderExport and assert ok (not
  wrongCarrierOrderIds); then exportOrders and assert CSV + batch + batch-order row (sales_order_id
  null, platform_order_id = ref) + courier_csv_exported_at. This is the acceptance test that would
  have caught the Phase 12 gap.
- OutboundOrderDetail: assigning a shipping_method_id then exporting works; a manual outbound with no
  shipping_method_id is hard-blocked as wrong carrier (negative test).
- Whichever export entry point is built: tenant-scope test (cannot export an outbound outside
  allowedTenantIds), already-exported -> re-export confirmation test.
- Keep the existing FulfillmentGroupIndex export tests green (its group-backed flow is unchanged).

## Acceptance
- A manual Replacement outbound created entirely through the UI can be courier-exported to a CSV +
  batch row end to end, with no FulfillmentGroup involved.
- shipping_method_id is captured at creation and editable on the detail screen.
- CSV output for group-backed outbounds is unchanged (byte-identical).
- Full suite green (php artisan test).
- ASCII punctuation only in all touched specs, code, and lang files.

## Open questions (resolved)
1. Export entry point home: per-order action on OutboundOrderDetail (outbound-native screens chosen).
2. Free-text shipping_method column: dropped this phase via
   2026_06_25_000001_drop_shipping_method_from_outbound_orders_table.php. shipping_method_id FK is
   now the sole source of truth. ShipOutboundOrderService, OutboundOrderShip, FulfillmentGroupPack,
   and FulfillmentGroupIndex no longer write or pass the free-text value.

## Commit
Per-phase workflow: implement, full suite green, then commit. Suggested message:
"Enable courier export for manual outbounds". Move this spec to docs/tasks/done/ after commit.
