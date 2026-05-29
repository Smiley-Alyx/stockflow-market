<?php

namespace App\Domains\Search\Contracts;

interface ProductSearch
{
    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int|string>}
     */
    public function search(string $query, int $page, int $perPage): array;
}
