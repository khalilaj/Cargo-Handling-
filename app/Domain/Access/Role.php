<?php

namespace App\Domain\Access;

/**
 * Who may do what. One place, so the UI, policies and domain actions agree.
 *
 *  Admin       everything, including user management
 *  Supervisor  operations + registers + approving corrections + reports + audit log
 *  Operator    day-to-day operations only; can request (not approve) corrections
 *  Auditor     read-only oversight: operations, stock, corrections, reports, audit log
 *
 * Two kinds of rules live here. "can..." rules say what a role may DO. "canView..." rules say what a
 * role may SEE: they drive the side menu, the dashboard and the alerts bell, and the policies use them
 * too, so a screen that is hidden from the menu is also refused by direct URL.
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

    /** Vessels, berths, customers, transporters, trucks, drivers. Operators only ever pick from them. */
    public function canViewRegisters(): bool
    {
        return $this->canManageMasterData();
    }

    public function canViewReports(): bool
    {
        return in_array($this, [self::Admin, self::Supervisor, self::Auditor], true);
    }

    /** The corrections queue. Operators raise a correction from the ledger and need nothing more. */
    public function canViewCorrections(): bool
    {
        return in_array($this, [self::Admin, self::Supervisor, self::Auditor], true);
    }

    /** Trends and the full movements feed on the dashboard. Operators get the live boards instead. */
    public function canViewAnalytics(): bool
    {
        return in_array($this, [self::Admin, self::Supervisor, self::Auditor], true);
    }

    /** Roles that act on alerts (the bell in the top bar). An auditor observes; it does not act. */
    public function canSeeAlerts(): bool
    {
        return in_array($this, [self::Admin, self::Supervisor, self::Operator], true);
    }
}
