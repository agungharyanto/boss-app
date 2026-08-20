<?php

namespace App\Enums;

enum CpeActionType: string
{
    case Reboot = 'reboot';
    case SetSsid = 'set_ssid';
    case SetPassword = 'set_password';
    case SetSsidEnabled = 'set_ssid_enabled';
    case SyncNow = 'sync_now';

    public function label(): string
    {
        return match ($this) {
            self::Reboot => 'Reboot',
            self::SetSsid => 'Ganti SSID WiFi',
            self::SetPassword => 'Ganti Password WiFi',
            self::SetSsidEnabled => 'Ubah Status SSID',
            self::SyncNow => 'Sync Sekarang',
        };
    }
}
