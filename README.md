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

- **Dashboard:** stock per product, live tank gauges, *Vessels in port* and *Trucks on site* boards, and (for managers and auditors) a 14-day inventory chart and the latest movements. It refreshes by itself.
- **The bell (top bar):** what needs *your* attention right now, each item linked to the right screen, with a count badge. Only things the person can act on are listed (tanks near full or low, ships late, trucks on site with warnings, overstays; approvers also see corrections awaiting approval, and managers see documents to chase). Normal progress is not an alert.
- **Operations → Vessel discharges:** voyage lifecycle `scheduled → berthed → discharging → completed` (or cancelled). Pumping into a tank is only possible while *discharging*, only into a tank holding the same product, and never beyond the **bill of lading**. The page reconciles B/L vs received vs transit loss.
- **Operations → Delivery orders:** `pending → loading → completed`. Loading is limited to the ordered quantity and to what the tank actually holds.
- **Inventory → Tanks / Cargo movements:** per-tank page with a balance-history chart and its ledger lines. Movements stay an immutable ledger, each linked to the vessel or order that caused it.

- **Operations → Corrections:** the ledger is immutable, so a mistake is fixed by a **reversal movement**. An operator requests it (with a reason), and a *different* supervisor approves (four-eyes rule). The reversal mirrors the original exactly, restores the tank, the bill of lading / order balance and the transit-loss figure, and can be applied only once. If the tank could not legally take it back (for example the cargo has since been delivered), nothing changes and the request stays open.
- **Administration → Audit log:** an append-only record of who changed what (before/after values, role, time, IP). Passwords are never logged; the fact that one changed is.
- **Registers:** vessels (with IMO number), berths, customers, transporters, trucks and drivers, each with create / view / edit and **deactivate** (never delete, because history refers to them). Voyages and orders are scheduled by *picking from the register*, so a ship or customer is one record, not text typed differently each time. Trucks and drivers carry document expiry dates (insurance, inspection, licence).
- **Tracking vessels:** a voyage can only be berthed at a **free, in-service berth**, and a ship cannot be alongside twice. The dashboard shows *Vessels in port* (berth, progress against the bill of lading), and each vessel's page says where it is right now and lists all its voyages.
- **Operations → Truck visits:** a truck's journey on site: `arrived → weighed in → loading → weighed out → departed` (or cancelled before loading). Cargo is booked out of a tank **against the visit**, so every load names the truck, driver and order. Rules: one open visit per truck and per driver; a load cannot exceed the truck's **payload**; an order cannot be closed while a truck is still on site for it; the weighbridge net is compared with the ledger and differences are flagged.
- **Documents warn, they never block:** expired, expiring (30 days) or unrecorded truck/driver documents are recorded on the visit, shown at the gate and on the dashboard, and the truck is still admitted (a deliberate policy decision).
- **Reports → Terminal reports:** stock position per product for any period (opening, discharged, transit loss, delivered, closing, with a "reconciles" check against the ledger), vessel reconciliation (bill of lading vs received) and delivery fulfilment. Each exports to **CSV** (spreadsheet formulas are neutralised) and all three to one **PDF**.
- **Administration → Users & roles** (admin only).

Every status change and movement goes through a domain action; Filament only calls them and shows the result.

### Roles: a simpler product for each person

Each role gets only the screens it needs. The same rules hide the menu item **and** refuse the direct URL.

| Role | Menu | Can do |
| --- | --- | --- |
| **Operator** | Operations (vessels, orders, truck visits), Stock (tanks, movements): 6 items | record movements, advance vessels and orders, gate trucks, *request* corrections. Live dashboard boards and the bell |
| **Supervisor** | the above + Corrections, Terminal reports, Registers (collapsed), Audit log: 15 items | everything an operator does, plus schedule vessels and orders, edit tanks and registers, approve or reject corrections |
| **Auditor** | Operations and Stock (read-only), Corrections, Terminal reports, Audit log: 9 items | observe only; no action buttons, no bell |
| **Admin** | everything, including Users & roles: 16 items | everything |

The menu is grouped Operations, Stock, Reports, Registers and Administration; the last two start collapsed.

Enforced in three places: Laravel policies (screens), action authorisation (buttons), and the domain actions themselves for approvals (role and four-eyes checks cannot be bypassed from another entry point). Operational data can never be deleted.

Demo logins (all share `ADMIN_PASSWORD`): `admin@cargo.test`, `supervisor@cargo.test`, `operator@cargo.test`, `auditor@cargo.test`.

## How the four domain rules are met

| Rule | Where | How |
| --- | --- | --- |
| **Capacity boundaries** | `TankLedger::replay` | Any level above capacity throws `TankCapacityExceededException`; the insert is rolled back. Dashboard shows the message as a notification. |
| **0.5% transit loss** | `TransitLossCalculator` | Applied to inbound vessel flows only, in integer kg (half-up). Stored per movement (`gross_kg`, `loss_kg`, `net_kg`) so it is auditable. |
| **Out-of-order logs** | `TankLedger::replay`, `IngestMovements` | The ledger is append-only. A tank's balance is *always* recomputed by replaying every movement sorted by `occurred_at`, so arrival order is irrelevant. A late record that would make the timeline impossible (negative stock / overflow at any point) is rejected. |
| **Atomic operations** | `TankLedger::record` | One DB transaction with a `SELECT … FOR UPDATE` row lock on the tank, plus a unique `idempotency_key` so duplicates/retries apply once. |

Several guarantees are enforced **by the database itself**, not only by application code: a UNIQUE column holds a truck's id only while its visit is open (one open visit per truck), and the same technique guards "one ship per berth, one berth per ship". Each is also checked with row locks in the application for clear error messages.

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

- `php artisan test` — 217 pass, 8 concurrency tests skipped on SQLite (it cannot lock rows). On PostgreSQL all **225 pass** with nothing skipped. Covers the four rules, both lifecycles, every Filament screen, and the real buttons through Filament's test helpers. `tests/Feature/Roles/` is the role-coverage suite: every route per role, every policy ability per role (336 decisions), exact menus, a link crawler, action buttons per record status, crafted-request refusals, and empty/edge-state rendering.
- Real concurrency on Postgres (parallel processes; the CI job runs these too): a tank that fits 10 discharges, a bill of lading that fits 10 pumps, an order that fits 6 loads, 12 duplicate submissions, two supervisors approving the same correction at once, twelve gates admitting one truck, loads racing for one truck's payload, and two ships racing for one berth:
  ```bash
  DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=cargo_test DB_USERNAME=postgres DB_PASSWORD=... \
    php artisan test --filter Concurrency
  ```
  Verified by negative control: with each row lock removed the matching test fails (11 pumps fit a 10-pump bill of lading; 8 loads fit a 6-load order).

## Upgrading an existing database

The migrations are additive. Voyages and orders created before the registers existed keep working: a backfill builds a vessel / customer register entry from each typed name (the same ship typed two ways becomes one entry) and links the records. Ships already alongside are marked in the guard columns. The seeder is idempotent and enriches those entries (IMO numbers, contacts) without duplicating them.

## Continuous integration

`.github/workflows/ci.yml` runs on every push and pull request:

| Job | What it checks |
| --- | --- |
| **Code style** | Laravel Pint (`vendor/bin/pint --test`), blocking |
| **Tests (SQLite)** | the full suite, blocking |
| **Tests + real concurrency (PostgreSQL 16)** | the same suite with the 5 parallel-process tests *enabled*, blocking |
| **Static analysis** | PHPStan + Larastan (`phpstan.neon`, level 5), **advisory for now**: it has not been run locally yet, so it should be promoted to blocking after its first green run |

## Deploy (Render)

1. Push this repo to GitHub.
2. Render → **New → Blueprint** → select the repo (uses `render.yaml`: Docker web service + free Postgres).
3. Set the prompted secrets: `APP_KEY` (run `php artisan key:generate --show`), `ADMIN_PASSWORD`.
4. Open the service URL → `/admin`, log in with `ADMIN_EMAIL` / `ADMIN_PASSWORD`.

Production uses **Postgres** because the locking guarantee needs a real database server.

## Branding

The interface follows the Bulkstream identity: logo from the assessment brief (`public/images/bulkstream-logo*.png`), ink `#153037` / `#1B2F36`, paper `#F0EFEA`, Montserrat for headings and Mulish for text (closest free match to the website's geometric sans). Colours and fonts are set in `app/Providers/Filament/AdminPanelProvider.php` and `public/css/bulkstream.css`; change them there. PDF reports use the logo and ink colours with the built-in DejaVu font.
