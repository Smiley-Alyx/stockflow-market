<?php

namespace App\Domains\Search\Contracts;

interface BulkSearchIndexer
{
    /**
     * @param  array<string, array<string, mixed>>  $documents
     */
    public function indexMany(string $index, array $documents): void;
}
