<?php

namespace App\Domains\Search\Contracts;

interface SearchIndexer
{
    /**
     * @param  array<string, mixed>  $document
     */
    public function index(string $index, string $documentId, array $document): void;

    public function delete(string $index, string $documentId): void;
}
