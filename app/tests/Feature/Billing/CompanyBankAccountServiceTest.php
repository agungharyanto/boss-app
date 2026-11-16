<?php

namespace Tests\Feature\Billing;

use App\Models\CompanyBankAccount;
use App\Models\CompanyBankAccountLog;
use App\Models\CompanyProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Company\CompanyBankAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * v0.13.5.4 — rekening multi-bank per tenant.
 */
class CompanyBankAccountServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CompanyBankAccountService
    {
        return app(CompanyBankAccountService::class);
    }

    private function data(array $o = []): array
    {
        return array_merge([
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder' => 'PT Uji',
        ], $o);
    }

    public function test_first_account_is_default_and_active(): void
    {
        $tenant = Tenant::factory()->create();
        $acc = $this->service()->create($tenant->id, $this->data());

        $this->assertTrue($acc->is_default);
        $this->assertTrue($acc->is_active);
    }

    public function test_second_account_is_not_default(): void
    {
        $tenant = Tenant::factory()->create();
        $svc = $this->service();
        $svc->create($tenant->id, $this->data());
        $second = $svc->create($tenant->id, $this->data(['bank_name' => 'Mandiri', 'account_number' => '999']));

        $this->assertFalse($second->is_default);
        $this->assertEquals(1, CompanyBankAccount::where('tenant_id', $tenant->id)->where('is_default', true)->count());
    }

    public function test_make_default_switches_exactly_one(): void
    {
        $tenant = Tenant::factory()->create();
        $svc = $this->service();
        $a = $svc->create($tenant->id, $this->data());
        $b = $svc->create($tenant->id, $this->data(['bank_name' => 'Mandiri', 'account_number' => '999']));

        $svc->makeDefault($b);

        $this->assertFalse($a->fresh()->is_default);
        $this->assertTrue($b->fresh()->is_default);
        $this->assertEquals(1, CompanyBankAccount::where('tenant_id', $tenant->id)->where('is_default', true)->count());
    }

    public function test_deleting_default_is_blocked_when_others_exist(): void
    {
        $tenant = Tenant::factory()->create();
        $svc = $this->service();
        $a = $svc->create($tenant->id, $this->data());
        $svc->create($tenant->id, $this->data(['bank_name' => 'Mandiri', 'account_number' => '999']));

        $this->expectException(RuntimeException::class);
        $svc->delete($a);
    }

    public function test_deleting_the_only_account_is_allowed(): void
    {
        $tenant = Tenant::factory()->create();
        $svc = $this->service();
        $a = $svc->create($tenant->id, $this->data());

        $svc->delete($a);
        $this->assertDatabaseMissing('company_bank_accounts', ['id' => $a->id]);
    }

    public function test_deactivating_default_is_blocked_when_others_exist(): void
    {
        $tenant = Tenant::factory()->create();
        $svc = $this->service();
        $a = $svc->create($tenant->id, $this->data());
        $svc->create($tenant->id, $this->data(['bank_name' => 'Mandiri', 'account_number' => '999']));

        $this->expectException(RuntimeException::class);
        $svc->setActive($a, false);
    }

    public function test_invalid_account_number_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $this->expectException(ValidationException::class);
        $this->service()->create($tenant->id, $this->data(['account_number' => 'ABC$$']));
    }

    public function test_each_change_writes_a_masked_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $svc = $this->service();
        $svc->create($tenant->id, $this->data(['account_number' => '1234567890']), $user->id);

        $log = CompanyBankAccountLog::where('tenant_id', $tenant->id)->where('action', 'created')->first();
        $this->assertNotNull($log);
        $this->assertSame($user->id, $log->user_id);
        // Nomor ter-mask: hanya 4 digit terakhir.
        $this->assertSame('******7890', $log->after['account_number']);
        $this->assertStringNotContainsString('123456', $log->after['account_number']);
    }

    public function test_tenant_isolation(): void
    {
        $t1 = Tenant::factory()->create();
        $t2 = Tenant::factory()->create();
        $svc = $this->service();
        $svc->create($t1->id, $this->data());
        $svc->create($t2->id, $this->data(['bank_name' => 'BNI']));

        $this->assertCount(1, $svc->activeAccounts($t1->id));
        $this->assertSame('BCA', $svc->activeAccounts($t1->id)->first()->bank_name);
    }

    public function test_backfill_from_legacy_profile_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        CompanyProfile::create([
            'tenant_id' => $tenant->id,
            'bank_name' => 'BRI',
            'bank_account_number' => '55667788',
            'bank_account_holder' => 'PT Lama',
        ]);

        $svc = $this->service();
        $first = $svc->backfillFromProfile($tenant->id);
        $this->assertNotNull($first);
        $this->assertTrue($first->is_default);
        $this->assertSame('BRI', $first->bank_name);

        // Idempoten: panggil lagi → tidak buat baris kedua.
        $this->assertNull($svc->backfillFromProfile($tenant->id));
        $this->assertEquals(1, CompanyBankAccount::where('tenant_id', $tenant->id)->count());
    }

    public function test_backfill_skips_when_no_legacy_bank(): void
    {
        $tenant = Tenant::factory()->create();
        CompanyProfile::create(['tenant_id' => $tenant->id, 'legal_name' => 'PT X']);

        $this->assertNull($this->service()->backfillFromProfile($tenant->id));
    }
}
