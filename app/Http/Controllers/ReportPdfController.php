<?php

namespace App\Http\Controllers;

use App\Domain\Reporting\DeliveryFulfilmentReport;
use App\Domain\Reporting\StockPositionReport;
use App\Domain\Reporting\VesselReconciliationReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class ReportPdfController
{
    public function __invoke(Request $request): Response
    {
        abort_unless($request->user()->role->canViewReports(), 403);

        [$from, $until] = self::period($request->query('from'), $request->query('until'));

        return Pdf::loadView('reports.pdf', [
            'from' => $from,
            'until' => $until,
            'generatedBy' => $request->user()->name,
            'stock' => app(StockPositionReport::class)($from, $until),
            'vessels' => app(VesselReconciliationReport::class)($from, $until),
            'orders' => app(DeliveryFulfilmentReport::class)($from, $until),
        ])->setPaper('a4', 'landscape')
            ->download("terminal-report-{$from->toDateString()}_{$until->toDateString()}.pdf");
    }

    /** @return array{Carbon, Carbon} a safe, ordered period; bad input falls back to the last 30 days */
    public static function period(mixed $from, mixed $until): array
    {
        try {
            $f = $from ? Carbon::parse((string) $from)->startOfDay() : now()->subDays(30)->startOfDay();
            $u = $until ? Carbon::parse((string) $until)->endOfDay() : now()->endOfDay();
        } catch (\Throwable) {
            [$f, $u] = [now()->subDays(30)->startOfDay(), now()->endOfDay()];
        }

        return $f->gt($u) ? [$u->copy()->startOfDay(), $f->copy()->endOfDay()] : [$f, $u];
    }
}
