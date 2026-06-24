# Task: Outbound Unification Phase 9 - Fold Fulfillment Nav Under Outbound

Parent plan: docs/tasks/outbound-unification-v1.md. Decisions: keep the manual outbound page and the
fulfillment/parcel queue as SEPARATE pages, but group them under one "Outbound" top-nav section.
This phase only changes the navigation grouping - no page, entity, route, or behavior changes.
Small and safe; do it as its own commit.

## Stack
- Laravel 13, Livewire 4, Flux UI 2, PHP 8.3. ASCII punctuation only (CJK values must php -l clean).

## Current nav (resources/views/components/layout/navigation.blade.php)
- Top-level "Outbound" = a single link to route('outbound.index') (manual outbound list), active via
  $outboundActive = request()->routeIs('outbound.*').
- Separate top-level "Fulfillment" = a dropdown (top-nav-item, x-data open) with sub-links:
  fulfillment-groups.index (nav_fulfillment_group_list) and fulfillment.pick-summary
  (nav_pick_summary), active via $fulfillmentActive = routeIs('fulfillment-groups.*','fulfillment.*').

## Change
Make "Outbound" a single dropdown (reuse the existing top-nav-item / x-data="{ open: false }"
pattern from the Fulfillment block) and remove the standalone Fulfillment block:
- Parent button label: common.nav_outbound ("Outbound" / 出庫 / 出倉 / 出库). Parent is-active when
  $outboundActive || $fulfillmentActive.
- Dropdown sub-links (each with its own routeIs is-active + @click="open = false" + wire:navigate):
  1. route('outbound.index')         -> common.nav_outbound_orders (NEW key; the manual outbound
     list), active routeIs('outbound.*').
  2. route('fulfillment-groups.index') -> common.nav_fulfillment_group_list, active
     routeIs('fulfillment-groups.*').
  3. route('fulfillment.pick-summary') -> common.nav_pick_summary, active
     routeIs('fulfillment.pick-summary').
Delete the old standalone "Fulfillment" top-nav-item block. Keep the $fulfillmentActive computed (now
used for the Outbound parent). Leave all routes and pages exactly as they are.

## Lang (lang/*/common.php, all four locales)
Add nav_outbound_orders (the manual list sub-label; mirror the outbound page title):
- en "Outbound orders", ja "出庫オーダー", zh_TW "出倉訂單", zh_CN "出库订单".
Reuse existing nav_outbound, nav_fulfillment_group_list, nav_pick_summary. Verify CJK common.php
files still php -l clean (UTF-8) after editing.

## Out of scope
- No page merge (the two pages stay separate, per decision).
- No entity/route changes, no FulfillmentGroup elimination, no detail-page consolidation (those are
  the following phases).

## Tests
- Nav renders an "Outbound" dropdown containing the three sub-links (outbound list, fulfillment
  list, pick summary) and no standalone "Fulfillment" top-level item.
- Visiting an outbound.* OR fulfillment.* route marks the Outbound parent active; each sub-link
  active state resolves by its own route. (Extend existing navigation/smoke tests if present.)
- nav_outbound_orders resolves in all four locales.

## Acceptance Criteria
- One "Outbound" top-nav dropdown groups the manual outbound list, the fulfillment list, and pick
  summary; the separate Fulfillment top-level item is gone.
- New nav_outbound_orders key present in en/ja/zh_TW/zh_CN; CJK files parse.
- No behavior/route/page changes; full suite green.
