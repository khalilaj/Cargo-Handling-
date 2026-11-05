<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\TankResource;
use App\Models\Tank;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class TankLevels extends TableWidget
{
    protected static ?string $heading = 'Tank levels';
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(Tank::query())
            ->poll('10s')
            ->paginated(false)
            ->columns(TankResource::levelColumns());
    }
}
