# Сквозной checkout flow

## Happy path

Целевая последовательность checkout:
`reserve stock → authorize payment → create order → capture payment → create shipment`.

```mermaid
sequenceDiagram
    actor Client
    participant Market as stockflow-market
    participant ERP as stockflow-erp-mock
    participant PSP as stockflow-payment-mock
    participant Delivery as stockflow-delivery-mock

    Client->>Market: confirm checkout
    Market->>ERP: inventory.reservation.requested.v1
    ERP-->>Market: inventory.reservation.confirmed.v1
    Market->>PSP: payment.authorization.requested.v1
    PSP-->>Market: payment.authorization.approved.v1
    Market->>Market: create confirmed order
    Market->>PSP: payment.capture.requested.v1
    PSP-->>Market: payment.capture.completed.v1
    Market->>Delivery: delivery.shipment.requested.v1
    Delivery-->>Market: delivery.shipment.created.v1
    Delivery-->>Market: delivery.shipment.status_changed.v1
    Market-->>Client: order accepted, shipment created
```

Все сообщения одного checkout сохраняют одинаковый `correlation_id`.
`causation_id` связывает каждый outcome с конкретным request.

## Компенсации

```mermaid
flowchart TD
    reserve["Reserve stock"] --> auth["Authorize payment"]
    auth --> order["Create order"]
    order --> capture["Capture payment"]
    capture --> shipment["Create shipment"]

    auth -. "declined" .-> release["Release stock"]
    order -. "failed" .-> voidAuth["Cancel authorization"]
    voidAuth --> release
    capture -. "failed" .-> release
    shipment -. "failed" .-> refund["Refund payment"]
    refund --> release
```

Payment mock сейчас поддерживает refund, но отдельного message-контракта void для
не captured authorization нет. До реализации market-orchestrator это нужно
зафиксировать как контрактное решение: добавить void/cancel authorization либо
использовать bounded authorization TTL на стороне PSP.

## Routing keys

| Шаг | Request | Успешный outcome | Негативный outcome |
| --- | --- | --- | --- |
| Reserve stock | `inventory.reservation.requested.v1` | `inventory.reservation.confirmed.v1` | `inventory.reservation.rejected.v1` |
| Release stock | `inventory.reservation.release.requested.v1` | `inventory.reservation.released.v1` | `inventory.reservation.release_failed.v1` |
| Authorize payment | `payment.authorization.requested.v1` | `payment.authorization.approved.v1` | `payment.authorization.declined.v1` |
| Capture payment | `payment.capture.requested.v1` | `payment.capture.completed.v1` | `payment.capture.failed.v1` |
| Refund payment | `payment.refund.requested.v1` | `payment.refund.completed.v1` | `payment.refund.failed.v1` |
| Create shipment | `delivery.shipment.requested.v1` | `delivery.shipment.created.v1` | `delivery.shipment.creation_failed.v1` |
| Cancel shipment | `delivery.shipment.cancel_requested.v1` | `delivery.shipment.cancelled.v1` | `delivery.shipment.cancel_failed.v1` |

## Saga state

Рекомендуемый минимальный state machine market-orchestrator:

```mermaid
stateDiagram-v2
    [*] --> ReservingInventory
    ReservingInventory --> AuthorizingPayment: inventory confirmed
    ReservingInventory --> Cancelled: inventory rejected
    AuthorizingPayment --> CreatingOrder: authorization approved
    AuthorizingPayment --> ReleasingInventory: authorization declined
    CreatingOrder --> CapturingPayment: order persisted
    CapturingPayment --> CreatingShipment: capture completed
    CapturingPayment --> ReleasingInventory: capture failed
    CreatingShipment --> FulfillmentPending: shipment created
    CreatingShipment --> RefundingPayment: shipment failed
    RefundingPayment --> ReleasingInventory: refund completed
    ReleasingInventory --> Cancelled: inventory released
    FulfillmentPending --> [*]
    Cancelled --> [*]
```

Saga transition должен быть idempotent: повторная доставка outcome не меняет
state повторно и не создаёт новый provider request.
