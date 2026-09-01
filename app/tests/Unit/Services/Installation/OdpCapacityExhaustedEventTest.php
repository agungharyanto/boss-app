<?php

namespace Tests\Unit\Services\Installation;

use App\Events\OdpCapacityExhausted;
use App\Listeners\NotifyOdpCapacityExhausted;
use App\Models\Customer;
use App\Models\Odp;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OdpCapacityExhaustedNotification;
use App\Services\Installation\OdpLocatorService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * v0.16.0 Core Network Infrastructure Management, Langkah 4. Deliberately
 * a SEPARATE test file from Tests\Unit\Services\Installation\
 * OdpLocatorServiceTest — that file's own 6 tests are left completely
 * untouched, zero diff, per the explicit "existing findNearestAvailable()
 * test tetap hijau tanpa perubahan assertion" instruction.
 */
class OdpCapacityExhaustedEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_event_is_dispatched_when_every_odp_port_in_scope_is_exhausted(): void
    {
        Event::fake([OdpCapacityExhausted::class]);
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'latitude' => -6.2000, 'longitude' => 106.8000]);
        // Deliberately zero Odp/OdpPort rows at all — every port is "exhausted".

        app(OdpLocatorService::class)->findNearestAvailable($customer);

        Event::assertDispatched(OdpCapacityExhausted::class, fn (OdpCapacityExhausted $event) => $event->customer->is($customer));
    }

    public function test_event_is_not_dispatched_when_customer_has_no_coordinates(): void
    {
        Event::fake([OdpCapacityExhausted::class]);
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'latitude' => null, 'longitude' => null]);

        app(OdpLocatorService::class)->findNearestAvailable($customer);

        Event::assertNotDispatched(OdpCapacityExhausted::class);
    }

    public function test_event_is_not_dispatched_when_an_available_port_is_actually_found(): void
    {
        Event::fake([OdpCapacityExhausted::class]);
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'latitude' => -6.2000, 'longitude' => 106.8000]);
        $odp = Odp::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null, 'latitude' => -6.2001, 'longitude' => 106.8001]);
        $odp->provisionPorts();

        app(OdpLocatorService::class)->findNearestAvailable($customer);

        Event::assertNotDispatched(OdpCapacityExhausted::class);
    }

    public function test_listener_sends_a_database_notification_only_to_same_tenant_users_with_manage_permission(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'reseller_id' => null]);

        $eligibleAdmin = User::factory()->create(['tenant_id' => $tenant->id]);
        $eligibleAdmin->assignRole('superadmin');

        $otherTenantAdmin = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $otherTenantAdmin->assignRole('superadmin');

        $nonAdminSameTenant = User::factory()->create(['tenant_id' => $tenant->id]);

        (new NotifyOdpCapacityExhausted)->handle(new OdpCapacityExhausted($customer));

        Notification::assertSentTo($eligibleAdmin, OdpCapacityExhaustedNotification::class);
        Notification::assertNotSentTo($otherTenantAdmin, OdpCapacityExhaustedNotification::class);
        Notification::assertNotSentTo($nonAdminSameTenant, OdpCapacityExhaustedNotification::class);
    }
}
