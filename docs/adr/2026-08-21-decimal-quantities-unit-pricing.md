# ADR - Decimal quantities & unit-based pricing (per-unit selling)

- **Date:** 2026-08-21
- **Status:** Accepted
- **Stack:** Backend + Frontend (cross-stack; see FE ADR `2026-08-21-decimal-quantity-selectors.md`).

## Context

Product prices were static and every quantity was a **whole number**: a shop that sold sugar by weight had to create a separate product per quantity (1kg sugar, 0.5kg sugar, ...), cluttering inventory and making product management inefficient. Clients asked for the ability to define a **base price per unit** (e.g. UGX 4,000/kg), select a quantity at checkout (e.g. 0.5kg), and have the price calculated automatically.

The whole stack assumed integers: `sale_items.quantity`, `products.stock_quantity`, `location_product.stock_quantity`, `stock_movements.(quantity_change|stock_before|stock_after)`, `order_items.quantity` were all integer columns; the POS steppers, refunds, storefront carts, invoices, and every request validator enforced whole numbers.

## Decision

Make quantities decimal end-to-end and introduce a unit-agnostic pricing-unit model.

### 1. Decimal quantity columns (forward-only migrations)

Widened the quantity-bearing columns to `decimal(10,3)` (three decimal places covers 0.25/0.5/0.75 kg cleanly; integer products are unaffected):

- `sale_items.quantity`, `sale_items.refunded_quantity`
- `products.stock_quantity`
- `location_product.stock_quantity`
- `stock_movements.quantity_change`, `stock_before`, `stock_after`
- `order_items.quantity`

Migration `2026_08_21_000001_widen_quantity_columns_to_decimal.php`. Existing integer values carry over unchanged. **No destructive migration.**

### 2. Decimal-safe math everywhere

Removed `(int)` casts on quantity across `SaleService` (create + refund), `TaxEngine`, `StockMovementService`, `OrderService`, `InvoiceService`, `PurchaseOrderService` (fulfill + receive), `InventoryOverviewService`, `InventoryLedgerService`, `InventoryCogsService`, `ComputesDashboardAndVat`, `ComputesProductPerformance`, `ProductService`, `ProductImportService`, `ProductRepository`, `ReportsController`, and the product export. Refund math now guards against zero quantity with `max(0.001, ...)` instead of `max(1, ...)`, so a 0.5kg refund restores exactly 0.5kg. Line totals are `round(qty × unit_price, 2)`.

### 3. Request validation accepts decimals

`items.*.quantity` / `quantity` rules changed from `integer, min:1` to `numeric, gt:0` in `SaleRequest`, `SaleController` (single + batch), `SaleItemRequest`, `OrderRequest`, `PurchaseOrderRequest`, `PurchaseOrderController`, `StockTransferRequest`, `StorefrontPlaceOrderRequest`; `StockMovementRequest.quantity_change` is now `numeric` (adjustments may be negative). Model casts updated to `decimal:3` (`SaleItem`, `OrderItem`, `PurchaseOrderItem`, `StockMovement`, `LocationProduct`, `Product.stock_quantity`).

### 4. Unit-agnostic pricing units

- New `App\Support\PricingUnits` class: groups units into **mass** (`kg`, `g`, `tonne`), **volume** (`litre`, `ml`), and **piece** (`piece`, `box`, `dozen`, `packet`, `bag`, `bundle`, `carton`, `pair`).
- `supportsDecimalQuantity()`: mass/volume units accept fractional quantities; pieces (and any **unknown/custom unit**) are integers. **Custom units never crash** - `family()` defaults unknown units to `piece`, `label()` shows the user's own text, and `baseUnit()` keeps a family on one stock scale (`g`/`tonne` → `kg`, `ml` → `litre`).
- New `products.pricing_unit` column (`varchar(32)`, nullable) holds the machine-readable unit; the existing free-text `products.unit` remains the human label. `ProductService::normalizeCatalogType` derives `pricing_unit` from `unit` when recognized.
- `ProductResource` and the storefront catalog (`StorefrontCatalogConcern::publicProductPayload`) expose `pricing_unit`, `supports_decimal_quantity`, and `pricing_unit_label`.

### 5. Legacy backfill

Migration `2026_08_21_000003_backfill_pricing_unit_from_unit.php` populates `pricing_unit` for **pre-existing products** from their free-text `unit` when it matches a known unit (self-contained, no app classes). Products with unrecognised/custom units keep `pricing_unit = NULL` and behave as integer items - **nothing breaks**.

## Consequences

- A single product (sugar 4,000/kg) now sells 0.5kg at 2,000; stock deducts 0.5; receipts, refunds, storefront orders, and invoices all carry decimals.
- Legacy products with recognised weight/volume units gain decimal capability automatically via the backfill; custom units are untouched and safe.
- Stock/refund/order math is now float throughout; three-decimal storage avoids binary float drift on the common fractions.
- Test coverage: `tests/Feature/DecimalQuantityTest.php` (0.5kg sale + stock deduction, custom-unit integer safety, fractional refund restoring stock, resource capability flags, legacy fallback); existing sale/stock/PO suites updated for the decimal cast string shape.

## References

- Migrations `2026_08_21_000001_widen_quantity_columns_to_decimal.php`, `2026_08_21_000002_add_pricing_unit_to_products_table.php`, `2026_08_21_000003_backfill_pricing_unit_from_unit.php`
- `app/Support/PricingUnits.php`
- `app/Services/SaleService.php`, `app/Services/ProductService.php`, `app/Services/OrderService.php`, `app/Services/InvoiceService.php`, `app/Services/PurchaseOrderService.php`
- `app/Support/TaxEngine.php`
- `app/Http/Resources/ProductResource.php`, `app/Services/Storefront/StorefrontCatalogConcern.php`
- `tests/Feature/DecimalQuantityTest.php`
- FE ADR `2026-08-21-decimal-quantity-selectors.md` (frontend quantity selectors + tests)