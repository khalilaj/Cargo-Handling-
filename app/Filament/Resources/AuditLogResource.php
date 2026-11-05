<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $modelLabel = 'audit entry';

    protected static ?string $pluralModelLabel = 'audit log';

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

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Entry')->columns(3)->schema([
                TextEntry::make('created_at')->label('When')->dateTime('d M Y H:i:s'),
                TextEntry::make('actor_name')->label('Who'),
                TextEntry::make('actor_role')->label('Role')->badge()->placeholder('—'),
                TextEntry::make('event')->badge(),
                TextEntry::make('subject')->label('Record')
                    ->state(fn (AuditLog $r) => $r->subject_type ? "{$r->subject_type} #{$r->subject_id}" : '—'),
                TextEntry::make('ip_address')->label('IP')->placeholder('—'),
                TextEntry::make('description')->columnSpanFull(),
            ]),
            Section::make('Before')->schema([KeyValueEntry::make('old_values')->hiddenLabel()->placeholder('—')])->collapsible(),
            Section::make('After')->schema([KeyValueEntry::make('new_values')->hiddenLabel()->placeholder('—')])->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('15s')
            ->recordUrl(null)
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('d M H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('actor_name')->label('Who')->searchable()->description(fn (AuditLog $r) => $r->actor_role),
                Tables\Columns\TextColumn::make('event')->badge()->color(fn (string $state) => match (true) {
                    str_ends_with($state, '.created') => 'success',
                    str_ends_with($state, '.deleted') => 'danger',
                    str_contains($state, 'password') => 'danger',
                    default => 'info',
                })->searchable(),
                Tables\Columns\TextColumn::make('description')->searchable()->wrap(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event')
                    ->options(fn () => AuditLog::query()->distinct()->orderBy('event')->pluck('event', 'event')->all()),
                Tables\Filters\SelectFilter::make('user_id')->label('Person')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id')->all()),
                Tables\Filters\Filter::make('period')
                    ->schema([Forms\Components\DatePicker::make('from'), Forms\Components\DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAuditLogs::route('/')];
    }
}
