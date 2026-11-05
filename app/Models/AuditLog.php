<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only. Rows can be written, never changed or removed through the application.
 * (For belt and braces in production, also revoke UPDATE/DELETE on this table from the app's DB role.)
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log rows are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit log rows cannot be deleted.'));
    }

    /** Write one entry, stamped with whoever is acting right now. */
    public static function record(string $event, string $description, ?Model $subject = null, ?array $old = null, ?array $new = null): self
    {
        $user = auth()->user();

        return static::create([
            'user_id' => $user?->id,
            'actor_name' => $user?->name ?? 'system',
            'actor_role' => $user?->role?->value,
            'event' => $event,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'description' => mb_substr($description, 0, 250),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
