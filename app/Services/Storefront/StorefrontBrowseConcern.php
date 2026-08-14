<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\Category;
use App\Models\Product;
use App\Support\StorefrontLocations;
use App\Support\StorefrontSlug;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Public storefront browse queries + server-side filtering + facets.
 * Kept as a trait so StorefrontService stays under 500 lines.
 */
trait StorefrontBrowseConcern
{
    /**
     * @param  array<string, mixed>  $filters  keys: category, city, country, sort
     */
    public function discoverShops(
        ?string $q,
        array $filters = [],
        int $perPage = 24,
        ?int $viewerUserId = null,
    ): LengthAwarePaginator {
        $query = Business::query()->publicStorefront();

        if ($q !== null && trim($q) !== '') {
            // Discover invites "@username" - strip so slug LIKE matches.
            $normalized = ltrim(trim($q), '@');
            $term = '%'.$normalized.'%';
            $slugTerm = '%'.StorefrontSlug::normalize($normalized).'%';
            $query->where(function (Builder $b) use ($term, $slugTerm) {
                $b->where('name', 'like', $term)
                    ->orWhere('city', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('slug', 'like', $term)
                    ->orWhere('slug', 'like', $slugTerm);
            });
        }

        $this->applyBusinessCategoryFilter($query, $filters['category'] ?? null);
        $this->applyLocationFilters($query, $filters['city'] ?? null, $filters['country'] ?? null);
        $this->applyRatingFilter($query, $filters['min_rating'] ?? null, 'business');

        $this->withShopStorefrontRatingAggregates($query, $viewerUserId);

        return $this->applyShopSort($query, $filters['sort'] ?? null)
            ->paginate(min(48, max(1, $perPage)));
    }

    /**
     * @param  array<string, mixed>  $filters  keys: business_category, type, currency, price_min, price_max,
     *                                         in_stock, min_rating, city, country, sort
     */
    public function discoverProducts(
        ?string $q,
        ?string $category,
        array $filters = [],
        int $perPage = 24,
        ?int $viewerUserId = null,
    ): LengthAwarePaginator {
        $query = $this->listedProductsQuery();

        if ($q !== null && trim($q) !== '') {
            $term = '%'.trim($q).'%';
            $query->where(function (Builder $b) use ($term) {
                $b->where('products.name', 'like', $term)
                    ->orWhere('products.description', 'like', $term)
                    ->orWhereHas('business', fn (Builder $bb) => $bb->where('name', 'like', $term));
            });
        }

        $this->applyProductCategoryFilter($query, $category);

        if (!empty($filters['business_category'])) {
            $query->whereHas(
                'business',
                fn (Builder $b) => $this->applyBusinessCategoryFilter($b, $filters['business_category'])
            );
        }
        if (!empty($filters['city']) || !empty($filters['country'])) {
            $query->whereHas(
                'business',
                fn (Builder $b) => $this->applyLocationFilters($b, $filters['city'] ?? null, $filters['country'] ?? null)
            );
        }
        $this->applyProductTypeFilter($query, $filters['type'] ?? null);
        $this->applyCurrencyFilter($query, $filters['currency'] ?? null);
        $this->applyPriceRangeFilter($query, $filters['price_min'] ?? null, $filters['price_max'] ?? null);
        $this->applyInStockFilter($query, $filters['in_stock'] ?? null);
        $this->applyRatingFilter($query, $filters['min_rating'] ?? null, 'product');

        $this->withProductStorefrontRatingAggregates($query, $viewerUserId);

        return $this->applyProductSort($query, $filters['sort'] ?? null)
            ->with(['category:id,name', 'business:id,name,slug,logo_path,city,currency,storefront_enabled'])
            ->paginate(min(48, max(1, $perPage)));
    }

    /** @return Collection<int, array{id: int|null, name: string, product_count: int}> */
    public function discoverCategories(): Collection
    {
        $query = Category::query()
            ->select('categories.id', 'categories.name')
            ->selectRaw('COUNT(products.id) as product_count')
            ->join('products', 'products.category_id', '=', 'categories.id')
            ->join('businesses', 'businesses.id', '=', 'products.business_id');
        Business::constrainJoinedPublicStorefront($query);

        return $query
            ->whereNull('businesses.deleted_at')
            ->where('products.listed_for_storefront', true)
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->groupBy('categories.id', 'categories.name')
            ->orderBy('categories.name')
            ->get()
            ->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'product_count' => (int) $c->product_count,
            ]);
    }

    /**
     * @param  array<string, mixed>  $filters  keys: type, currency, price_min, price_max, in_stock, min_rating, sort
     */
    public function shopProducts(
        Business $business,
        ?string $category = null,
        array $filters = [],
        ?int $viewerUserId = null,
        int $perPage = 24,
        int $page = 1,
        ?string $q = null,
    ): LengthAwarePaginator {
        $query = Product::query()
            ->where('business_id', $business->id)
            ->where('listed_for_storefront', true)
            ->where('is_active', true)
            ->with(['category:id,name']);

        if ($q !== null && trim($q) !== '') {
            $term = '%'.trim($q).'%';
            $query->where(function (Builder $b) use ($term) {
                $b->where('products.name', 'like', $term)
                    ->orWhere('products.description', 'like', $term);
            });
        }

        $this->applyProductCategoryFilter($query, $category);
        $this->applyProductTypeFilter($query, $filters['type'] ?? null);
        $this->applyCurrencyFilter($query, $filters['currency'] ?? null);
        $this->applyPriceRangeFilter($query, $filters['price_min'] ?? null, $filters['price_max'] ?? null);
        $this->applyInStockFilter($query, $filters['in_stock'] ?? null);
        $this->applyRatingFilter($query, $filters['min_rating'] ?? null, 'product');

        $this->withProductStorefrontRatingAggregates($query, $viewerUserId);

        return $this->applyProductSort($query, $filters['sort'] ?? 'name')
            ->paginate(min(48, max(1, $perPage)), ['*'], 'page', max(1, $page));
    }

    /**
     * Resolve a single listed product by its storefront slug within an enabled
     * shop. Returns null when the shop is unavailable or the product is not
     * currently listed/active on the storefront.
     */
    public function findListedProductForShop(Business $business, string $productSlug): ?Product
    {
        return Product::query()
            ->where('business_id', $business->id)
            ->where('listed_for_storefront', true)
            ->where('is_active', true)
            ->where('slug', $productSlug)
            ->with(['category:id,name', 'business:id,name,slug,logo_path,city,currency'])
            ->first();
    }

    /** @return array<string, mixed> */
    public function discoverFacets(): array
    {
        $blocked = config('platform.blocked_business_statuses', ['restricted', 'suspended']);

        $businessCategories = BusinessCategory::query()
            ->select('business_categories.id', 'business_categories.slug', 'business_categories.name')
            ->selectRaw('SUM(CASE WHEN businesses.id IS NOT NULL AND businesses.deleted_at IS NULL AND businesses.storefront_enabled = true AND businesses.status NOT IN ('.implode(',', array_map(fn ($s) => "'".$s."'", $blocked)).') THEN 1 ELSE 0 END) as cnt')
            ->leftJoin('businesses', 'businesses.business_category_id', '=', 'business_categories.id')
            ->groupBy('business_categories.id', 'business_categories.slug', 'business_categories.name')
            ->orderBy('business_categories.sort_order')
            ->orderBy('business_categories.name')
            ->get();

        $countries = Business::query()->publicStorefront()
            ->whereNotNull('country')
            ->whereRaw('TRIM(country) <> ?', [''])
            ->selectRaw('TRIM(country) as name')
            ->selectRaw('COUNT(*) as count')
            ->groupByRaw('TRIM(country)')
            ->orderBy('name')
            ->get()
            ->keyBy(fn ($r) => mb_strtolower((string) $r->name));

        $cities = Business::query()->publicStorefront()
            ->whereNotNull('city')
            ->whereRaw('TRIM(city) <> ?', [''])
            ->selectRaw('TRIM(city) as name')
            ->selectRaw('COUNT(*) as count')
            ->groupByRaw('TRIM(city)')
            ->orderBy('name')
            ->get()
            ->keyBy(fn ($r) => mb_strtolower((string) $r->name));

        $currencies = Business::query()->publicStorefront()
            ->whereNotNull('currency')
            ->whereRaw('TRIM(currency) <> ?', [''])
            ->selectRaw('TRIM(currency) as name')
            ->selectRaw('COUNT(*) as count')
            ->groupByRaw('TRIM(currency)')
            ->orderBy('name')
            ->get()
            ->keyBy(fn ($r) => strtoupper((string) $r->name));

        // Merge the authoritative reference list (never empty, East Africa first)
        // with live counts so businesses already showing options keep their counts.
        $mergedCountries = collect(StorefrontLocations::countries())
            ->map(fn (string $name) => [
                'name' => $name,
                'count' => (int) ($countries[mb_strtolower($name)]->count ?? 0),
            ])
            ->values();

        $mergedCities = collect(StorefrontLocations::cities())
            ->map(fn (string $name) => [
                'name' => $name,
                'count' => (int) ($cities[mb_strtolower($name)]->count ?? 0),
            ])
            ->values();

        $mergedCurrencies = collect(StorefrontLocations::currencies())
            ->map(fn (string $label, string $code) => [
                'code' => $code,
                'name' => $label,
                'count' => (int) ($currencies[strtoupper($code)]->count ?? 0),
            ])
            ->values();

        $types = $this->listedProductsQuery()
            ->select('products.type')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('products.type')
            ->orderBy('products.type')
            ->get();

        $expr = $this->effectivePriceSql();
        $priceRow = $this->listedProductsQuery()
            ->selectRaw('MIN('.$expr.') as mins')
            ->selectRaw('MAX('.$expr.') as maxs')
            ->first();

        $typeLabels = [Product::TYPE_PRODUCT => 'Products', Product::TYPE_SERVICE => 'Services'];

        return [
            'business_categories' => $businessCategories
                ->map(fn ($c) => ['id' => (int) $c->id, 'slug' => $c->slug, 'name' => $c->name, 'count' => (int) $c->cnt])
                ->values(),
            'locations' => [
                'countries' => $mergedCountries,
                'cities' => $mergedCities,
            ],
            'currencies' => $mergedCurrencies,
            'product_types' => $types
                ->map(fn ($t) => ['value' => $t->type, 'name' => $typeLabels[$t->type] ?? $t->type, 'count' => (int) $t->count])
                ->values(),
            'price' => [
                'min' => (float) ($priceRow->mins ?? 0),
                'max' => (float) ($priceRow->maxs ?? 0),
            ],
        ];
    }

    /** Match a product's Category by id (digits) or name (LIKE). */
    private function applyProductCategoryFilter(Builder $query, ?string $category): void
    {
        if ($category !== null && trim($category) !== '') {
            $cat = trim($category);
            $query->whereHas('category', function (Builder $b) use ($cat) {
                if (ctype_digit($cat)) {
                    $b->where('categories.id', (int) $cat);
                } else {
                    $b->where('categories.name', 'like', '%'.$cat.'%');
                }
            });
        }
    }

    /** Match a business's curated category by id (digits) or slug. Business-scoped builder. */
    private function applyBusinessCategoryFilter(Builder $query, mixed $category): void
    {
        if ($category === null || trim((string) $category) === '') {
            return;
        }
        $cat = trim((string) $category);
        if (ctype_digit($cat)) {
            $query->where('businesses.business_category_id', (int) $cat);
        } else {
            $query->whereHas('businessCategory', fn (Builder $b) => $b->where('business_categories.slug', $cat));
        }
    }

    /** Case-insensitive, trimmed exact match on city + country. Business-scoped builder. */
    private function applyLocationFilters(Builder $query, mixed $city, mixed $country): void
    {
        $this->applyExactMatch($query, 'city', $city);
        $this->applyExactMatch($query, 'country', $country);
    }

    private function applyExactMatch(Builder $query, string $column, mixed $value): void
    {
        if ($value === null || trim((string) $value) === '') {
            return;
        }
        $needle = mb_strtolower(trim((string) $value));
        $query->whereRaw('LOWER(TRIM(businesses.'.$column.')) = ?', [$needle]);
    }

    private function applyProductTypeFilter(Builder $query, mixed $type): void
    {
        if (in_array($type, [Product::TYPE_PRODUCT, Product::TYPE_SERVICE], true)) {
            $query->where('products.type', $type);
        }
    }

    /** Match products whose owning business trades in the given currency (case-insensitive). */
    private function applyCurrencyFilter(Builder $query, mixed $currency): void
    {
        if ($currency === null || trim((string) $currency) === '') {
            return;
        }
        $needle = mb_strtoupper(trim((string) $currency));
        $query->whereHas(
            'business',
            fn (Builder $b) => $b->whereRaw('UPPER(TRIM(businesses.currency)) = ?', [$needle])
        );
    }

    /** Effective price SQL - mirrors Product::effectiveUnitPrice(). */
    private function effectivePriceSql(): string
    {
        $driver = $this->connectionDriver();
        $clamp = $driver === 'sqlite'
            ? 'MIN(100, MAX(0, products.discount_percent))'
            : 'LEAST(100, GREATEST(0, products.discount_percent))';
        return '(CASE WHEN COALESCE(products.discount_percent, 0) > 0 '
            ."THEN ROUND(products.unit_price * (1 - {$clamp} / 100), 2) "
            .'ELSE products.unit_price END)';
    }

    /** DB driver name - SQLite lacks LEAST()/GREATEST(), so facets clamp differently. */
    private function connectionDriver(): string
    {
        return (string) \Illuminate\Support\Facades\DB::connection()->getDriverName();
    }

    private function applyPriceRangeFilter(Builder $query, mixed $min, mixed $max): void
    {
        $minV = $min !== null && trim((string) $min) !== '' ? (float) $min : null;
        $maxV = $max !== null && trim((string) $max) !== '' ? (float) $max : null;
        if ($minV === null && $maxV === null) {
            return;
        }
        $expr = $this->effectivePriceSql();
        $query->where(function (Builder $b) use ($expr, $minV, $maxV) {
            if ($minV !== null) {
                $b->whereRaw($expr.' >= ?', [$minV]);
            }
            if ($maxV !== null) {
                $b->whereRaw($expr.' <= ?', [$maxV]);
            }
        });
    }

    private function applyInStockFilter(Builder $query, mixed $inStock): void
    {
        if (in_array($inStock, ['1', 'true', 'yes', 'on'], true)) {
            $query->where(function (Builder $b) {
                $b->where('products.type', Product::TYPE_SERVICE)
                    ->orWhere('products.stock_quantity', '>', 0);
            });
        }
    }

    /** Correlated-subquery MIN rating filter (composes with paginate). */
    private function applyRatingFilter(Builder $query, mixed $minRating, string $kind): void
    {
        if ($minRating === null || trim((string) $minRating) === '') {
            return;
        }
        $min = (float) $minRating;
        if ($min <= 0) {
            return;
        }
        if ($kind === 'business') {
            $query->whereRaw(
                '(SELECT COALESCE(AVG(r.rating), 0) FROM business_storefront_ratings r '
                .'WHERE r.business_id = businesses.id) >= ?',
                [$min],
            );
        } else {
            $query->whereRaw(
                '(SELECT COALESCE(AVG(r.rating), 0) FROM product_storefront_ratings r '
                .'WHERE r.product_id = products.id) >= ?',
                [$min],
            );
        }
    }

    protected function applyShopSort(Builder $query, mixed $sort): Builder
    {
        return match ($sort) {
            'newest' => $query->orderByDesc('businesses.created_at'),
            'rating' => $query->orderByDesc('businesses.storefront_ratings_avg_rating'),
            'name' => $query->orderBy('businesses.name'),
            default => $query->orderBy('businesses.name'),
        };
    }

    protected function applyProductSort(Builder $query, mixed $sort): Builder
    {
        $expr = $this->effectivePriceSql();
        return match ($sort) {
            'price_asc' => $query->orderByRaw($expr.' asc'),
            'price_desc' => $query->orderByRaw($expr.' desc'),
            'rating' => $query->orderByDesc('products.storefront_ratings_avg_rating'),
            'name' => $query->orderBy('products.name'),
            default => $query->orderByDesc('products.storefront_listed_at')->orderByDesc('products.id'),
        };
    }
}