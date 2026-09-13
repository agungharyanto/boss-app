<?php

namespace App\Enums;

enum CpeActionType: string
{
    case Reboot = 'reboot';
    case SetSsid = 'set_ssid';
    case SetPassword = 'set_password';
    case SetSsidEnabled = 'set_ssid_enabled';
    case SyncNow = 'sync_now';
    /** v0.12.6 — lihat App\Services\Network\WanConfigPushService. */
    case PushWanConfig = 'push_wan_config';

    public function label(): string
    {
        return match ($this) {
            self::Reboot => 'Reboot',
            self::SetSsid => 'Ganti SSID WiFi',
            self::SetPassword => 'Ganti Password WiFi',
            self::SetSsidEnabled => 'Ubah Status SSID',
            self::SyncNow => 'Sync Sekarang',
            self::PushWanConfig => 'Push Konfig WAN',
        };
    }
}
