<?php

namespace App\Filament\Widgets;

use App\Domain\Fleet\Enums\VisitStatus;
use App\Domain\Operations\Enums\DischargeStatus;
use App\Domain\Operations\Enums\OrderStatus;
use App\Filament\Pages\Reports;
use App\Filament\Resources\BerthResource;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\DeliveryOrderResource;
use App\Filament\Resources\DriverResource;
use App\Filament\Resources\TankResource;
use App\Filament\Resources\TransporterResource;
use App\Filament\Resources\TruckResource;
use App\Filament\Resources\TruckVisitResource;
use App\Filament\Resources\VesselDischargeResource;
use App\Filament\Resources\VesselResource;
use App\Models\Berth;
use App\Models\DeliveryOrder;
use App\Models\Tank;
use App\Models\TruckVisit;
use App\Models\Vessel;
use App\Models\VesselDischarge;
use Filament\Widgets\Widget;

/** The whole job in order, with live counts and a link per step, so nobody has to hunt through the side menu. */
class WorkflowGuide extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.workflow-guide';

    /** People who only read (auditors) have no work to follow. */
    public static function canView(): bool
    {
        return auth()->user()?->role->canOperate() ?? false;
    }

    protected function getViewData(): array
    {
        $registers = collect([
            'Vessels' => VesselResource::class, 'Berths' => BerthResource::class, 'Tanks' => TankResource::class,
            'Customers' => CustomerResource::class, 'Transporters' => TransporterResource::class,
            'Trucks' => TruckResource::class, 'Drivers' => DriverResource::class,
        ])->map(fn (string $resource) => $resource::canViewAny() ? $resource::getUrl() : null)->filter();

        $scheduled = VesselDischarge::where('status', DischargeStatus::Scheduled->value)->count();
        $inPort = VesselDischarge::whereIn('status', [DischargeStatus::Berthed->value, DischargeStatus::Discharging->value])->count();
        $openOrders = DeliveryOrder::whereIn('status', [OrderStatus::Pending->value, OrderStatus::Loading->value])->count();
        $trucksOnSite = TruckVisit::whereNotIn('status', [VisitStatus::Departed->value, VisitStatus::Cancelled->value])->count();

        return ['steps' => [
            [
                'title' => 'Set up your registers',
                'what' => 'Once, and whenever something changes: the ships, berths, tanks, customers, trucks and drivers you will pick from later.',
                'count' => Vessel::count().' vessels, '.Berth::count().' berths, '.Tank::count().' tanks',
                'links' => $registers->all(),
            ],
            [
                'title' => 'Schedule a ship',
                'what' => 'Create a vessel discharge with the quantity on the bill of lading.',
                'count' => $scheduled.' scheduled',
                'links' => $this->linkTo('New vessel discharge', VesselDischargeResource::class, 'create', 'canCreate'),
            ],
            [
                'title' => 'Run the discharge',
                'what' => 'Open the voyage and press Mark berthed, Start discharge, Pump into tank for each tank, then Complete. The 0.5% transit loss is taken off automatically.',
                'count' => $inPort.' in port',
                'links' => $this->linkTo('Vessel discharges', VesselDischargeResource::class),
            ],
            [
                'title' => 'Take a delivery order',
                'what' => 'Create an order for a customer, then press Start loading on it. Trucks are loaded against it.',
                'count' => $openOrders.' open',
                'links' => $this->linkTo('New delivery order', DeliveryOrderResource::class, 'create', 'canCreate'),
            ],
            [
                'title' => 'Move the trucks',
                'what' => 'Gate in, weigh in, start loading, load from a tank, weigh out, gate out. Each visit page shows the next button.',
                'count' => $trucksOnSite.' on site',
                'links' => $this->linkTo('Truck visits', TruckVisitResource::class),
            ],
            [
                'title' => 'Check the numbers',
                'what' => 'Tank levels are just below. Reports show stock, transit loss and deliveries for any period.',
                'count' => '',
                'links' => Reports::canAccess() ? ['Reports' => Reports::getUrl()] : [],
            ],
        ]];
    }

    /** @return array<string, string> a one-item link list, empty when this role may not open the screen */
    private function linkTo(string $label, string $resource, string $page = 'index', string $gate = 'canViewAny'): array
    {
        return $resource::$gate() ? [$label => $resource::getUrl($page)] : [];
    }
}
