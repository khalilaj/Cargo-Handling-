<?php

namespace App\Filament\Widgets;

use App\Domain\Operations\Enums\DischargeStatus;
use App\Filament\Resources\VesselDischargeResource;
use App\Models\VesselDischarge;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class VesselsInPort extends TableWidget
{
    protected static ?int $sort = 5;

    protected static ?string $heading = 'Vessels in port';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(VesselDischarge::query()->withTotals()->with(['berth', 'vessel'])
                ->whereIn('status', [DischargeStatus::Berthed->value, DischargeStatus::Discharging->value])->orderBy('berth_id'))
            ->paginated(false)
            ->poll('10s')
            ->emptyStateHeading('No vessels alongside')
            ->emptyStateDescription('Scheduled voyages appear here once they are berthed.')
            ->recordUrl(fn (VesselDischarge $record) => VesselDischargeResource::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('berth.code')->label('Berth')->weight('bold')->placeholder('?'),
                Tables\Columns\TextColumn::make('vessel_name')->label('Vessel')->description(fn (VesselDischarge $r) => $r->vessel?->imo ? "IMO {$r->vessel->imo}" : null),
                Tables\Columns\TextColumn::make('voyage_ref')->label('Voyage'),
                Tables\Columns\TextColumn::make('commodity')->badge()->formatStateUsing(fn ($state) => strtoupper($state->value)),
                Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => $state->label())->color(fn ($state) => $state->color()),
                Tables\Columns\TextColumn::make('progress')->label('Discharged')
                    ->state(fn (VesselDischarge $r) => number_format($r->receivedGrossKg() / 1000, 1).' of '.number_format($r->bl_quantity_kg / 1000, 1)." t ({$r->progressPercent()}%)"),
                Tables\Columns\TextColumn::make('berthed_at')->label('Alongside since')->dateTime('d M H:i')->placeholder('—'),
            ]);
    }
}
