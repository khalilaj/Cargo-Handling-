<?php

namespace App\Domain\Access;

/**
 * Who may do what. One place, so the UI, policies and domain actions agree.
 *
 *  Admin       everything, including user management
 *  Supervisor  operations + master data + approving corrections + audit log
 *  Operator    day-to-day operations; can request (not approve) corrections
 *  Auditor     read-only everywhere, plus the audit log
 */
enum Role: string
{
    case Admin = 'admin';
    case Supervisor = 'supervisor';
    case Operator = 'operator';
    case Auditor = 'auditor';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Supervisor => 'warning',
            self::Operator => 'info',
            self::Auditor => 'gray',
        };
    }

    /** Record movements, advance vessel/order status, request corrections. */
    public function canOperate(): bool
    {
        return in_array($this, [self::Admin, self::Supervisor, self::Operator], true);
    }

    /** Approve or reject correction requests. */
    public function canApprove(): bool
    {
        return in_array($this, [self::Admin, self::Supervisor], true);
    }

    /** Create/edit tanks, schedule vessels, create delivery orders. */
    public function canManageMasterData(): bool
    {
        return in_array($this, [self::Admin, self::Supervisor], true);
    }

    public function canViewAudit(): bool
    {
        return in_array($this, [self::Admin, self::Supervisor, self::Auditor], true);
    }

    public function canManageUsers(): bool
    {
        return $this === self::Admin;
    }
}
