<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use Illuminate\Support\Str;

/**
 * Add to a model to log every create / update / delete with who did it and
 * the before/after values. Secrets never leave the model; derived fields can be excluded.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($m) => $m->writeAudit('created', null, $m->auditableValues($m->getAttributes())));

        static::updated(function ($m) {
            if ($m->wasChanged('password')) {          // the fact is logged, never the value
                AuditLog::record(Str::snake(class_basename($m)).'.password_changed', "{$m->auditLabel()} password changed", $m);
            }

            $changes = $m->auditableValues($m->getChanges());
            if ($changes === []) {
                return;
            }
            $old = array_intersect_key($m->getOriginal(), $changes);
            $m->writeAudit('updated', $m->auditableValues($old), $changes);
        });

        static::deleted(fn ($m) => $m->writeAudit('deleted', $m->auditableValues($m->getOriginal()), null));
    }

    /** Short human label of this record, e.g. "Tank CPO-01". */
    public function auditLabel(): string
    {
        return Str::headline(class_basename($this)).' #'.$this->getKey();
    }

    /** Attributes never written to the log (derived or secret). */
    protected function auditExclude(): array
    {
        return [];
    }

    private function auditableValues(array $attributes): array
    {
        $skip = array_merge(
            ['updated_at', 'created_at', 'password', 'remember_token', 'id'],
            $this->getHidden(),
            $this->auditExclude(),
        );

        return array_map(fn ($v) => match (true) {
            $v instanceof \BackedEnum => $v->value,
            $v instanceof \DateTimeInterface => $v->format('Y-m-d H:i:s'),
            default => $v,
        }, array_diff_key($attributes, array_flip($skip)));
    }

    private function writeAudit(string $action, ?array $old, ?array $new): void
    {
        $changedFields = array_keys($new ?? $old ?? []);

        $summary = match ($action) {
            'created' => 'created',
            'deleted' => 'deleted',
            default => implode(', ', array_map(
                fn ($f) => "{$f}: ".$this->auditShort($old[$f] ?? null).' → '.$this->auditShort($new[$f] ?? null),
                array_slice($changedFields, 0, 3),
            )).(count($changedFields) > 3 ? ' …' : ''),
        };

        AuditLog::record(
            Str::snake(class_basename($this)).'.'.$action,
            "{$this->auditLabel()} {$summary}",
            $this,
            $old,
            $new,
        );
    }

    private function auditShort(mixed $v): string
    {
        return match (true) {
            $v === null => '∅',
            is_bool($v) => $v ? 'yes' : 'no',
            is_scalar($v) => Str::limit((string) $v, 40),
            default => '…',
        };
    }
}
