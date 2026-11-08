<?php

namespace App\Filament\Support;

use App\Domain\Access\RegisterUsage;
use App\Models\Berth;
use App\Models\Driver;
use App\Models\Tank;
use App\Models\Truck;
use App\Models\TruckVisit;
use App\Models\Vessel;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Registers are archived, never deleted straight away. Only an admin archives or restores; only an admin
 * deletes, and only an archived record that nothing refers to any more.
 */
class RegisterActions
{
    /** The three row buttons every register gets. */
    public static function archiveActions(): array
    {
        return [self::archive(), self::restore(), self::delete()];
    }

    /** Archived records are hidden unless the filter says otherwise. */
    public static function statusFilter(): TernaryFilter
    {
        return TernaryFilter::make('active')->label('Status')
            ->placeholder('All')->trueLabel('Active')->falseLabel('Archived')
            ->default(true);
    }

    public static function archive(): Action
    {
        return Action::make('archive')
            ->label('Archive')
            ->icon('heroicon-o-archive-box')
            ->color('warning')
            ->visible(fn (Model $record) => (bool) $record->active)
            ->authorize(fn (Model $record) => auth()->user()?->can('archive', $record) ?? false)
            ->requiresConfirmation()
            ->modalHeading('Archive this record?')
            ->modalDescription('It will no longer be offered when scheduling or gating in. History is kept. An admin can restore it.')
            ->action(function (Model $record) {
                abort_unless(auth()->user()?->can('archive', $record), 403);
                $why = DB::transaction(function () use ($record): ?string {
                    $fresh = $record::query()->lockForUpdate()->findOrFail($record->getKey());
                    if ($why = self::blocker($fresh)) {
                        return $why;
                    }
                    $fresh->update(['active' => false]);

                    return null;
                });

                if ($why) {
                    Notification::make()->title('Not done')->body("Cannot archive. {$why}")->danger()->send();

                    return;
                }
                Notification::make()->title('Archived')->success()->send();
            });
    }

    public static function restore(): Action
    {
        return Action::make('restore')
            ->label('Restore')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('success')
            ->visible(fn (Model $record) => ! $record->active)
            ->authorize(fn (Model $record) => auth()->user()?->can('restore', $record) ?? false)
            ->requiresConfirmation()
            ->modalDescription('It will be offered again.')
            ->action(function (Model $record) {
                abort_unless(auth()->user()?->can('restore', $record), 403);
                $record->update(['active' => true]);
                Notification::make()->title('Restored')->success()->send();
            });
    }

    public static function delete(): Action
    {
        return Action::make('delete')
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (Model $record) => ! $record->active)
            ->authorize(fn (Model $record) => auth()->user()?->can('delete', $record) ?? false)
            ->requiresConfirmation()
            ->modalHeading('Delete this record for good?')
            ->modalDescription('This cannot be undone. It is only allowed when nothing refers to it any more.')
            ->modalSubmitActionLabel('Delete permanently')
            ->action(function (Model $record) {
                abort_unless(auth()->user()?->can('delete', $record), 403);

                try {
                    // Re-read the row under a lock so nothing can be booked against it between the check and the delete.
                    $reasons = DB::transaction(function () use ($record): array {
                        $fresh = $record::query()->lockForUpdate()->findOrFail($record->getKey());
                        abort_unless(auth()->user()?->can('delete', $fresh), 403);

                        if ($reasons = RegisterUsage::blockers($fresh)) {
                            return $reasons;
                        }
                        $fresh->delete();

                        return [];
                    });
                } catch (QueryException) {
                    $reasons = ['Other records still refer to it.'];
                }

                if ($reasons) {
                    Notification::make()->title('Not done')->body(implode(' ', $reasons).' Nothing was deleted.')->danger()->send();

                    return;
                }
                Notification::make()->title('Deleted')->success()->send();
            });
    }

    /** Why this record cannot be archived right now, or null. */
    public static function blocker(Model $record): ?string
    {
        return match (true) {
            $record instanceof Vessel => ($v = $record->currentVoyage()) ? "{$record->name} is alongside on {$v->voyage_ref}." : null,
            $record instanceof Berth => ($v = $record->occupant()) ? "Berth {$record->code} is occupied by {$v->vessel_name}." : null,
            $record instanceof Truck => $record->activeVisit()->exists() ? "Truck {$record->plate} is on site." : null,
            $record instanceof Driver => TruckVisit::whereNotNull('active_truck_id')->where('driver_id', $record->id)->exists() ? "{$record->name} is on site with a truck." : null,
            $record instanceof Tank => $record->current_volume_kg > 0 ? "Tank {$record->code} still holds ".number_format($record->current_volume_kg).' kg.' : null,
            default => null,
        };
    }
}
