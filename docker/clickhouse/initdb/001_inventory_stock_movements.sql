CREATE TABLE IF NOT EXISTS stockflow.inventory_stock_movements
(
    movement_id UInt64,
    stock_item_id UInt64,
    warehouse_id UInt64,
    product_id UInt64,
    sku LowCardinality(String),
    reservation_id Nullable(UInt64),
    movement_type LowCardinality(String),
    quantity UInt64,
    reference_type Nullable(String),
    reference_id Nullable(String),
    metadata Nullable(String),
    occurred_at DateTime64(3, 'UTC'),
    ingested_at DateTime64(3, 'UTC') DEFAULT now64(3)
)
ENGINE = ReplacingMergeTree(ingested_at)
PARTITION BY toYYYYMM(occurred_at)
ORDER BY (stock_item_id, occurred_at, movement_id);
