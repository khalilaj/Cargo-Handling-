<?php

namespace App\Domain\Operations\Enums;

/** Vessel discharge lifecycle: scheduled -> berthed -> discharging -> completed (or cancelled). */
enum DischargeStatus: string
{
    case Scheduled = 'scheduled';
    case Berthed = 'berthed';
    case Discharging = 'discharging';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Scheduled => [self::Berthed, self::Cancelled],
            self::Berthed => [self::Discharging, self::Cancelled],
            self::Discharging => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    public function isActive(): bool
    {
        return $this === self::Discharging;
    }

    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Scheduled => 'gray',
            self::Berthed => 'info',
            self::Discharging => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    /** Verb for the button that moves an operation INTO this status. */
    public function actionLabel(): string
    {
        return match ($this) {
            self::Berthed => 'Mark berthed',
            self::Discharging => 'Start discharge',
            self::Completed => 'Complete',
            self::Cancelled => 'Cancel',
            self::Scheduled => 'Reschedule',
        };
    }
}
