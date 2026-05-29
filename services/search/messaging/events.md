# События поиска

- `search.index.requested` — запрос на обновление поискового документа.
- `search.index.deletion_requested` — запрос на удаление поискового документа.
- `search.index.completed` — поисковый документ записан в индекс или удалён из него.
- `search.index.failed` — поисковый документ ушёл в dead-letter после исчерпания retry-порога.
