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

## What the operator sees

- **Dashboard:** stock per product, an alerts panel (tanks near full / low, vessels past ETA or discharging too long, open deliveries), a 14-day inventory chart, live tank gauges and the latest movements. Everything refreshes by itself.
- **Operations → Vessel discharges:** voyage lifecycle `scheduled → berthed → discharging → completed` (or cancelled). Pumping into a tank is only possible while *discharging*, only into a tank holding the same product, and never beyond the **bill of lading**. The page reconciles B/L vs received vs transit loss.
- **Operations → Delivery orders:** `pending → loading → completed`. Loading is limited to the ordered quantity and to what the tank actually holds.
- **Inventory → Tanks / Cargo movements:** per-tank page with a balance-history chart and its ledger lines. Movements stay an immutable ledger, each linked to the vessel or order that caused it.

- **Operations → Corrections:** the ledger is immutable, so a mistake is fixed by a **reversal movement**. An operator requests it (with a reason), and a *different* supervisor approves (four-eyes rule). The reversal mirrors the original exactly, restores the tank, the bill of lading / order balance and the transit-loss figure, and can be applied only once. If the tank could not legally take it back (for example the cargo has since been delivered), nothing changes and the request stays open.
- **Administration → Audit log:** an append-only record of who changed what (before/after values, role, time, IP). Passwords are never logged; the fact that one changed is.
- **Administration → Users & roles** (admin only).

Every status change and movement goes through a domain action; Filament only calls them and shows the result.

### Roles

| Role | Can do |
| --- | --- |
| **Admin** | everything, including users |
| **Supervisor** | operate, schedule vessels / orders, edit tanks, approve or reject corrections, read the audit log |
| **Operator** | operate (record movements, advance vessels and orders), *request* corrections |
| **Auditor** | read-only everywhere, plus the audit log |

Enforced in three places: Laravel policies (screens), action authorisation (buttons), and the domain actions themselves for approvals (role and four-eyes checks cannot be bypassed from another entry point). Operational data can never be deleted.

Demo logins (all share `ADMIN_PASSWORD`): `admin@cargo.test`, `supervisor@cargo.test`, `operator@cargo.test`, `auditor@cargo.test`.

## How the four domain rules are met

| Rule | Where | How |
| --- | --- | --- |
| **Capacity boundaries** | `TankLedger::replay` | Any level above capacity throws `TankCapacityExceededException`; the insert is rolled back. Dashboard shows the message as a notification. |
| **0.5% transit loss** | `TransitLossCalculator` | Applied to inbound vessel flows only, in integer kg (half-up). Stored per movement (`gross_kg`, `loss_kg`, `net_kg`) so it is auditable. |
| **Out-of-order logs** | `TankLedger::replay`, `IngestMovements` | The ledger is append-only. A tank's balance is *always* recomputed by replaying every movement sorted by `occurred_at`, so arrival order is irrelevant. A late record that would make the timeline impossible (negative stock / overflow at any point) is rejected. |
| **Atomic operations** | `TankLedger::record` | One DB transaction with a `SELECT … FOR UPDATE` row lock on the tank, plus a unique `idempotency_key` so duplicates/retries apply once. |

A correction is protected by four independent layers: the request row is locked, the tank row is locked, the ledger refuses a second reversal, and the reversal has a fixed idempotency key on a unique column, so a double reversal is impossible even if the application layers were removed.

Operations add a second lock level with a fixed order (**vessel/order row, then tank**) so concurrent flows can never deadlock, and the B/L / order caps are enforced under that lock.

Layers: `app/Domain/Cargo`, `app/Domain/Operations` (enums, exceptions, services, actions — no Filament inside) →
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

- `php artisan test` — 76 pass, 5 concurrency tests skipped (SQLite cannot lock rows). Covers the four rules, both lifecycles, every Filament screen, and the real buttons through Filament's test helpers.
- Real concurrency on Postgres (20 parallel processes each): a tank that fits 10 discharges, a bill of lading that fits 10 pumps, an order that fits 6 loads, 12 duplicate submissions, and two supervisors approving the same correction at once:
  ```bash
  DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=cargo_test DB_USERNAME=postgres DB_PASSWORD=... \
    php artisan test --filter Concurrency
  ```
  Verified by negative control: with each row lock removed the matching test fails (11 pumps fit a 10-pump bill of lading; 8 loads fit a 6-load order).

## Deploy (Render)

1. Push this repo to GitHub.
2. Render → **New → Blueprint** → select the repo (uses `render.yaml`: Docker web service + free Postgres).
3. Set the prompted secrets: `APP_KEY` (run `php artisan key:generate --show`), `ADMIN_PASSWORD`.
4. Open the service URL → `/admin`, log in with `ADMIN_EMAIL` / `ADMIN_PASSWORD`.

Production uses **Postgres** because the locking guarantee needs a real database server.
