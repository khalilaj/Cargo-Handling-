<?php

namespace App\Domain\Cargo\Enums;

enum MovementType: string
{
    case VesselDischarge = 'vessel_discharge';   // inbound: ship -> tank
    case TruckDelivery = 'truck_delivery';       // outbound: tank -> truck

    public function label(): string
    {
        return match ($this) {
            self::VesselDischarge => 'Vessel discharge (inbound)',
            self::TruckDelivery => 'Truck delivery (outbound)',
        };
    }

    public function isInbound(): bool
    {
        return $this === self::VesselDischarge;
    }
}
