<?php

namespace App\Filament\Pages;

use App\Domain\Reporting\CsvExport;
use App\Domain\Reporting\DeliveryFulfilmentReport;
use App\Domain\Reporting\StockPositionReport;
use App\Domain\Reporting\VesselReconciliationReport;
use App\Http\Controllers\ReportPdfController;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class Reports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Terminal reports';

    protected static ?string $title = 'Terminal reports';

    protected string $view = 'filament.pages.reports';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->canViewReports() ?? false;
    }

    public string $from = '';

    public string $until = '';

    public function mount(): void
    {
        [$f, $u] = ReportPdfController::period(null, null);
        $this->from = $f->toDateString();
        $this->until = $u->toDateString();
    }

    /** @return array{Carbon, Carbon} */
    private function period(): array
    {
        return ReportPdfController::period($this->from, $this->until);   // bad or swapped input is repaired, never an error
    }

    protected function getViewData(): array
    {
        [$f, $u] = $this->period();

        return [
            'stock' => app(StockPositionReport::class)($f, $u),
            'vessels' => app(VesselReconciliationReport::class)($f, $u),
            'orders' => app(DeliveryFulfilmentReport::class)($f, $u),
            'pdfUrl' => route('reports.pdf', ['from' => $f->toDateString(), 'until' => $u->toDateString()]),
        ];
    }

    public function downloadStock(): StreamedResponse
    {
        [$f, $u] = $this->period();
        $report = app(StockPositionReport::class)($f, $u);

        return CsvExport::download(
            "stock-position_{$f->toDateString()}_{$u->toDateString()}.csv",
            ['product', 'opening_kg', 'discharged_gross_kg', 'transit_loss_kg', 'delivered_kg', 'closing_kg', 'reversals', 'reconciles'],
            array_map(fn ($r) => [
                strtoupper($r['commodity']->value), $r['opening_kg'], $r['discharged_gross_kg'], $r['transit_loss_kg'],
                $r['delivered_kg'], $r['closing_kg'], $r['reversals'], $r['reconciles'] ? 'yes' : 'NO',
            ], $report['rows']),
        );
    }

    public function downloadVessels(): StreamedResponse
    {
        [$f, $u] = $this->period();

        return CsvExport::download(
            "vessel-reconciliation_{$f->toDateString()}_{$u->toDateString()}.csv",
            ['voyage', 'vessel', 'product', 'status', 'bill_of_lading_kg', 'received_gross_kg', 'transit_loss_kg', 'received_net_kg', 'outstanding_kg', 'loss_percent'],
            app(VesselReconciliationReport::class)($f, $u),
        );
    }

    public function downloadOrders(): StreamedResponse
    {
        [$f, $u] = $this->period();

        return CsvExport::download(
            "delivery-fulfilment_{$f->toDateString()}_{$u->toDateString()}.csv",
            ['order', 'customer', 'product', 'status', 'ordered_kg', 'delivered_kg', 'outstanding_kg', 'fulfilled_percent'],
            app(DeliveryFulfilmentReport::class)($f, $u),
        );
    }
}
