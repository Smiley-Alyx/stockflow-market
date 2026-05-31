<?php

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Models\Category;
use Illuminate\Validation\ValidationException;

class CatalogUrlService
{
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
