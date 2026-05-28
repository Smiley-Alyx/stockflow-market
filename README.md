# StockFlow Market

StockFlow Market — pet-проект маркетплейса на Laravel и Vue.js. Цель проекта — показать реалистичный engineering case: каталог, остатки, заказы, поиск, асинхронные события, наблюдаемость и нагрузочные сценарии без лишней архитектурной имитации.

## Текущий этап

Создан базовый Laravel-проект и зафиксирована стартовая структура backend-кода под modular monolith:

```text
app/
  Application/
    Commands/
    DTO/
    Queries/
  Domains/
    Catalog/
    Inventory/
    Orders/
    Pricing/
    Search/
  Infrastructure/
    Cache/
    Messaging/
    Persistence/
    Search/
  Interfaces/
    Console/
    Http/
```

Пока директории пустые намеренно: доменная модель, миграции, API и интеграции будут добавляться маленькими этапами.

## Локальный запуск

Текущий минимальный запуск использует стандартные команды Laravel:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan serve
```

Полноценный запуск через Docker Compose с PostgreSQL, Redis, RabbitMQ, Elasticsearch, Prometheus и Grafana будет добавлен отдельным этапом.

## Проверки

```bash
composer test
```

Если локальный PHP не содержит SQLite-драйвер, миграции SQLite из стандартного post-install шага Laravel могут завершиться предупреждением. На следующих этапах проект будет переведён на PostgreSQL в Docker.

## Ближайшие шаги

1. Добавить Docker Compose для Laravel, PostgreSQL, Redis, RabbitMQ и Elasticsearch.
2. Описать первое ADR по выбору modular monolith.
3. Реализовать базовую доменную модель Catalog и миграции.
