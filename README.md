# Cargo Handling System

Bulk palm-oil terminal tracker (CPO, CPOL, PFAD): **vessel discharge → shore tanks → truck delivery**.
Laravel 13 + FilamentPHP 5. Operator dashboard at `/admin`.

## Run locally

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve          # http://localhost:8000  ->  admin@cargo.test / password
php artisan test
```

## How the four domain rules are met

| Rule | Where | How |
| --- | --- | --- |
| **Capacity boundaries** | `TankLedger::replay` | Any level above capacity throws `TankCapacityExceededException`; the insert is rolled back. Dashboard shows the message as a notification. |
| **0.5% transit loss** | `TransitLossCalculator` | Applied to inbound vessel flows only, in integer kg (half-up). Stored per movement (`gross_kg`, `loss_kg`, `net_kg`) so it is auditable. |
| **Out-of-order logs** | `TankLedger::replay`, `IngestMovements` | The ledger is append-only. A tank's balance is *always* recomputed by replaying every movement sorted by `occurred_at`, so arrival order is irrelevant. A late record that would make the timeline impossible (negative stock / overflow at any point) is rejected. |
| **Atomic operations** | `TankLedger::record` | One DB transaction with a `SELECT … FOR UPDATE` row lock on the tank, plus a unique `idempotency_key` so duplicates/retries apply once. |

Layers: `app/Domain/Cargo` (enums, exceptions, services, actions — no Filament inside) →
`app/Filament` (thin UI that only calls the actions) → `app/Observers` (state hook: logs when a tank crosses 90%).

## Assumptions (not stated in the brief)
- Quantities are whole **kg**; the 0.5% loss rounds half-up to the nearest kg.
- A tank holds **one commodity**; discharging a different product into it is refused.
- Loss applies to **inbound only**; truck deliveries are recorded at face value.
- Movements are an **immutable ledger** (no edit/delete); corrections are new movements.
- CPO needs temperature control: modelled as a `requires_heating` flag on the tank, not live telemetry.

## Message-pipeline endpoint

```bash
curl -X POST $URL/api/movements/ingest -H "Authorization: Bearer $INGEST_TOKEN" -H 'Content-Type: application/json' -d '{
  "records": [
    {"tank":"CPOL-01","type":"truck_delivery","quantity_kg":30000,"occurred_at":"2026-11-05T10:00:00Z","idempotency_key":"k2"},
    {"tank":"CPOL-01","type":"vessel_discharge","quantity_kg":100000,"occurred_at":"2026-11-05T08:00:00Z","idempotency_key":"k1"}
  ]}'
```
Records may be in any order; each gets `applied` or `rejected` (with reason). Re-sending a batch changes nothing.

## Tests

- `php artisan test` — 15 pass + 2 concurrency tests skipped (SQLite can't lock rows).
- Real concurrency (20 parallel processes racing for one tank, 12 duplicate submissions):
  ```bash
  DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=cargo_test DB_USERNAME=postgres DB_PASSWORD=... \
    php artisan test --filter Concurrency
  ```
  Verified: exactly 10 of 20 fit; with the row lock removed the test fails (11 fit), proving the lock is what protects capacity.

## Deploy (Render)

1. Push this repo to GitHub.
2. Render → **New → Blueprint** → select the repo (uses `render.yaml`: Docker web service + free Postgres).
3. Set the prompted secrets: `APP_KEY` (run `php artisan key:generate --show`), `ADMIN_PASSWORD`.
4. Open the service URL → `/admin`, log in with `ADMIN_EMAIL` / `ADMIN_PASSWORD`.

Production uses **Postgres** because the locking guarantee needs a real database server.
