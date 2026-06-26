# Future Todo

This file collects future work that is worth remembering but should not interrupt current feature
delivery. Items here are not active task specs until they are promoted into `docs/tasks/`.

## Low Priority Tech Debt

### Split Feature CSS Out Of The Shared App Layout

Status: future cleanup, not a bug.

Context:

- `resources/views/inventory.blade.php` is currently the shared layout for most of the app.
- Its inline `<style>` block has grown large and includes both common layout styles and feature-specific
  styles.
- Because this layout is shared, CSS in this file can affect many pages.
- So far this has mostly been safe because feature styles use prefixes such as `.so-*`,
  `.sales-order-*`, `.tracking-import-*`, and `.view-switcher-*`.

Decision:

- Do not pause feature work for a large CSS refactor now.
- Do not mass-wrap Sales Orders selectors with `.sales-order-index-page .so-*`.
- Keep using clear feature prefixes for new CSS until the cleanup happens.

Why not mass-wrap with a page class:

- It is a large retrofit for limited gain.
- It increases selector specificity and can make later overrides harder.
- It does not fix the real issue: feature CSS lives in the shared layout file.
- The existing prefix convention already prevents most accidental collisions.

Preferred future cleanup:

1. Keep only truly common CSS in `resources/views/inventory.blade.php`.
   - Design tokens.
   - Base layout.
   - Navigation.
   - Shared table/form primitives that are intentionally reusable.
2. Move feature-specific CSS into the Vite CSS pipeline.
   - Prefer `resources/css/app.css`, or feature files imported by it.
   - Examples: Sales Orders, SKU views, tracking import, return orders.
3. Continue using feature prefixes as lightweight scoping.
   - Example: `.so-*` for Sales Orders.
   - Example: `.tracking-import-*` for tracking import.
4. Review which classes are truly shared before moving them.
   - Shared candidates: `.table-control`, `.action-menu`, `.data-table`.
   - Feature candidates: `.so-note-cell`, `.so-shipping-cell`, `.tracking-import-*`.

Trade-off:

- Inline layout CSS is fast during active UI iteration because changes appear without a Vite build.
- Moving CSS to Vite improves maintainability and caching, but requires `npm run dev` or `npm run build`.

Suggested trigger:

- Do this after current high-churn UI work calms down, especially SKU views, tracking import, returns,
  and Sales Orders toolbar refinements.

Optional first step:

- Run a grep audit for feature-specific classes used outside their intended page.
- Only fix real collisions or high-risk selectors before the larger cleanup.
