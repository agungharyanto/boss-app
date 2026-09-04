<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionScheme;
use App\Enums\NetworkProfileGroupType;
use App\Enums\ReferrerType;
use App\Enums\TitipDepositStatus;
use App\Models\BandwidthProfile;
use App\Models\CommissionLedger;
use App\Models\CommissionRate;
use App\Models\Customer;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Commission\SubscriptionRenewalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionRenewalServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private SubscriptionRenewalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->service = app(SubscriptionRenewalService::class);
    }

    private function package(?float $titip, string $name = 'Paket', float $sellPrice = 150000): PppPackage
    {
        $group = NetworkProfileGroup::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => NetworkProfileGroupType::Ppp,
        ]);

        $package = PppPackage::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'is_active' => true,
            'sell_price' => $sellPrice,
            'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['tenant_id' => $this->tenant->id])->id,
        ]);

        CommissionRate::factory()->create([
            'ppp_package_id' => $package->id,
            'recurring_amount' => 5000,
            'titip_amount' => $titip,
            'is_active' => true,
        ]);

        return $package;
    }

    private function actingUser(): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_renewal_by_a_user_with_no_linked_referrer_records_the_renewal_without_a_commission(): void
    {
        $user = $this->actingUser();
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $this->package(3000)->id,
        ]);

        $result = $this->service->renew($user, $customer, null);

        $this->assertFalse($result['commission_created']);
        $this->assertNotNull($result['commission_skipped_reason']);
        $this->assertDatabaseMissing('commission_ledger', [
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value,
        ]);
        $this->assertDatabaseHas('customer_timeline_entries', [
            'customer_id' => $customer->id,
            'event_type' => 'subscription_renewed',
        ]);
    }

    public function test_a_sales_referrer_without_a_titip_rate_on_the_package_still_renews_without_a_commission(): void
    {
        $user = $this->actingUser();
        Referrer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'type' => ReferrerType::Sales,
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $this->package(null)->id, // no titip_amount
        ]);

        $result = $this->service->renew($user, $customer, null);

        $this->assertFalse($result['commission_created']);
        $this->assertDatabaseMissing('commission_ledger', [
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value,
        ]);
    }

    public function test_changing_the_package_updates_only_ppp_package_id(): void
    {
        $user = $this->actingUser();
        $from = $this->package(3000, 'Lama');
        $to = $this->package(4000, 'Baru');
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $from->id,
            'name' => 'Tetap',
            'phone_number' => '081200000000',
        ]);

        $originalName = $customer->name;
        $originalPhone = $customer->phone_number;

        $result = $this->service->renew($user, $customer, $to->id);

        $customer->refresh();
        $this->assertSame($to->id, $customer->ppp_package_id);
        $this->assertSame($originalName, $customer->name);
        $this->assertSame($originalPhone, $customer->phone_number);
        $this->assertTrue($result['package_changed']);
        $this->assertSame('Baru', $result['package_to']);
    }

    public function test_a_package_from_another_tenant_is_rejected(): void
    {
        $user = $this->actingUser();
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $this->package(3000)->id,
        ]);
        $foreignTenant = Tenant::factory()->create();
        $foreignPackage = PppPackage::factory()->create([
            'tenant_id' => $foreignTenant->id,
            'is_active' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service->renew($user, $customer, $foreignPackage->id);
    }

    public function test_titip_row_snapshots_gross_amount_from_the_current_package_sell_price(): void
    {
        $user = $this->actingUser();
        Referrer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'type' => ReferrerType::Sales,
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $this->package(3000, 'Paket', 175000)->id,
        ]);

        $this->service->renew($user, $customer, null);

        $row = CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->where('scheme', CommissionScheme::Titip->value)
            ->sole();

        $this->assertSame('175000.00', $row->gross_amount);
        $this->assertSame('3000.00', $row->amount);
        $this->assertSame(TitipDepositStatus::BelumSetor, $row->deposit_status);
    }

    public function test_gross_amount_uses_the_new_package_sell_price_when_the_package_is_changed(): void
    {
        $user = $this->actingUser();
        Referrer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'type' => ReferrerType::Freelance,
            'is_active' => true,
        ]);
        $from = $this->package(3000, 'Lama', 100000);
        $to = $this->package(4000, 'Baru', 250000);
        $customer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $from->id,
        ]);

        $result = $this->service->renew($user, $customer, $to->id);

        $row = CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->where('scheme', CommissionScheme::Titip->value)
            ->sole();

        $this->assertSame('250000.00', $row->gross_amount);
        $this->assertSame('4000.00', $row->amount);
        $this->assertSame(250000.0, $result['commission_gross_amount']);
    }

    public function test_the_service_has_no_network_gateway_dependency(): void
    {
        $ctor = (new \ReflectionClass(SubscriptionRenewalService::class))->getConstructor();

        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            $this->assertStringNotContainsString('App\\Services\\Network', $name);
            $this->assertStringNotContainsString('RouterOs', $name);
        }
    }
}
