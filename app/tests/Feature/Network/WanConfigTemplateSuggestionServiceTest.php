<?php

namespace Tests\Feature\Network;

use App\Models\CpeDevice;
use App\Models\ModemType;
use App\Models\Tenant;
use App\Models\WanConfigTemplate;
use App\Services\Network\WanConfigTemplateSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v0.12.5 — WanConfigTemplateSuggestionService adalah BEST-EFFORT, tidak
 * pernah garansi match. Setiap skenario ambigu HARUS kembalikan null
 * (serahkan ke manual) — bukan menebak salah satu.
 */
class WanConfigTemplateSuggestionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): WanConfigTemplateSuggestionService
    {
        return app(WanConfigTemplateSuggestionService::class);
    }

    public function test_single_unambiguous_match_returns_the_template(): void
    {
        $tenant = Tenant::factory()->create();
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG,CIOT']);
        $template = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'modem_type_id' => $modemType->id, 'enabled' => true]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'manufacturer' => 'ZICG']);

        $result = $this->service()->suggestFor($device);

        $this->assertNotNull($result);
        $this->assertSame($template->id, $result->id);
    }

    /**
     * Normalisasi kapital/spasi — pattern lowercase dengan spasi tetap
     * match manufacturer device uppercase tanpa spasi, dan sebaliknya.
     */
    public function test_matching_is_case_and_whitespace_insensitive(): void
    {
        $tenant = Tenant::factory()->create();
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => ' zicg , ciot ']);
        $template = WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'modem_type_id' => $modemType->id, 'enabled' => true]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'manufacturer' => '  zicg  ']);

        $result = $this->service()->suggestFor($device);

        $this->assertNotNull($result);
        $this->assertSame($template->id, $result->id);
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
     * Ambigu di level Tipe Modem — dua ModemType berbeda sama-sama punya
     * pattern yang match manufacturer yang sama. Tidak ada "yang benar"
     * otomatis.
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
     * ModemType match tapi TIDAK punya template aktif sama sekali.
     */
    public function test_matching_modem_type_with_no_active_template_returns_null(): void
    {
        $tenant = Tenant::factory()->create();
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG']);
        WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'modem_type_id' => $modemType->id, 'enabled' => false]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'manufacturer' => 'ZICG']);

        $this->assertNull($this->service()->suggestFor($device));
    }

    /**
     * ModemType match tapi punya LEBIH DARI SATU template aktif — ambigu
     * di level Template, tidak ada "yang benar" otomatis.
     */
    public function test_matching_modem_type_with_more_than_one_active_template_returns_null(): void
    {
        $tenant = Tenant::factory()->create();
        $modemType = ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG']);
        WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'modem_type_id' => $modemType->id, 'enabled' => true, 'name' => 'A']);
        WanConfigTemplate::factory()->create(['tenant_id' => $tenant->id, 'modem_type_id' => $modemType->id, 'enabled' => true, 'name' => 'B']);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'manufacturer' => 'ZICG']);

        $this->assertNull($this->service()->suggestFor($device));
    }

    /**
     * Sebuah ModemType Tipe Modem yang NONAKTIF tidak boleh ikut jadi
     * kandidat match, meski pattern-nya cocok.
     */
    public function test_an_inactive_modem_type_is_never_a_match_candidate(): void
    {
        $tenant = Tenant::factory()->create();
        ModemType::factory()->create(['tenant_id' => $tenant->id, 'manufacturer_match_patterns' => 'ZICG', 'is_active' => false]);
        $device = CpeDevice::factory()->create(['tenant_id' => $tenant->id, 'manufacturer' => 'ZICG']);

        $this->assertNull($this->service()->suggestFor($device));
    }
}
