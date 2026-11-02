<?php

namespace Tests\Feature\Company;

use App\Models\InstallationTerms;
use App\Models\Tenant;
use App\Services\Company\InstallationTermsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallationTermsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): InstallationTermsService
    {
        return app(InstallationTermsService::class);
    }

    public function test_active_is_null_before_any_version_exists(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertNull($this->service()->active($tenant->id));
    }

    public function test_save_new_version_increments_and_is_insert_only(): void
    {
        $tenant = Tenant::factory()->create();

        $v1 = $this->service()->saveNewVersion($tenant->id, 'Teks versi satu', null);
        $v2 = $this->service()->saveNewVersion($tenant->id, 'Teks versi dua', null);

        $this->assertSame(1, $v1->version);
        $this->assertSame(2, $v2->version);

        // Insert-only: baris v1 tidak berubah.
        $this->assertSame('Teks versi satu', $v1->fresh()->body);
        $this->assertSame(2, InstallationTerms::where('tenant_id', $tenant->id)->count());

        // active = versi tertinggi.
        $this->assertSame(2, $this->service()->active($tenant->id)->version);
        $this->assertSame('Teks versi dua', $this->service()->active($tenant->id)->body);
    }

    public function test_versions_are_scoped_per_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $this->service()->saveNewVersion($a->id, 'A1', null);
        $this->service()->saveNewVersion($a->id, 'A2', null);
        $this->service()->saveNewVersion($b->id, 'B1', null);

        $this->assertSame(2, $this->service()->active($a->id)->version);
        $this->assertSame(1, $this->service()->active($b->id)->version);
    }

    public function test_history_is_ordered_newest_version_first(): void
    {
        $tenant = Tenant::factory()->create();
        $this->service()->saveNewVersion($tenant->id, 'satu', null);
        $this->service()->saveNewVersion($tenant->id, 'dua', null);

        $history = $this->service()->history($tenant->id);

        $this->assertSame([2, 1], $history->pluck('version')->all());
    }
}
