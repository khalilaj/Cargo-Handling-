<?php

namespace App\Domain\Fleet\Enums;

/** Truck visit: arrived -> weighed_in -> loading -> weighed_out -> departed (or cancelled before loading). */
enum VisitStatus: string
{
    case Arrived = 'arrived';
    case WeighedIn = 'weighed_in';
    case Loading = 'loading';
    case WeighedOut = 'weighed_out';
    case Departed = 'departed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Arrived => [self::WeighedIn, self::Cancelled],
            self::WeighedIn => [self::Loading, self::Cancelled],
            self::Loading => [self::WeighedOut],
            self::WeighedOut => [self::Departed],
            self::Departed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** The truck is physically on site. */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Departed, self::Cancelled], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::WeighedIn => 'Weighed in',
            self::WeighedOut => 'Weighed out',
            default => ucfirst($this->value),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Arrived => 'gray',
            self::WeighedIn => 'info',
            self::Loading => 'warning',
            self::WeighedOut => 'primary',
            self::Departed => 'success',
            self::Cancelled => 'danger',
        };
    }
}
