<?php

namespace Tests\Feature\Network;

use App\Models\CpeBindingRejection;
use App\Models\CpeDevice;
use App\Models\Customer;
use App\Models\LegacyMacCustomerMap;
use App\Models\Tenant;
use App\Services\Network\LegacyDeviceMatcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LegacyDeviceMatcherServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array<string, mixed>>  $devices
     */
    private function fakeGenieAcsDevices(array $devices): void
    {
        Http::fake([
            'genieacs-nbi:7557/devices*' => Http::response($devices, 200),
        ]);
    }

    private function genieAcsDevice(string $oui, string $productClass, string $serial): array
    {
        return [
            '_id' => "{$oui}-{$productClass}-{$serial}",
            '_deviceId' => ['_OUI' => $oui, '_ProductClass' => $productClass, '_SerialNumber' => $serial],
        ];
    }

    public function test_exact_match_binds_with_exact_confidence(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'ISP Demo']);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'legacy_username' => '081234567890']);

        LegacyMacCustomerMap::factory()->create([
            'mac_address' => 'A4:F3:3B:6C:2E:7B',
            'legacy_username' => '081234567890',
        ]);

        $this->fakeGenieAcsDevices([
            $this->genieAcsDevice('A4F33B', 'GM220-S', 'SNEXACT6C2E7B'),
        ]);

        $bound = app(LegacyDeviceMatcherService::class)->matchAndBind();

        $this->assertSame(1, $bound);
        $this->assertDatabaseHas('cpe_devices', [
            'serial_number' => 'SNEXACT6C2E7B',
            'customer_id' => $customer->id,
            'import_match_confidence' => 'exact',
        ]);
    }

    public function test_one_hex_digit_off_binds_with_close_1_confidence(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'ISP Demo']);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'legacy_username' => '081234567890']);

        LegacyMacCustomerMap::factory()->create([
            'mac_address' => 'A4:F3:3B:6C:2E:7B',
            'legacy_username' => '081234567890',
        ]);

        // Tail 6C2E7C vs reference 6C2E7B — one hex digit differs.
        $this->fakeGenieAcsDevices([
            $this->genieAcsDevice('A4F33B', 'GM220-S', 'SNCLOSE6C2E7C'),
        ]);

        $bound = app(LegacyDeviceMatcherService::class)->matchAndBind();

        $this->assertSame(1, $bound);
        $this->assertDatabaseHas('cpe_devices', [
            'serial_number' => 'SNCLOSE6C2E7C',
            'customer_id' => $customer->id,
            'import_match_confidence' => 'close_1',
        ]);
    }

    public function test_two_hex_digits_off_binds_with_close_2_confidence(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'ISP Demo']);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'legacy_username' => '081234567890']);

        LegacyMacCustomerMap::factory()->create([
            'mac_address' => 'A4:F3:3B:6C:2E:7B',
            'legacy_username' => '081234567890',
        ]);

        // Tail 6C2E8C vs reference 6C2E7B — two hex digits differ.
        $this->fakeGenieAcsDevices([
            $this->genieAcsDevice('A4F33B', 'GM220-S', 'SNCLOSE6C2E8C'),
        ]);

        $bound = app(LegacyDeviceMatcherService::class)->matchAndBind();

        $this->assertSame(1, $bound);
        $this->assertDatabaseHas('cpe_devices', [
            'serial_number' => 'SNCLOSE6C2E8C',
            'customer_id' => $customer->id,
            'import_match_confidence' => 'close_2',
        ]);
    }

    public function test_three_or_more_hex_digits_off_is_not_considered_a_match(): void
    {
        Tenant::factory()->create(['name' => 'ISP Demo']);

        LegacyMacCustomerMap::factory()->create([
            'mac_address' => 'A4:F3:3B:6C:2E:7B',
            'legacy_username' => '081234567890',
        ]);

        // Tail 111111 vs reference 6C2E7B — every hex digit differs (distance 6).
        $this->fakeGenieAcsDevices([
            $this->genieAcsDevice('A4F33B', 'GM220-S', 'SNNOMATCH111111'),
        ]);

        $bound = app(LegacyDeviceMatcherService::class)->matchAndBind();

        $this->assertSame(0, $bound);
        $this->assertDatabaseMissing('cpe_devices', ['serial_number' => 'SNNOMATCH111111']);
    }

    public function test_a_matched_mac_with_no_corresponding_customer_does_not_error(): void
    {
        Tenant::factory()->create(['name' => 'ISP Demo']);

        // No Customer exists with this legacy_username at all.
        LegacyMacCustomerMap::factory()->create([
            'mac_address' => 'A4:F3:3B:6C:2E:7B',
            'legacy_username' => 'no-such-customer',
        ]);

        $this->fakeGenieAcsDevices([
            $this->genieAcsDevice('A4F33B', 'GM220-S', 'SNORPHAN6C2E7B'),
        ]);

        $bound = app(LegacyDeviceMatcherService::class)->matchAndBind();

        $this->assertSame(0, $bound);
        $this->assertDatabaseMissing('cpe_devices', ['serial_number' => 'SNORPHAN6C2E7B']);
    }

    public function test_a_device_already_bound_is_skipped_not_reprocessed(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'ISP Demo']);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'legacy_username' => '081234567890']);
        $otherCustomer = Customer::factory()->create(['tenant_id' => $tenant->id]);

        LegacyMacCustomerMap::factory()->create([
            'mac_address' => 'A4:F3:3B:6C:2E:7B',
            'legacy_username' => '081234567890',
        ]);

        $genieAcsId = 'A4F33B-GM220-S-SNALREADYBOUND';
        CpeDevice::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $otherCustomer->id,
            'genieacs_device_id' => $genieAcsId,
            'serial_number' => 'SNALREADYBOUND',
        ]);

        $this->fakeGenieAcsDevices([[
            '_id' => $genieAcsId,
            '_deviceId' => ['_OUI' => 'A4F33B', '_ProductClass' => 'GM220-S', '_SerialNumber' => 'SNALREADYBOUND'],
        ]]);

        $bound = app(LegacyDeviceMatcherService::class)->matchAndBind();

        $this->assertSame(0, $bound);
        // Still attributed to the original customer — never reassigned.
        $this->assertDatabaseHas('cpe_devices', [
            'genieacs_device_id' => $genieAcsId,
            'customer_id' => $otherCustomer->id,
        ]);
        $this->assertDatabaseMissing('cpe_devices', ['customer_id' => $customer->id]);
    }

    public function test_genieacs_internal_probe_and_discovery_entries_are_never_matched(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'ISP Demo']);
        Customer::factory()->create(['tenant_id' => $tenant->id, 'legacy_username' => '081234567890']);

        LegacyMacCustomerMap::factory()->create([
            'mac_address' => 'A4:F3:3B:00:00:01',
            'legacy_username' => '081234567890',
        ]);

        $this->fakeGenieAcsDevices([
            [
                '_id' => 'DISCOVERYSERVICE-DISCOVERYSERVICE-abcdef',
                '_deviceId' => ['_OUI' => 'DISCOVERYSERVICE', '_ProductClass' => 'DISCOVERYSERVICE', '_SerialNumber' => 'abcdef'],
            ],
            [
                '_id' => '000000-probe-000001',
                '_deviceId' => ['_OUI' => '000000', '_ProductClass' => 'probe', '_SerialNumber' => '000001'],
            ],
        ]);

        $bound = app(LegacyDeviceMatcherService::class)->matchAndBind();

        $this->assertSame(0, $bound);
        $this->assertDatabaseCount('cpe_devices', 0);
    }

    public function test_a_previously_rejected_pair_is_never_re_matched(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'ISP Demo']);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'legacy_username' => '081234567890']);

        LegacyMacCustomerMap::factory()->create([
            'mac_address' => 'A4:F3:3B:6C:2E:7B',
            'legacy_username' => '081234567890',
        ]);

        $genieAcsId = 'A4F33B-GM220-S-SNREJECTED6C2E7B';
        CpeBindingRejection::factory()->create([
            'tenant_id' => $tenant->id,
            'genieacs_device_id' => $genieAcsId,
            'customer_id' => $customer->id,
        ]);

        $this->fakeGenieAcsDevices([[
            '_id' => $genieAcsId,
            '_deviceId' => ['_OUI' => 'A4F33B', '_ProductClass' => 'GM220-S', '_SerialNumber' => 'SNREJECTED6C2E7B'],
        ]]);

        $bound = app(LegacyDeviceMatcherService::class)->matchAndBind();

        $this->assertSame(0, $bound);
        $this->assertDatabaseMissing('cpe_devices', ['serial_number' => 'SNREJECTED6C2E7B']);
    }

    /**
     * Confirms the rejection is keyed to the SPECIFIC (genieacs_device_id,
     * customer_id) pair, not to the customer alone — a brand new modem
     * (different serial/genieacs_device_id) for the SAME customer must
     * still match and bind normally. This is what makes "Ganti Modem"
     * (App\Livewire\Network\CpeDeviceIndex::replaceModem()) safe: it
     * deliberately does NOT write a rejection row, and this test proves
     * that even if it had, a genuinely different device id would be
     * unaffected anyway.
     */
    public function test_a_rejection_for_one_device_never_blocks_a_different_device_for_the_same_customer(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'ISP Demo']);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'legacy_username' => '081234567890']);

        // Rejection recorded against an OLD device id.
        CpeBindingRejection::factory()->create([
            'tenant_id' => $tenant->id,
            'genieacs_device_id' => 'A4F33B-GM220-S-SNOLDREJECTED',
            'customer_id' => $customer->id,
        ]);

        LegacyMacCustomerMap::factory()->create([
            'mac_address' => 'A4:F3:3B:6C:2E:7B',
            'legacy_username' => '081234567890',
        ]);

        // A completely different, NEW device (new serial -> new
        // genieacs_device_id) that legitimately matches the SAME customer.
        $this->fakeGenieAcsDevices([[
            '_id' => 'A4F33B-GM220-S-SNBRANDNEWMODEM6C2E7B',
            '_deviceId' => ['_OUI' => 'A4F33B', '_ProductClass' => 'GM220-S', '_SerialNumber' => 'SNBRANDNEWMODEM6C2E7B'],
        ]]);

        $bound = app(LegacyDeviceMatcherService::class)->matchAndBind();

        $this->assertSame(1, $bound);
        $this->assertDatabaseHas('cpe_devices', [
            'serial_number' => 'SNBRANDNEWMODEM6C2E7B',
            'customer_id' => $customer->id,
        ]);
    }

    public function test_returns_zero_when_no_legacy_mac_reference_has_been_imported_yet(): void
    {
        Tenant::factory()->create(['name' => 'ISP Demo']);

        $this->fakeGenieAcsDevices([
            $this->genieAcsDevice('A4F33B', 'GM220-S', 'SNWHATEVER123456'),
        ]);

        $bound = app(LegacyDeviceMatcherService::class)->matchAndBind();

        $this->assertSame(0, $bound);
    }
}
