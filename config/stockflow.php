<?php

return [
    'runtime' => [
        'service_name' => env('STOCKFLOW_SERVICE_NAME', 'gateway'),
        'request_timeout_ms' => (int) env('STOCKFLOW_REQUEST_TIMEOUT_MS', 2500),
        'dependency_timeout_seconds' => (int) env('STOCKFLOW_DEPENDENCY_TIMEOUT_SECONDS', 2),
        'shutdown_timeout_seconds' => (int) env('STOCKFLOW_SHUTDOWN_TIMEOUT_SECONDS', 15),
    ],

    'dependencies' => [
        'rabbitmq' => [
            'host' => env('RABBITMQ_HOST', 'rabbitmq'),
            'port' => (int) env('RABBITMQ_PORT', 5672),
        ],
        'elasticsearch' => [
            'host' => env('ELASTICSEARCH_HOST', 'http://elasticsearch:9200'),
        ],
    ],

    'catalog' => [
        'cache' => [
            'product_ttl_seconds' => (int) env('STOCKFLOW_CATALOG_PRODUCT_CACHE_TTL', 300),
            'category_tree_ttl_seconds' => (int) env('STOCKFLOW_CATALOG_CATEGORY_TREE_CACHE_TTL', 900),
        ],
    ],

    'search' => [
        'indexing' => [
            'queue' => env('STOCKFLOW_SEARCH_INDEX_QUEUE', 'search-indexing'),
            'batch_size' => (int) env('STOCKFLOW_SEARCH_INDEX_BATCH_SIZE', 100),
            'max_in_flight' => (int) env('STOCKFLOW_SEARCH_INDEX_MAX_IN_FLIGHT', 500),
            'timeout_ms' => (int) env('STOCKFLOW_SEARCH_INDEX_TIMEOUT_MS', 1500),
        ],
    ],

    'messaging' => [
        'event_bus' => env('STOCKFLOW_EVENT_BUS', 'rabbitmq'),
        'retry' => [
            'max_attempts' => (int) env('STOCKFLOW_MESSAGE_RETRY_ATTEMPTS', 5),
            'backoff_ms' => (int) env('STOCKFLOW_MESSAGE_RETRY_BACKOFF_MS', 250),
            'dead_letter_after_attempts' => (int) env('STOCKFLOW_MESSAGE_DEAD_LETTER_AFTER', 5),
        ],
    ],
];
