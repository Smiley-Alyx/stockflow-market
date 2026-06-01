<?php

return [
    'runtime' => [
        'service_name' => env('STOCKFLOW_SERVICE_NAME', 'gateway'),
        'request_timeout_ms' => (int) env('STOCKFLOW_REQUEST_TIMEOUT_MS', 2500),
        'dependency_timeout_seconds' => (int) env('STOCKFLOW_DEPENDENCY_TIMEOUT_SECONDS', 2),
        'shutdown_timeout_seconds' => (int) env('STOCKFLOW_SHUTDOWN_TIMEOUT_SECONDS', 15),
    ],

    'rate_limits' => [
        'checkout' => [
            'max_attempts' => (int) env('STOCKFLOW_CHECKOUT_RATE_LIMIT_ATTEMPTS', 30),
            'decay_seconds' => (int) env('STOCKFLOW_CHECKOUT_RATE_LIMIT_DECAY_SECONDS', 60),
        ],
        'search' => [
            'max_attempts' => (int) env('STOCKFLOW_SEARCH_RATE_LIMIT_ATTEMPTS', 120),
            'decay_seconds' => (int) env('STOCKFLOW_SEARCH_RATE_LIMIT_DECAY_SECONDS', 60),
        ],
        'catalog' => [
            'max_attempts' => (int) env('STOCKFLOW_CATALOG_RATE_LIMIT_ATTEMPTS', 300),
            'decay_seconds' => (int) env('STOCKFLOW_CATALOG_RATE_LIMIT_DECAY_SECONDS', 60),
        ],
    ],

    'dependencies' => [
        'rabbitmq' => [
            'enabled' => (bool) env('RABBITMQ_ENABLED', false),
            'host' => env('RABBITMQ_HOST', 'rabbitmq'),
            'port' => (int) env('RABBITMQ_PORT', 5672),
            'critical' => (bool) env('RABBITMQ_CRITICAL', false),
        ],
        'elasticsearch' => [
            'host' => env('ELASTICSEARCH_HOST', 'http://elasticsearch:9200'),
            'critical' => (bool) env('ELASTICSEARCH_CRITICAL', false),
        ],
        'clickhouse' => [
            'enabled' => (bool) env('CLICKHOUSE_ENABLED', false),
            'host' => env('CLICKHOUSE_HOST', 'http://clickhouse:8123'),
            'native_port' => (int) env('CLICKHOUSE_NATIVE_PORT', 9000),
            'database' => env('CLICKHOUSE_DATABASE', 'stockflow'),
            'username' => env('CLICKHOUSE_USERNAME', 'stockflow'),
            'password' => env('CLICKHOUSE_PASSWORD', 'secret'),
            'critical' => (bool) env('CLICKHOUSE_CRITICAL', false),
        ],
    ],

    'circuit_breakers' => [
        'rabbitmq' => [
            'failure_threshold' => (int) env('RABBITMQ_CIRCUIT_BREAKER_FAILURE_THRESHOLD', 3),
            'failure_window_seconds' => (int) env('RABBITMQ_CIRCUIT_BREAKER_FAILURE_WINDOW_SECONDS', 60),
            'open_seconds' => (int) env('RABBITMQ_CIRCUIT_BREAKER_OPEN_SECONDS', 30),
        ],
        'elasticsearch' => [
            'failure_threshold' => (int) env('ELASTICSEARCH_CIRCUIT_BREAKER_FAILURE_THRESHOLD', 3),
            'failure_window_seconds' => (int) env('ELASTICSEARCH_CIRCUIT_BREAKER_FAILURE_WINDOW_SECONDS', 60),
            'open_seconds' => (int) env('ELASTICSEARCH_CIRCUIT_BREAKER_OPEN_SECONDS', 30),
        ],
    ],

    'catalog' => [
        'cache' => [
            'product_ttl_seconds' => (int) env('STOCKFLOW_CATALOG_PRODUCT_CACHE_TTL', 300),
            'category_tree_ttl_seconds' => (int) env('STOCKFLOW_CATALOG_CATEGORY_TREE_CACHE_TTL', 900),
        ],
    ],

    'inventory' => [
        'stock_movements' => [
            'retention_days' => (int) env('STOCKFLOW_STOCK_MOVEMENT_RETENTION_DAYS', 180),
            'archive_batch_size' => (int) env('STOCKFLOW_STOCK_MOVEMENT_ARCHIVE_BATCH_SIZE', 500),
        ],
    ],

    'provider_saga' => [
        'enabled' => (bool) env('STOCKFLOW_PROVIDER_SAGA_ENABLED', false),
        'payment_token' => env('STOCKFLOW_PROVIDER_SAGA_PAYMENT_TOKEN', 'tok_approved_visa'),
        'outbox' => [
            'processing_timeout_seconds' => (int) env('STOCKFLOW_PROVIDER_OUTBOX_PROCESSING_TIMEOUT_SECONDS', 60),
            'publisher_confirm_timeout_seconds' => (int) env('STOCKFLOW_PROVIDER_OUTBOX_CONFIRM_TIMEOUT_SECONDS', 5),
        ],
        'rabbitmq' => [
            'host' => env('RABBITMQ_HOST', 'rabbitmq'),
            'port' => (int) env('RABBITMQ_PORT', 5672),
            'user' => env('RABBITMQ_USER', 'stockflow'),
            'password' => env('RABBITMQ_PASSWORD', 'secret'),
            'vhost' => env('RABBITMQ_VHOST', '/'),
            'outcomes_queue' => env('STOCKFLOW_PROVIDER_OUTCOMES_QUEUE', 'stockflow.market.provider.outcomes'),
            'outcomes_retry_queue' => env('STOCKFLOW_PROVIDER_OUTCOMES_RETRY_QUEUE', 'stockflow.market.provider.outcomes.retry'),
            'outcomes_dead_letter_queue' => env('STOCKFLOW_PROVIDER_OUTCOMES_DLQ', 'stockflow.market.provider.outcomes.dlq'),
            'outcomes_retry_delay_ms' => (int) env('STOCKFLOW_PROVIDER_OUTCOMES_RETRY_DELAY_MS', 2000),
            'outcomes_max_retry_count' => (int) env('STOCKFLOW_PROVIDER_OUTCOMES_MAX_RETRY_COUNT', 3),
        ],
    ],

    'search' => [
        'indexing' => [
            'queue' => env('STOCKFLOW_SEARCH_INDEX_QUEUE', 'search-indexing'),
            'dead_letter_queue' => env('STOCKFLOW_SEARCH_INDEX_DEAD_LETTER_QUEUE', 'search-indexing-dead-letter'),
            'dead_letter_backend' => env('STOCKFLOW_SEARCH_INDEX_DEAD_LETTER_BACKEND', 'redis'),
            'dead_letter_redis_connection' => env('STOCKFLOW_SEARCH_INDEX_DEAD_LETTER_REDIS_CONNECTION', 'default'),
            'dead_letter_redis_key' => env('STOCKFLOW_SEARCH_INDEX_DEAD_LETTER_REDIS_KEY', 'stockflow:search:dead-letter'),
            'requeue_audit_channel' => env('STOCKFLOW_SEARCH_REQUEUE_AUDIT_CHANNEL', 'search_requeue_audit'),
            'requeue_batch_size' => (int) env('STOCKFLOW_SEARCH_REQUEUE_BATCH_SIZE', 100),
            'max_requeue_batch_size' => (int) env('STOCKFLOW_SEARCH_MAX_REQUEUE_BATCH_SIZE', 500),
            'batch_size' => (int) env('STOCKFLOW_SEARCH_INDEX_BATCH_SIZE', 100),
            'max_in_flight' => (int) env('STOCKFLOW_SEARCH_INDEX_MAX_IN_FLIGHT', 500),
            'timeout_ms' => (int) env('STOCKFLOW_SEARCH_INDEX_TIMEOUT_MS', 1500),
        ],
    ],

    'messaging' => [
        'event_bus' => env('STOCKFLOW_EVENT_BUS', 'in_process'),
        'inbox' => [
            'processing_timeout_seconds' => (int) env('STOCKFLOW_INBOX_PROCESSING_TIMEOUT_SECONDS', 60),
        ],
        'retry' => [
            'max_attempts' => (int) env('STOCKFLOW_MESSAGE_RETRY_ATTEMPTS', 5),
            'backoff_ms' => (int) env('STOCKFLOW_MESSAGE_RETRY_BACKOFF_MS', 250),
            'dead_letter_after_attempts' => (int) env('STOCKFLOW_MESSAGE_DEAD_LETTER_AFTER', 5),
        ],
    ],
];
