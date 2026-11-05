<?php

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Cargo\Enums\Commodity;
use App\Domain\Cargo\Services\TankLedger;
use App\Domain\Reporting\CsvExport;
use App\Domain\Reporting\DeliveryFulfilmentReport;
use App\Domain\Reporting\StockPositionReport;
use App\Domain\Reporting\VesselReconciliationReport;
use App\Filament\Pages\Reports;
use App\Http\Controllers\ReportPdfController;
use App\Models\CargoMovement;
use App\Models\Tank;
use App\Models\User;
use App\Models\VesselDischarge;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function stock(string $from, string $to): array
    {
        $r = (new StockPositionReport)(Carbon::parse($from), Carbon::parse($to));

        return collect($r['rows'])->keyBy(fn ($row) => $row['commodity']->value)->all() + ['_totals' => $r['totals']];
    }

    private function login(Role $role): User
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs($u = User::where('role', $role->value)->firstOrFail());

        return $u;
    }

    // ---- stock position ----------------------------------------------------------

    public function test_whole_history_matches_hand_calculation(): void
    {
        $r = $this->stock('2026-10-01', '2026-12-31');

        $this->assertSame([0, 4_200_000, 21_000, 350_000, 3_829_000], $this->figures($r['cpo']));
        $this->assertSame([0, 2_500_000, 12_500, 750_000, 1_737_500], $this->figures($r['cpol']));      // 600,000 + 150,000 delivered
        $this->assertSame([0, 1_700_000, 8_500, 0, 1_691_500], $this->figures($r['pfad']));
        $this->assertSame(42_000, $r['_totals']['transit_loss_kg']);
    }

    /** @return list<int> opening, discharged, loss, delivered, closing */
    private function figures(array $row): array
    {
        return [$row['opening_kg'], $row['discharged_gross_kg'], $row['transit_loss_kg'], $row['delivered_kg'], $row['closing_kg']];
    }

    public function test_a_single_day_has_the_right_opening_balance(): void
    {
        $r = $this->stock('2026-11-03', '2026-11-03');       // Nov 3: PFAD discharge, CPO delivery, CPOL delivery

        $this->assertSame([4_179_000, 0, 0, 350_000, 3_829_000], $this->figures($r['cpo']));
        $this->assertSame([2_487_500, 0, 0, 600_000, 1_887_500], $this->figures($r['cpol']));          // seed-6 is on Nov 4
        $this->assertSame([0, 1_700_000, 8_500, 0, 1_691_500], $this->figures($r['pfad']));
    }

    public function test_a_period_with_no_movements_just_carries_the_balance(): void
    {
        $r = $this->stock('2026-10-01', '2026-10-31');       // before anything happened

        $this->assertSame([0, 0, 0, 0, 0], $this->figures($r['cpo']));

        $r = $this->stock('2026-11-20', '2026-11-30');       // after everything
        $this->assertSame([3_829_000, 0, 0, 0, 3_829_000], $this->figures($r['cpo']));
    }

    public function test_reversals_are_netted_into_the_figure_they_cancel(): void
    {
        $ledger = app(TankLedger::class);
        $ledger->reverse(CargoMovement::where('idempotency_key', 'seed-3')->first(), Carbon::parse('2026-11-04 12:00'), 'r3');   // PFAD discharge
        $ledger->reverse(CargoMovement::where('idempotency_key', 'seed-6')->first(), Carbon::parse('2026-11-04 13:00'), 'r6');   // CPOL delivery

        $r = $this->stock('2026-10-01', '2026-12-31');

        $this->assertSame([0, 0, 0, 0, 0], $this->figures($r['pfad']), 'a fully reversed discharge leaves no trace in the totals');
        $this->assertSame(1, $r['pfad']['reversals']);
        $this->assertSame([0, 2_500_000, 12_500, 600_000, 1_887_500], $this->figures($r['cpol']));   // the 150,000 delivery is gone
        $this->assertSame(33_500, $r['_totals']['transit_loss_kg']);
    }

    public function test_closing_always_reconciles_and_matches_the_tanks(): void
    {
        foreach (['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'] as $day) {
            foreach (['cpo', 'cpol', 'pfad'] as $c) {
                $this->assertTrue($this->stock($day, $day)[$c]['reconciles'], "{$c} on {$day}");
            }
        }

        $closing = $this->stock('2026-10-01', '2026-12-31');
        foreach (Commodity::cases() as $c) {
            $this->assertSame(
                (int) Tank::where('commodity', $c)->sum('current_volume_kg'),
                $closing[$c->value]['closing_kg'],
                "{$c->value}: report closing must equal what the tanks hold",
            );
        }
    }

    // ---- vessels and orders ------------------------------------------------------

    public function test_vessel_reconciliation_rows(): void
    {
        $rows = collect((new VesselReconciliationReport)(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')))->keyBy('voyage');

        $v = $rows['VOY-2611-03'];
        $this->assertSame([2_000_000, 1_700_000, 8_500, 1_691_500, 300_000, 0.5], [
            $v['bl_kg'], $v['received_gross_kg'], $v['transit_loss_kg'], $v['received_net_kg'], $v['outstanding_kg'], $v['loss_percent'],
        ]);
        $this->assertSame('Discharging', $v['status']);
        $this->assertSame(0, $rows['VOY-2611-04']['received_gross_kg']);            // berthed, nothing pumped yet
    }

    public function test_delivery_fulfilment_rows(): void
    {
        $rows = collect((new DeliveryFulfilmentReport)(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')))->keyBy('order');

        $this->assertSame([400_000, 150_000, 250_000, 37.5], [
            $rows['ORD-1003']['ordered_kg'], $rows['ORD-1003']['delivered_kg'], $rows['ORD-1003']['outstanding_kg'], $rows['ORD-1003']['fulfilled_percent'],
        ]);
        $this->assertSame(100.0, $rows['ORD-1001']['fulfilled_percent']);
    }

    public function test_the_vessel_report_follows_reversals(): void
    {
        app(TankLedger::class)->reverse(CargoMovement::where('idempotency_key', 'seed-3')->first(), Carbon::parse('2026-11-04 12:00'), 'r3');

        $v = collect((new VesselReconciliationReport)(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')))->keyBy('voyage')['VOY-2611-03'];
        $this->assertSame(0, $v['received_gross_kg']);
        $this->assertSame(2_000_000, $v['outstanding_kg']);
    }

    // ---- CSV ---------------------------------------------------------------------

    public function test_csv_cells_that_a_spreadsheet_would_execute_are_neutralised(): void
    {
        foreach (['=HYPERLINK("http://evil","x")', '+1+1', '-2+3', '@SUM(A1)', "\tcmd"] as $evil) {
            $this->assertSame("'".$evil, CsvExport::safe($evil), $evil);
        }
        foreach (['MV Pacific Dawn', '', 12, 3.5, null, 'a=b', '5-3'] as $fine) {
            $this->assertSame($fine, CsvExport::safe($fine));
        }

        $response = CsvExport::download('t.csv', ['name', 'kg'], [['=cmd|calc', 5], ['MV Safe, "quoted"', 6]]);
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringStartsWith("\xEF\xBB\xBF".'name,kg', $csv);
        $this->assertStringContainsString("'=cmd|calc,5", $csv);
        $this->assertStringContainsString('"MV Safe, ""quoted""",6', $csv);               // commas and quotes escaped
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
    }

    public function test_a_hostile_vessel_name_is_neutralised_in_the_real_export(): void
    {
        $this->login(Role::Supervisor);
        VesselDischarge::create(['voyage_ref' => 'EVIL-1', 'vessel_name' => '=HYPERLINK("http://evil")', 'commodity' => Commodity::CPO,
            'bl_quantity_kg' => 1, 'eta' => '2026-11-10 08:00']);

        $component = Livewire::test(Reports::class)->set('from', '2026-11-01')->set('until', '2026-11-30');
        $component->call('downloadVessels')->assertFileDownloaded('vessel-reconciliation_2026-11-01_2026-11-30.csv');

        $rows = (new VesselReconciliationReport)(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
        $evil = collect($rows)->firstWhere('voyage', 'EVIL-1');
        $this->assertSame("'=HYPERLINK(\"http://evil\")", CsvExport::safe($evil['vessel']));
    }

    // ---- screens -----------------------------------------------------------------

    public function test_reports_are_for_managers_and_auditors_not_operators(): void
    {
        $this->login(Role::Operator);
        $this->get('/admin/reports')->assertForbidden();

        foreach ([Role::Auditor, Role::Supervisor, Role::Admin] as $role) {
            $this->login($role);
            $this->get('/admin/reports')->assertOk();
        }

        Livewire::test(Reports::class)
            ->set('from', '2026-10-01')->set('until', '2026-12-31')
            ->assertSee('3,829,000')->assertSee('VOY-2611-03')->assertSee('ORD-1003')->assertSee('reconciles');
    }

    public function test_bad_or_swapped_dates_are_repaired_not_an_error(): void
    {
        [$f, $u] = ReportPdfController::period('2026-11-30', '2026-11-01');            // swapped
        $this->assertTrue($f->lt($u));
        $this->assertSame('2026-11-01', $f->toDateString());

        [$f, $u] = ReportPdfController::period('not a date', '???');
        $this->assertTrue($f->lt($u));                                                 // fell back to the last 30 days

        $this->login(Role::Supervisor);
        Livewire::test(Reports::class)->set('from', 'garbage')->set('until', '')->assertOk();
    }

    public function test_the_pdf_download_works_for_signed_in_users_only(): void
    {
        $this->get('/reports/pdf')->assertRedirect('/admin/login');

        foreach ([Role::Auditor, Role::Supervisor] as $role) {
            $this->login($role);
            $res = $this->get('/reports/pdf?from=2026-11-01&until=2026-11-30');
            $res->assertOk();
            $this->assertStringContainsString('application/pdf', (string) $res->headers->get('Content-Type'));
            $this->assertStringContainsString('terminal-report-2026-11-01_2026-11-30.pdf', (string) $res->headers->get('Content-Disposition'));
            $this->assertStringStartsWith('%PDF', (string) $res->getContent());
        }
    }

    public function test_the_pdf_is_refused_to_operators(): void
    {
        $this->login(Role::Operator);

        $this->get('/reports/pdf?from=2026-11-01&until=2026-11-30')->assertForbidden();
    }
}
