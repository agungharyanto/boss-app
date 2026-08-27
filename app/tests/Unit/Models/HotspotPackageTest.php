<?php

namespace Tests\Unit\Models;

use App\Models\HotspotPackage;
use Tests\TestCase;

/**
 * v0.14.4 — pure logic unit tests for HotspotPackage::routerOsSessionTimeout()/
 * mikrotikLookupName()/mikrotikTargetName() — no DB, no queue, no gateway.
 * The RouterOS command shapes these methods feed into
 * (rate-limit/session-timeout/name) were separately verified for real
 * against ro-hotspot.bajastu.id — see CLAUDE.md's v0.14.4 section.
 */
class HotspotPackageTest extends TestCase
{
    public function test_unlimited_profile_never_produces_a_session_timeout(): void
    {
        $package = new HotspotPackage(['profile_type' => 'unlimited']);

        $this->assertNull($package->routerOsSessionTimeout());
    }

    public function test_limited_time_base_produces_a_session_timeout(): void
    {
        $package = new HotspotPackage([
            'profile_type' => 'limited', 'limit_type' => 'time_base',
            'active_duration_value' => 3, 'active_duration_unit' => 'hour',
        ]);

        $this->assertSame('3h', $package->routerOsSessionTimeout());
    }

    /**
     * QuotaBase has no RouterOS profile-level field to push a limit into —
     * see HotspotLimitType's own docblock. Must stay null even though
     * profile_type=Limited and duration fields ARE present.
     */
    public function test_limited_quota_base_never_produces_a_session_timeout(): void
    {
        $package = new HotspotPackage([
            'profile_type' => 'limited', 'limit_type' => 'quota_base',
            'active_duration_value' => 30, 'active_duration_unit' => 'day',
        ]);

        $this->assertNull($package->routerOsSessionTimeout());
    }

    public function test_month_duration_approximates_to_30_days(): void
    {
        $package = new HotspotPackage([
            'profile_type' => 'limited', 'limit_type' => 'time_base',
            'active_duration_value' => 2, 'active_duration_unit' => 'month',
        ]);

        $this->assertSame('60d', $package->routerOsSessionTimeout());
    }

    public function test_minute_and_day_units_map_directly(): void
    {
        $minutePackage = new HotspotPackage([
            'profile_type' => 'limited', 'limit_type' => 'time_base',
            'active_duration_value' => 45, 'active_duration_unit' => 'minute',
        ]);
        $dayPackage = new HotspotPackage([
            'profile_type' => 'limited', 'limit_type' => 'time_base',
            'active_duration_value' => 7, 'active_duration_unit' => 'day',
        ]);

        $this->assertSame('45m', $minutePackage->routerOsSessionTimeout());
        $this->assertSame('7d', $dayPackage->routerOsSessionTimeout());
    }

    public function test_limited_with_a_missing_duration_value_produces_no_session_timeout(): void
    {
        $package = new HotspotPackage([
            'profile_type' => 'limited', 'limit_type' => 'time_base',
            'active_duration_value' => null, 'active_duration_unit' => 'day',
        ]);

        $this->assertNull($package->routerOsSessionTimeout());
    }

    public function test_mikrotik_lookup_name_falls_back_to_current_name_when_never_synced(): void
    {
        $package = new HotspotPackage(['name' => 'Paket-Baru', 'mikrotik_profile_name' => null]);

        $this->assertSame('Paket-Baru', $package->mikrotikLookupName());
        $this->assertSame('Paket-Baru', $package->mikrotikTargetName());
    }

    public function test_mikrotik_lookup_name_uses_the_previously_synced_name_after_a_rename(): void
    {
        $package = new HotspotPackage(['name' => 'Nama-Baru', 'mikrotik_profile_name' => 'Nama-Lama']);

        $this->assertSame('Nama-Lama', $package->mikrotikLookupName());
        $this->assertSame('Nama-Baru', $package->mikrotikTargetName());
    }
}
