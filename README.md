# StockFlow Market

StockFlow Market — инженерный pet-проект маркетплейса с микросервисным контуром вокруг Laravel, PostgreSQL, Redis, RabbitMQ, Elasticsearch и Vite frontend. Проект развивается как реалистичный backend case: каталог, остатки, заказы, цены, поиск, асинхронные события и локальная инфраструктура без лишней имитации enterprise-слоя.

## Текущий статус

Сейчас проект находится на этапе закладки фундамента:

- поднят Laravel backend shell, который временно выполняет роль API gateway;
- добавлен Docker Compose для локального запуска инфраструктуры;
- заведён `services/` workspace под будущие сервисы;
- зафиксированы первые OpenAPI-заготовки и события по доменам;
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

## Проверки

Основная проверка на текущем этапе:

```bash
composer test
```

Тесты пока лёгкие и намеренно инфраструктурные: они страхуют базовый Laravel bootstrap и наличие сервисной структуры. По мере появления бизнес-сценариев сюда будут добавляться контрактные тесты, feature-тесты API и интеграционные проверки событий.

## Инженерные решения

- Репозиторий остаётся monorepo, пока сервисы находятся в активной фазе проектирования.
- Переходная архитектура зафиксирована в `docs/adr/0001-laravel-gateway-service-workspace.md`.
- PostgreSQL выбран как основное хранилище для транзакционных данных.
- Redis используется для кеша, сессий и быстрых очередей локального контура.
- RabbitMQ зарезервирован под доменные события между сервисами.
- Elasticsearch выделен под поисковые read-модели и индексацию каталога.
- Контракты сервисов описываются до реализации публичных API.

## Ближайший план

1. Реализовать базовую модель Catalog: товары, категории, атрибуты.
2. Добавить публикацию события `catalog.product.created`.
3. Подключить обработчик индексации в Search Service.
4. Расширить Docker Compose worker-процессами для очередей и событий.

## Лицензия

MIT.
