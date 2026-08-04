<?php

namespace App\Enums;

enum WorkOrderPhotoType: string
{
    case Odp = 'odp';
    case OntDevice = 'ont_device';
    case SignalStrength = 'signal_strength';
    case HouseFront = 'house_front';

    public function label(): string
    {
        return match ($this) {
            self::Odp => 'Foto ODP',
            self::OntDevice => 'Foto Perangkat ONT',
            self::SignalStrength => 'Foto Kekuatan Sinyal',
            self::HouseFront => 'Foto Tampak Depan Rumah',
        };
    }
}
