# StockFlow Market

StockFlow Market — инженерный pet-проект маркетплейса с микросервисным контуром вокруг Laravel, PostgreSQL, Redis, RabbitMQ, Elasticsearch и Vite frontend. Проект развивается как реалистичный backend case: каталог, остатки, заказы, цены, поиск, асинхронные события и локальная инфраструктура без лишней имитации enterprise-слоя.

## Текущий статус

Сейчас проект находится на этапе закладки фундамента:

- поднят Laravel backend shell, который временно выполняет роль API gateway;
- добавлен Docker Compose для локального запуска инфраструктуры;
- заведён `services/` workspace под будущие сервисы;
- зафиксированы первые OpenAPI-заготовки и события по доменам;
- реализована базовая модель Catalog: категории, товары и атрибуты;
- добавлена внутренняя публикация `catalog.product.created`;
- добавлен обработчик, который превращает создание товара в `search.index.requested`;
- добавлен первый async job pipeline для индексации поисковых документов;
- добавлены Docker Compose worker-процессы для общей очереди и поисковой индексации;
- добавлены `health/live` и `health/ready` probes для runtime и зависимостей;
- подключён Elasticsearch adapter для записи поисковых документов;
- добавлен `config/stockflow.php` для runtime-настроек таймаутов, кеша, очередей, retry и backpressure limits;
- описан первый ADR по переходной архитектуре Laravel gateway + service workspace;
- добавлен архитектурный тест, который проверяет наличие сервисной структуры.

## Архитектура

Целевое направление — микросервисный marketplace, где каждый сервис владеет своей моделью, контрактами и схемой данных. На раннем этапе реализация остаётся в одном репозитории, чтобы быстрее развивать сценарии и не платить стоимость распределённой системы до появления реальной доменной нагрузки.

```text
stockflow-market/
  app/                    # Laravel backend shell / gateway на текущем этапе
  services/
    gateway/              # внешний API слой
    catalog/              # товары, категории, атрибуты
    inventory/            # остатки, резервы, складские движения
    orders/               # корзина и жизненный цикл заказа
    pricing/              # цены, промо-правила, расчёты
    search/               # Elasticsearch индексы и read-модели
  docker/
    php/                  # локальный PHP runtime
  compose.yaml            # локальный стек разработки
```

Каждый доменный сервис уже содержит одинаковые рабочие зоны:

- `contracts/` — OpenAPI и внешние контракты;
- `database/migrations/` — будущая схема данных сервиса;
- `messaging/` — входящие и исходящие события;
- `src/` — прикладной и доменный код;
- `tests/` — модульные, контрактные и интеграционные проверки.

## Локальная инфраструктура

Docker Compose поднимает:

| Сервис | Назначение | Локальный адрес |
| --- | --- | --- |
| `php` | Laravel backend shell | `http://localhost:8080` |
| `frontend` | Vite dev server | `http://localhost:5173` |
| `postgres` | основная реляционная БД | `localhost:5432` |
| `redis` | кеш, сессии, очереди | `localhost:6379` |
| `rabbitmq` | брокер доменных событий | `localhost:5672`, UI `http://localhost:15672` |
| `elasticsearch` | поисковый движок | `http://localhost:9200` |

Дефолтные локальные креды:

```text
PostgreSQL: stockflow / secret
RabbitMQ:   stockflow / secret
```

## Быстрый старт

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec php composer install
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate
```

После запуска:

- backend доступен на `http://localhost:8080`;
- frontend доступен на `http://localhost:5173`;
- RabbitMQ Management UI доступен на `http://localhost:15672`.

## Локальные команды

```bash
composer test
docker compose config
docker compose ps
docker compose logs -f php
docker compose down
```

## Runtime-настройки

Проектные highload-настройки собраны в `config/stockflow.php`, чтобы прикладной код не хардкодил операционные лимиты. Значения переопределяются через `STOCKFLOW_*` переменные в `.env`.

| Группа | Назначение |
| --- | --- |
| `runtime` | имя сервиса, общий request timeout, graceful shutdown budget |
| `catalog.cache` | TTL кеша товаров и дерева категорий |
| `search.indexing` | очередь индексации, batch size, max in-flight, timeout Elasticsearch |
| `messaging.retry` | retry attempts, backoff и порог dead-letter |

Эти настройки пока являются контрактом для ближайших этапов: Redis caching, Elasticsearch adapter, retries и backpressure. Очередь `search-indexing` уже используется job pipeline для поисковой индексации.

## Проверки

Основная проверка на текущем этапе:

```bash
composer test
```

Тесты пока лёгкие и намеренно инфраструктурные: они страхуют базовый Laravel bootstrap, наличие сервисной структуры, модель каталога и первый внутренний событийный поток. По мере появления бизнес-сценариев сюда будут добавляться контрактные тесты, feature-тесты API и интеграционные проверки событий.

## Инженерные решения

- Репозиторий остаётся monorepo, пока сервисы находятся в активной фазе проектирования.
- Переходная архитектура зафиксирована в `docs/adr/0001-laravel-gateway-service-workspace.md`.
- Runtime-настройки зафиксированы в `docs/adr/0002-runtime-configuration-boundaries.md`.
- PostgreSQL выбран как основное хранилище для транзакционных данных.
- Redis используется для кеша, сессий и быстрых очередей локального контура.
- RabbitMQ зарезервирован под доменные события между сервисами.
- Elasticsearch выделен под поисковые read-модели и индексацию каталога.
- Контракты сервисов описываются до реализации публичных API.

## Ближайший план

1. Добавить Redis caching для чтения каталога и дерева категорий.
2. Описать contract tests для первых gateway/catalog API сценариев.
3. Подготовить retry/dead-letter поведение для событийной доставки.

## Лицензия

MIT.
