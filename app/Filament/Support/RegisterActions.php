<?php

namespace App\Filament\Support;

use App\Models\Berth;
use App\Models\Driver;
use App\Models\Truck;
use App\Models\TruckVisit;
use App\Models\Vessel;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/** Registers are switched off, never deleted. Switching off is refused while the thing is in use. */
class RegisterActions
{
    public static function toggleActive(): Action
    {
        return Action::make('toggle_active')
            ->label(fn (Model $record) => $record->active ? 'Deactivate' : 'Activate')
            ->icon(fn (Model $record) => $record->active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
            ->color(fn (Model $record) => $record->active ? 'danger' : 'success')
            ->authorize(fn () => auth()->user()?->role->canManageMasterData() ?? false)
            ->requiresConfirmation()
            ->modalDescription(fn (Model $record) => $record->active
                ? 'It will no longer be offered when scheduling or gating in. History is kept.'
                : 'It will be offered again.')
            ->action(function (Model $record) {
                if ($record->active && ($why = self::blocker($record))) {
                    Notification::make()->title('Cannot deactivate')->body($why)->danger()->send();

                    return;
                }
                $record->update(['active' => ! $record->active]);
                Notification::make()->title($record->active ? 'Activated' : 'Deactivated')->success()->send();
            });
    }

    /** Why this record cannot be switched off right now, or null. */
    public static function blocker(Model $record): ?string
    {
        return match (true) {
            $record instanceof Vessel => ($v = $record->currentVoyage()) ? "{$record->name} is alongside on {$v->voyage_ref}." : null,
            $record instanceof Berth => ($v = $record->occupant()) ? "Berth {$record->code} is occupied by {$v->vessel_name}." : null,
            $record instanceof Truck => $record->activeVisit()->exists() ? "Truck {$record->plate} is on site." : null,
            $record instanceof Driver => TruckVisit::whereNotNull('active_truck_id')->where('driver_id', $record->id)->exists() ? "{$record->name} is on site with a truck." : null,
            default => null,
        };
    }
}
