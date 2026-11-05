<?php

namespace App\Domain\Operations\Enums;

/** Truck delivery order lifecycle: pending -> loading -> completed (or cancelled). */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Loading = 'loading';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Loading, self::Cancelled],
            self::Loading => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    public function isActive(): bool
    {
        return $this === self::Loading;
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
            self::Pending => 'gray',
            self::Loading => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function actionLabel(): string
    {
        return match ($this) {
            self::Loading => 'Start loading',
            self::Completed => 'Complete',
            self::Cancelled => 'Cancel',
            self::Pending => 'Reopen',
        };
    }
}
