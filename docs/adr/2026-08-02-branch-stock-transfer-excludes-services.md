# ADR: Branch stock transfer excludes service items

**Date:** 2026-08-02
**Status:** Accepted

**Context:** Branch-to-branch stock transfer treated catalog services as physical stock. `StockMovementService::transfer()` deducted/incremented `location_product` quantities and wrote a `type = transfer` movement for service lines, letting a service accrue a branch balance even though it tracks no inventory. This was inconsistent with the purchase-order receipt path (`d484fca` introduced in `PurchaseOrderService::receive()`), which skips stock movements for service lines because services are not quantitative.

**Decision:** Services are never branch-transferable.
- In `StockMovementService::transfer()`, load the `Product` and, when `!$product->tracksStock()`, `continue` (skip) the item — no `location_product` balance change and no transfer movement record.
- Frontend `BranchTransferModal.tsx` filters services out of the selectable list (`isServiceItem()` helper) for a consistent UX.

**Consequences:**
- Services can never carry a branch stock balance or appear in transfer ledgers.
- Transfer stock-availability checks now only ever apply to trackable products.
- Rule now matches receipt semantics ("services are not quantitative").

**Files:**
- `app/Services/StockMovementService.php` (`transfer()`)

**Verification:** `composer vera:fast` (php -l + logic, incl. file-size) ✅ · commit `78bbf16`