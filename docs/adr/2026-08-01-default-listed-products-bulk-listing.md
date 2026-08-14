# ADR: New products default to listed (supply + storefront); bulk list/unlist endpoint

**Date:** 2026-08-01
**Status:** Accepted

**Context:** Products created in the app (or imported via CSV) were **unlisted by default** for both the B2B supply marketplace (`listed_for_supply`) and the public storefront (`listed_for_storefront`). The user had to remember to list each product manually after creating or importing it, so new inventory frequently never appeared on either shop until someone noticed. The product table also only exposed listing toggles one product at a time.

**Decision:**
- **Default on** for new products: `listed_for_supply` and `listed_for_storefront` default to `true` via migration (`change()`), and `ProductService::create` explicitly sets `(bool)($data[$flag] ?? true)` after `normalizeCatalogType`. `ProductImportService` sets both flags `true` for every imported row. Existing products keep their current state (opt-in via bulk List).
- **Bulk list/unlist**: `ProductService::bulkUpdateListing(array $ids, int $businessId, string $channel, bool $listed): int` - scoped to the business, operates on supply or storefront channel.
  - Supply **list** auto-fills `supply_price` from `wholesale_price ?? unit_price` (when null) and sets `supply_min_qty` to `1`, then sets `listed_at`.
  - Supply **unlist** clears `listed_at` (and hides from the supply marketplace).
  - Storefront list/unlist sets/clears `storefront_listed_at`.
  - Returns the number of products updated.
- `ProductController::bulkListing` exposes it via `POST /products/bulk-listing` → `{ updated: int }`, validated by `ProductBulkListingRequest` (`ids` required array 1..5000, `channel in:supply,storefront`, `listed` boolean).
- Interface method added to `ProductServiceInterface`.

**Consequences:**
- New/imported inventory appears on both shops immediately unless the user opts out - removing the "invisible inventory" trap.
- Bulk action supports entire product table updates in one request (≤5000 ids).
- Existing unlisted products remain unlisted until explicitly listed, so the migration doesn't surprise existing setups.
- Listing mutations remain online-only (no offline queueing), consistent with bulk-delete and the B2B supply-chain module.

**Files:**
- `database/migrations/2026_08_01_000000_default_products_listed_for_sale.php` (new)
- `app/Services/ProductService.php` (`create`, `bulkUpdateListing`)
- `app/Services/ProductImportService.php`
- `app/Services/Contracts/ProductServiceInterface.php`
- `app/Http/Requests/ProductBulkListingRequest.php` (new)
- `app/Http/Controllers/Api/ProductController.php`
- `routes/api/v1/products.php`

**Tests:** `tests/Feature/ProductListingTest.php` - 6 tests, 31 assertions (defaults on create/import, bulk supply fallback price + timestamps, storefront list/unlist, business scoping, validation).

**Verification:** `composer vera:fast` ✅ · `php artisan migrate --pretend` ✅ · neighbor suites `ProductImportTest|ProductTest` 12/12 ✅
