<?php

namespace Tests\Feature\Network;

use App\Models\CpeDevice;
use App\Models\ModemType;
use App\Models\Tenant;
use App\Services\Network\ModemTypeSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.12.5 (rename dari WanConfigTemplateSuggestionService — dipisah dari
 * urusan Template sama sekali) — ModemTypeSuggestionService: murni OUI
 * matching `cpe_devices.manufacturer` -> `ModemType`, BEST-EFFORT, tidak
 * pernah garansi match.
 */
class ModemTypeSuggestionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ModemTypeSuggestionService
    {
        return app(ModemTypeSuggestionService::class);
    }

    public function test_single_unambiguous_match_returns_the_modem_type(): void
    {
        $tenant = Tenant::factory()->create();
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG,CIOT']);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'manufacturer' => 'ZICG']);

        $result = $this->service()->suggestFor($device);

        $this->assertNotNull($result);
        $this->assertSame($modemType->id, $result->id);
    }

    /**
     * Normalisasi kapital/spasi — pattern lowercase dengan spasi tetap
     * match manufacturer device uppercase tanpa spasi, dan sebaliknya.
     */
    public function test_matching_is_case_and_whitespace_insensitive(): void
    {
        $tenant = Tenant::factory()->create();
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => ' zicg , ciot ']);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'manufacturer' => '  zicg  ']);

        $result = $this->service()->suggestFor($device);

        $this->assertNotNull($result);
        $this->assertSame($modemType->id, $result->id);
    }

    public function test_no_matching_modem_type_returns_null(): void
    {
        $tenant = Tenant::factory()->create();
        ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG']);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'manufacturer' => 'UNKNOWNOUI']);

        $this->assertNull($this->service()->suggestFor($device));
    }

    public function test_empty_manufacturer_returns_null(): void
    {
        $tenant = Tenant::factory()->create();
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'manufacturer' => null]);

        $this->assertNull($this->service()->suggestFor($device));
    }

    /**
     * Ambigu — dua ModemType berbeda sama-sama punya pattern yang match
     * manufacturer yang sama. Tidak ada "yang benar" otomatis.
     */
    public function test_manufacturer_matching_more_than_one_modem_type_returns_null(): void
    {
        $tenant = Tenant::factory()->create();
        ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG']);
        ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG,CIOT']);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'manufacturer' => 'ZICG']);

        $this->assertNull($this->service()->suggestFor($device));
    }

    /**
     * ModemType nonaktif tidak boleh ikut jadi kandidat match, meski
     * pattern-nya cocok.
     */
    public function test_an_inactive_modem_type_is_never_a_match_candidate(): void
    {
        $tenant = Tenant::factory()->create();
        ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG', 'is_active' => false]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'manufacturer' => 'ZICG']);

        $this->assertNull($this->service()->suggestFor($device));
    }
}
