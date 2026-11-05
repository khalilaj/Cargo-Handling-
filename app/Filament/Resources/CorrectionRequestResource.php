<?php

namespace App\Filament\Resources;

use App\Domain\Cargo\Exceptions\CargoException;
use App\Domain\Corrections\Actions\ApproveCorrection;
use App\Domain\Corrections\Actions\RejectCorrection;
use App\Domain\Corrections\Enums\CorrectionStatus;
use App\Filament\Resources\CorrectionRequestResource\Pages;
use App\Models\CorrectionRequest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CorrectionRequestResource extends Resource
{
    protected static ?string $model = CorrectionRequest::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Corrections';

    protected static ?string $modelLabel = 'correction request';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = CorrectionRequest::where('status', CorrectionStatus::Pending->value)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Request')->columns(3)->schema([
                TextEntry::make('id')->label('Request'),
                TextEntry::make('status')->badge()
                    ->formatStateUsing(fn (CorrectionStatus $state) => $state->label())
                    ->color(fn (CorrectionStatus $state) => $state->color()),
                TextEntry::make('movement')->label('Movement')
                    ->state(fn (CorrectionRequest $r) => $r->movement->auditLabel()),
                TextEntry::make('requester.name')->label('Requested by'),
                TextEntry::make('created_at')->label('Requested')->dateTime('d M Y H:i'),
                TextEntry::make('reason')->columnSpanFull(),
            ]),
            Section::make('Decision')->columns(3)->schema([
                TextEntry::make('decider.name')->label('Decided by')->placeholder('—'),
                TextEntry::make('decided_at')->label('Decided')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('reversal_movement_id')->label('Reversal movement')->formatStateUsing(fn ($state) => $state ? "#{$state}" : '—'),
                TextEntry::make('decision_note')->label('Note')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['movement.tank', 'requester', 'decider']))
            ->defaultSort('id', 'desc')
            ->poll('10s')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(CorrectionStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                    ->default(CorrectionStatus::Pending->value),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (CorrectionStatus $state) => $state->label())
                    ->color(fn (CorrectionStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('movement')->label('Movement')
                    ->state(fn (CorrectionRequest $r) => "#{$r->cargo_movement_id} · {$r->movement->tank->code} · ".number_format($r->movement->net_kg).' kg')
                    ->description(fn (CorrectionRequest $r) => $r->movement->reference),
                Tables\Columns\TextColumn::make('reason')->limit(40)->tooltip(fn (CorrectionRequest $r) => $r->reason),
                Tables\Columns\TextColumn::make('requester.name')->label('By')->description(fn (CorrectionRequest $r) => $r->created_at->format('d M H:i')),
                Tables\Columns\TextColumn::make('decider.name')->label('Decided by')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(fn (CorrectionRequest $record) => static::getUrl('view', ['record' => $record]))
            ->recordActions(self::decisionActions());
    }

    /** @return list<Action> */
    public static function decisionActions(): array
    {
        $mayDecide = fn (CorrectionRequest $record) => $record->status === CorrectionStatus::Pending
            && (auth()->user()?->role->canApprove() ?? false);

        return [
            Action::make('approve')->label('Approve')->icon('heroicon-o-check-circle')->color('success')
                ->visible($mayDecide)
                ->requiresConfirmation()
                ->modalDescription('A reversal movement will be added to the ledger. The original movement stays on record.')
                ->schema([Forms\Components\Textarea::make('note')->label('Note (optional)')->rows(2)])
                ->action(function (array $data, CorrectionRequest $record) {
                    self::decide(fn () => app(ApproveCorrection::class)($record, auth()->user(), $data['note'] ?? null), 'Correction approved: reversal recorded');
                    $record->refresh();
                }),
            Action::make('reject')->label('Reject')->icon('heroicon-o-x-circle')->color('danger')
                ->visible($mayDecide)
                ->schema([Forms\Components\Textarea::make('note')->label('Why is it rejected?')->required()->minLength(5)->rows(2)])
                ->action(function (array $data, CorrectionRequest $record) {
                    self::decide(fn () => app(RejectCorrection::class)($record, auth()->user(), $data['note']), 'Correction rejected');
                    $record->refresh();
                }),
        ];
    }

    private static function decide(\Closure $work, string $successTitle): void
    {
        try {
            $work();
            Notification::make()->title($successTitle)->success()->send();
        } catch (CargoException $e) {
            Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCorrectionRequests::route('/'),
            'view' => Pages\ViewCorrectionRequest::route('/{record}'),
        ];
    }
}
