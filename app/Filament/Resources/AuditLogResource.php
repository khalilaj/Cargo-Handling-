<?php

namespace App\Filament\Resources;

use App\Domain\Access\Role;
use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\KenyaTime;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

/** Read-only ledger. Entries are shown as who / what / which record / what changed, never as raw JSON. */
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
                TextEntry::make('created_at')->label('When')->dateTime('d M Y H:i:s')->timezone(KenyaTime::ZONE),
                TextEntry::make('actor_name')->label('Who')->state(fn (AuditLog $r) => self::actorName($r)),
                TextEntry::make('actor_role')->label('Role')->badge()
                    ->formatStateUsing(fn (?string $state) => self::roleLabel($state))->placeholder('—'),
                TextEntry::make('event')->label('What happened')->badge()
                    ->formatStateUsing(fn (string $state) => self::eventLabel($state))
                    ->color(fn (string $state) => self::eventColor($state)),
                TextEntry::make('subject')->label('Record')
                    ->state(fn (AuditLog $r) => self::recordLabel($r))
                    ->url(fn (AuditLog $r) => self::subjectUrl($r))
                    ->placeholder('—'),
                TextEntry::make('ip_address')->label('IP')->placeholder('—'),
                TextEntry::make('description')->columnSpanFull(),
            ]),
            Section::make('Changes')->schema([
                TextEntry::make('changes')->hiddenLabel()
                    ->state(fn (AuditLog $r) => self::changeLines($r))
                    ->listWithLineBreaks()->bulleted()
                    ->placeholder('No field values were recorded.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('15s')
            ->recordUrl(fn (AuditLog $r) => static::getUrl('view', ['record' => $r]))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('d M H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('actor_name')->label('Who')->searchable()
                    ->state(fn (AuditLog $r) => self::actorName($r))
                    ->description(fn (AuditLog $r) => self::roleLabel($r->actor_role)),
                Tables\Columns\TextColumn::make('event')->label('What happened')->badge()
                    ->formatStateUsing(fn (string $state) => self::eventLabel($state))
                    ->color(fn (string $state) => self::eventColor($state))
                    ->searchable(),
                Tables\Columns\TextColumn::make('subject_type')->label('Record')->weight('bold')
                    ->state(fn (AuditLog $r) => self::recordLabel($r))
                    ->description(fn (AuditLog $r) => $r->subject_type)
                    ->url(fn (AuditLog $r) => self::subjectUrl($r))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('changes')->label('Changes')->wrap()
                    ->state(fn (AuditLog $r) => self::changeLines($r))
                    ->listWithLineBreaks()->bulleted()
                    ->limitList(3)->expandableLimitedList()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('description')->label('Details')->visibleFrom('md')
                    ->searchable()->wrap(),
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
            ->recordActions([ViewAction::make()->iconButton()->tooltip('View')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }

    public static function actorName(AuditLog $entry): string
    {
        return $entry->actor_name === 'system' ? 'System' : (string) $entry->actor_name;
    }

    public static function roleLabel(?string $role): ?string
    {
        return Role::tryFrom($role ?? '')?->label();
    }

    public static function eventLabel(string $event): string
    {
        return match (true) {
            str_ends_with($event, '.created') => 'Created',
            str_ends_with($event, '.deleted') => 'Deleted',
            str_ends_with($event, '.password_changed') => 'Password changed',
            str_ends_with($event, '.updated') => 'Updated',
            default => (string) Str::of($event)->after('.')->headline(),
        };
    }

    public static function eventColor(string $event): string
    {
        return match (true) {
            str_ends_with($event, '.created') => 'success',
            str_ends_with($event, '.deleted'), str_contains($event, 'password') => 'danger',
            default => 'info',
        };
    }

    /** Readable reference: the record's title (e.g. "CPO-01"), or "#id" once the record is gone. */
    public static function recordLabel(AuditLog $entry): string
    {
        $record = self::subjectRecord($entry);
        $titleAttribute = $record ? self::titleAttribute($record) : null;
        $title = $titleAttribute ? $record->getAttribute($titleAttribute) : null;

        return filled($title) ? (string) $title : ($entry->subject_id ? '#'.$entry->subject_id : '—');
    }

    /** Link to the record's own page, only when that page exists and the viewer may open it. */
    public static function subjectUrl(AuditLog $entry): ?string
    {
        $record = self::subjectRecord($entry);
        $resource = $record ? Filament::getModelResource($record::class) : null;

        if (! $resource || ! $resource::hasPage('view') || ! auth()->user()?->can('view', $record)) {
            return null;
        }

        return $resource::getUrl('view', ['record' => $record]);
    }

    /**
     * One line per changed field: "Label: old → new" for updates, "Label: value" for
     * creations and deletions.
     *
     * @return list<string>
     */
    public static function changeLines(AuditLog $entry): array
    {
        $old = $entry->old_values ?? [];
        $new = $entry->new_values ?? [];

        if ($old === [] && $new === []) {
            return str_ends_with($entry->event, '.password_changed') ? ['Password: value not recorded'] : [];
        }

        $fields = array_unique([...array_keys($old), ...array_keys($new)]);

        return array_values(array_map(fn (string $field) => self::changeLine($field, $old, $new), $fields));
    }

    private static function changeLine(string $field, array $old, array $new): string
    {
        $label = (string) Str::of($field)->replace('_', ' ')->ucfirst();

        return match (true) {
            $new === [] => "{$label}: ".self::formatValue($old[$field] ?? null),
            $old === [] => "{$label}: ".self::formatValue($new[$field] ?? null),
            default => "{$label}: ".self::formatValue($old[$field] ?? null).' → '.self::formatValue($new[$field] ?? null),
        };
    }

    private static function formatValue(mixed $value): string
    {
        return match (true) {
            $value === null => '∅',
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value) => Str::limit((string) $value, 80),
            default => '…',
        };
    }

    private static function subjectRecord(AuditLog $entry): ?Model
    {
        if (! $entry->subject_type || ! $entry->subject_id) {
            return null;
        }

        $class = 'App\\Models\\'.$entry->subject_type;

        return class_exists($class) ? $class::query()->find($entry->subject_id) : null;
    }

    private static function titleAttribute(Model $record): ?string
    {
        $resource = Filament::getModelResource($record::class);

        return $resource ? $resource::getRecordTitleAttribute() : null;
    }
}
