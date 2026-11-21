<?php

namespace Tests\Feature\Customers;

use App\Enums\CustomerOptOutAction;
use App\Exceptions\CustomerOptOutException;
use App\Models\Customer;
use App\Models\CustomerNotificationOptOutLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Customers\CustomerNotificationOptOutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * v0.28.1 (T1) — service opt-out/opt-in notifikasi otomatis. Tiap toggle =
 * TEPAT SATU baris jejak append-only + flip flag, dalam satu transaksi. Status
 * sudah sama → tolak tanpa log. Alasan wajib (min 3). Model menolak update/delete.
 */
class CustomerNotificationOptOutServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: Customer, 2: User} */
    private function ctx(): array
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $customer, $user];
    }

    private function service(): CustomerNotificationOptOutService
    {
        return app(CustomerNotificationOptOutService::class);
    }

    public function test_opt_out_sets_flag_and_writes_exactly_one_log(): void
    {
        [$tenant, $customer, $user] = $this->ctx();

        $log = $this->service()->optOut($customer, 'permintaan pelanggan', $user);

        $this->assertTrue($customer->fresh()->auto_notify_opt_out);
        $this->assertSame(1, CustomerNotificationOptOutLog::count());
        $this->assertSame(CustomerOptOutAction::OptedOut, $log->action);
        $this->assertSame('permintaan pelanggan', $log->reason);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame($customer->id, $log->customer_id);
        $this->assertSame(['auto_notify_opt_out' => false], $log->before);
        $this->assertSame(['auto_notify_opt_out' => true], $log->after);
    }

    public function test_opt_in_after_opt_out_writes_a_second_log(): void
    {
        [, $customer, $user] = $this->ctx();

        $this->service()->optOut($customer, 'berhenti dulu', $user);
        $log = $this->service()->optIn($customer->fresh(), 'minta aktif lagi', $user);

        $this->assertFalse($customer->fresh()->auto_notify_opt_out);
        $this->assertSame(2, CustomerNotificationOptOutLog::count());
        $this->assertSame(CustomerOptOutAction::OptedIn, $log->action);
        $this->assertSame(['auto_notify_opt_out' => true], $log->before);
        $this->assertSame(['auto_notify_opt_out' => false], $log->after);
    }

    public function test_opt_out_when_already_opted_out_is_rejected_without_log(): void
    {
        [, $customer, $user] = $this->ctx();
        $this->service()->optOut($customer, 'awal', $user);

        try {
            $this->service()->optOut($customer->fresh(), 'lagi', $user);
            $this->fail('Seharusnya menolak opt-out ketika sudah opt-out.');
        } catch (CustomerOptOutException $e) {
            $this->assertStringContainsString('tidak berubah', $e->getMessage());
        }

        $this->assertSame(1, CustomerNotificationOptOutLog::count());
    }

    public function test_opt_in_when_already_opted_in_is_rejected_without_log(): void
    {
        [, $customer, $user] = $this->ctx();

        try {
            $this->service()->optIn($customer, 'sudah aktif', $user);
            $this->fail('Seharusnya menolak opt-in ketika sudah menerima.');
        } catch (CustomerOptOutException $e) {
            $this->assertStringContainsString('tidak berubah', $e->getMessage());
        }

        $this->assertSame(0, CustomerNotificationOptOutLog::count());
        $this->assertFalse($customer->fresh()->auto_notify_opt_out);
    }

    public function test_reason_too_short_is_rejected_without_any_change(): void
    {
        [, $customer, $user] = $this->ctx();

        try {
            $this->service()->optOut($customer, 'ab', $user);
            $this->fail('Seharusnya menolak alasan < 3 karakter.');
        } catch (CustomerOptOutException $e) {
            $this->assertStringContainsString('minimal', $e->getMessage());
        }

        $this->assertFalse($customer->fresh()->auto_notify_opt_out);
        $this->assertSame(0, CustomerNotificationOptOutLog::count());
    }

    public function test_reason_is_trimmed_before_length_check(): void
    {
        [, $customer, $user] = $this->ctx();

        // '  a  ' setelah trim = 'a' (1 char) → ditolak.
        try {
            $this->service()->optOut($customer, '  a  ', $user);
            $this->fail('Alasan setelah trim < 3 karakter seharusnya ditolak.');
        } catch (CustomerOptOutException $e) {
            // ok
        }

        $this->assertSame(0, CustomerNotificationOptOutLog::count());
    }

    public function test_log_refuses_update(): void
    {
        [, $customer, $user] = $this->ctx();
        $log = $this->service()->optOut($customer, 'awal', $user);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APPEND-ONLY');

        $log->update(['reason' => 'diubah']);
    }

    public function test_log_refuses_delete(): void
    {
        [, $customer, $user] = $this->ctx();
        $log = $this->service()->optOut($customer, 'awal', $user);

        try {
            $log->delete();
            $this->fail('Baris log seharusnya tidak bisa dihapus.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('APPEND-ONLY', $e->getMessage());
        }

        $this->assertSame(1, CustomerNotificationOptOutLog::count());
    }
}
