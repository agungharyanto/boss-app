<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerNikEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_nik_is_stored_encrypted_but_reads_back_as_plaintext_through_eloquent(): void
    {
        $customer = Customer::factory()->create(['nik' => '3201012501990001']);

        $this->assertSame('3201012501990001', $customer->fresh()->nik);

        $rawValue = DB::table('customers')->where('id', $customer->id)->value('nik');

        $this->assertNotSame('3201012501990001', $rawValue);
        $this->assertStringNotContainsString('3201012501990001', $rawValue);
    }

    public function test_nik_hash_is_derived_automatically_and_matches_the_configured_hmac(): void
    {
        $customer = Customer::factory()->create(['nik' => '3201012501990001']);

        $expected = hash_hmac('sha256', '3201012501990001', config('app.nik_hmac_key'));

        $this->assertSame($expected, DB::table('customers')->where('id', $customer->id)->value('nik_hash'));
    }

    public function test_nik_hash_is_null_when_nik_is_null(): void
    {
        $customer = Customer::factory()->create(['nik' => null]);

        $this->assertNull(DB::table('customers')->where('id', $customer->id)->value('nik_hash'));
    }

    public function test_nik_hash_cannot_be_set_directly_via_mass_assignment(): void
    {
        $customer = Customer::factory()->create([
            'nik' => '3201012501990001',
            'nik_hash' => 'tampered-value',
        ]);

        $expected = hash_hmac('sha256', '3201012501990001', config('app.nik_hmac_key'));

        $this->assertSame($expected, DB::table('customers')->where('id', $customer->id)->value('nik_hash'));
    }

    public function test_nik_hash_is_recomputed_when_nik_changes(): void
    {
        $customer = Customer::factory()->create(['nik' => '3201012501990001']);

        $customer->update(['nik' => '3201019909012001']);

        $expected = hash_hmac('sha256', '3201019909012001', config('app.nik_hmac_key'));

        $this->assertSame($expected, DB::table('customers')->where('id', $customer->id)->value('nik_hash'));
    }

    public function test_backfill_command_is_idempotent_and_recovers_a_pre_migration_plaintext_row(): void
    {
        $tenant = Tenant::factory()->create();

        // Simulate a row written before the `encrypted` cast was deployed
        // on some server — raw plaintext in `nik`, no `nik_hash` at all.
        // This bypasses the model entirely (that's the point).
        $id = DB::table('customers')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => 'Legacy Row',
            'address' => 'Jl. Legacy No. 1',
            'phone_number' => '081200000099',
            'status' => 'prospek',
            'registration_status' => 'registered',
            'nik' => '3201011111220002',
            'nik_hash' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('customers:backfill-nik-hash');

        $expected = hash_hmac('sha256', '3201011111220002', config('app.nik_hmac_key'));
        $this->assertSame($expected, DB::table('customers')->where('id', $id)->value('nik_hash'));

        $rawNik = DB::table('customers')->where('id', $id)->value('nik');
        $this->assertNotSame('3201011111220002', $rawNik);

        $customer = Customer::withoutGlobalScopes()->find($id);
        $this->assertSame('3201011111220002', $customer->nik);

        // Second run must be a no-op — nothing left matching whereNull(nik_hash).
        Artisan::call('customers:backfill-nik-hash');
        $this->assertStringContainsString('Tidak ada customer', Artisan::output());
    }
}
