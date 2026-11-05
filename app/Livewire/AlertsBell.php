<?php

namespace App\Livewire;

use App\Domain\Operations\Services\OperationalAlerts;
use App\Filament\Resources\CorrectionRequestResource;
use App\Filament\Resources\DeliveryOrderResource;
use App\Filament\Resources\TankResource;
use App\Filament\Resources\TruckResource;
use App\Filament\Resources\TruckVisitResource;
use App\Filament\Resources\VesselDischargeResource;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** The bell in the top bar: what needs this person's attention right now. Refreshes by itself. */
class AlertsBell extends Component
{
    public function render(): View
    {
        $user = auth()->user();

        if (! $user || ! $user->role->canSeeAlerts()) {
            return view('livewire.alerts-bell', ['visible' => false, 'alerts' => collect(), 'color' => 'gray']);
        }

        $alerts = app(OperationalAlerts::class)->forRole($user->role)
            ->map(fn (array $a) => $a + ['url' => $this->urlFor($a['target'])]);

        $color = match (true) {
            $alerts->contains('level', 'danger') => 'danger',
            $alerts->contains('level', 'warning') => 'warning',
            default => 'info',
        };

        return view('livewire.alerts-bell', ['visible' => true, 'alerts' => $alerts, 'color' => $color]);
    }

    /** @param array{type: string, id?: int} $t */
    private function urlFor(array $t): ?string
    {
        return match ($t['type']) {
            'tank' => TankResource::getUrl('view', ['record' => $t['id']]),
            'voyage' => VesselDischargeResource::getUrl('view', ['record' => $t['id']]),
            'order' => DeliveryOrderResource::getUrl('view', ['record' => $t['id']]),
            'visit' => TruckVisitResource::getUrl('view', ['record' => $t['id']]),
            'corrections' => CorrectionRequestResource::getUrl('index'),
            'trucks' => TruckResource::getUrl('index'),
            default => null,
        };
    }
}
