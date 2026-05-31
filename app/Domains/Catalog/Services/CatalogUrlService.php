<?php

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Models\Category;
use Illuminate\Validation\ValidationException;

class CatalogUrlService
{
    /**
     * @param  array<string, array<int, string>>  $filters
     */
    public function filterUrl(?Category $category, array $filters, ?int $priceFrom = null, ?int $priceTo = null): string
    {
        $url = $category === null ? '/catalog/' : $this->categoryUrl($category);
        $segments = collect($filters)
            ->sortKeys()
            ->map(function (array $values, string $name): string {
                $values = collect($values)
                    ->map(fn (mixed $value): string => rawurlencode((string) $value))
                    ->sort()
                    ->implode('-or-');

                return rawurlencode($name).'-is-'.$values;
            })
            ->values()
            ->all();

        if ($priceFrom !== null || $priceTo !== null) {
            $segments[] = match (true) {
                $priceFrom !== null && $priceTo !== null => 'price-from-'.$priceFrom.'-to-'.$priceTo,
                $priceFrom !== null => 'price-from-'.$priceFrom,
                default => 'price-to-'.$priceTo,
            };
        }

        return $segments === []
            ? $url
            : $url.'filter/'.implode('/', $segments).'/apply/';
    }

    /**
     * @param  array<string, array<int, string>>  $filters
     */
    public function priceUrlTemplate(?Category $category, array $filters): string
    {
        $url = $this->filterUrl($category, $filters);

        if (str_contains($url, '/filter/')) {
            return str_replace('/apply/', '/price-from-{price_from}-to-{price_to}/apply/', $url);
        }

        return $url.'filter/price-from-{price_from}-to-{price_to}/apply/';
    }

    /**
     * @return array{category_path: string, filters: array<string, array<int, string>>, price_from: int|null, price_to: int|null}
     */
    public function parseCatalogPath(string $path): array
    {
        $segments = collect(explode('/', trim($path, '/')))
            ->filter()
            ->values();

        if ($segments->first() === 'catalog') {
            $segments->shift();
        }

        $filterPosition = $segments->search('filter');
        $categorySegments = $filterPosition === false ? $segments : $segments->take($filterPosition);
        $filterSegments = $filterPosition === false ? collect() : $segments->slice($filterPosition + 1)->values();

        if ($filterSegments->last() === 'apply') {
            $filterSegments->pop();
        } elseif ($filterSegments->isNotEmpty()) {
            throw ValidationException::withMessages([
                'path' => ['Catalog filter URL must end with apply.'],
            ]);
        }

        $categoryPath = $categorySegments->implode('/');
        $this->pathSegments($categoryPath);
        $filters = [];
        $priceFrom = null;
        $priceTo = null;

        foreach ($filterSegments as $segment) {
            if (preg_match('/^price-from-(\d+)-to-(\d+)$/', $segment, $matches) === 1) {
                $priceFrom = (int) $matches[1];
                $priceTo = (int) $matches[2];

                continue;
            }

            if (preg_match('/^price-from-(\d+)$/', $segment, $matches) === 1) {
                $priceFrom = (int) $matches[1];

                continue;
            }

            if (preg_match('/^price-to-(\d+)$/', $segment, $matches) === 1) {
                $priceTo = (int) $matches[1];

                continue;
            }

            if (preg_match('/^([a-zA-Z0-9_-]+)-is-(.+)$/', $segment, $matches) !== 1) {
                throw ValidationException::withMessages([
                    'path' => ['Catalog filter URL contains an invalid segment.'],
                ]);
            }

            $filters[rawurldecode($matches[1])] = collect(explode('-or-', $matches[2]))
                ->map(fn (string $value): string => rawurldecode($value))
                ->values()
                ->all();
        }

        if ($priceFrom !== null && $priceTo !== null && $priceFrom > $priceTo) {
            throw ValidationException::withMessages([
                'path' => ['Minimum price must not exceed maximum price.'],
            ]);
        }

        return [
            'category_path' => $categoryPath,
            'filters' => $filters,
            'price_from' => $priceFrom,
            'price_to' => $priceTo,
        ];
    }

    public function assertValidParent(Category $category): void
    {
        $ancestors = [];
        $parentId = $category->parent_id;

        while ($parentId !== null) {
            if ($category->exists && $parentId === $category->id || in_array($parentId, $ancestors, true)) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Category hierarchy must not contain cycles.'],
                ]);
            }

            $ancestors[] = $parentId;

            if (count($ancestors) >= 3) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Category hierarchy supports up to three levels.'],
                ]);
            }

            $parentId = Category::query()->whereKey($parentId)->value('parent_id');
        }
    }

    public function resolveCategory(?string $path = null, ?string $slug = null): ?Category
    {
        if ($path === null || trim($path, '/') === '') {
            return $slug === null
                ? null
                : Category::query()->where('slug', $slug)->where('is_active', true)->first();
        }

        $segments = $this->pathSegments($path);

        /** @var Category|null $category */
        $category = null;

        foreach ($segments as $segment) {
            $category = Category::query()
                ->where('slug', $segment)
                ->where('parent_id', $category?->id)
                ->where('is_active', true)
                ->first();

            if (! $category instanceof Category) {
                throw ValidationException::withMessages([
                    'category_path' => ['Category path was not found.'],
                ]);
            }
        }

        return $category;
    }

    public function categoryPath(Category $category): string
    {
        return collect($this->ancestorsAndSelf($category))
            ->pluck('slug')
            ->implode('/');
    }

    public function categoryUrl(Category $category): string
    {
        return '/catalog/'.$this->categoryPath($category).'/';
    }

    /**
     * @return array<int, array{id: int, name: string, slug: string, url: string}>
     */
    public function breadcrumbs(Category $category): array
    {
        $segments = [];

        return collect($this->ancestorsAndSelf($category))
            ->map(function (Category $category) use (&$segments): array {
                $segments[] = $category->slug;

                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'url' => '/catalog/'.implode('/', $segments).'/',
                ];
            })
            ->all();
    }

    /**
     * @return array<int, Category>
     */
    private function ancestorsAndSelf(Category $category): array
    {
        $categories = [$category];
        $parent = $category->parent;

        while ($parent instanceof Category) {
            array_unshift($categories, $parent);

            if (count($categories) > 3) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Category hierarchy supports up to three levels.'],
                ]);
            }

            $parent = $parent->parent;
        }

        return $categories;
    }

    /**
     * @return array<int, string>
     */
    private function pathSegments(string $path): array
    {
        $segments = collect(explode('/', trim($path, '/')))
            ->filter()
            ->values()
            ->all();

        if ($segments === [] || count($segments) > 3) {
            throw ValidationException::withMessages([
                'category_path' => ['Category path must contain from one to three levels.'],
            ]);
        }

        return $segments;
    }
}
