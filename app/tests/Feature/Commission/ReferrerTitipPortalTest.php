<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionScheme;
use App\Enums\CommissionStatus;
use App\Enums\NetworkProfileGroupType;
use App\Enums\WhatsappEventType;
use App\Livewire\ReferrerPortal\Dashboard;
use App\Models\BandwidthProfile;
use App\Models\CommissionLedger;
use App\Models\CommissionRate;
use App\Models\Customer;
use App\Models\NetworkProfileGroup;
use App\Models\PppPackage;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Services\Commission\ReferrerActionOtpService;
use App\Services\Commission\ReferrerOtpException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.9.6 — alur "Catat Titip" self-service di Portal Referrer.
 */
class ReferrerTitipPortalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Referrer $referrer;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->tenant = Tenant::factory()->create();

        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->referrer = Referrer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'phone' => '081200000001',
        ]);

        WhatsappMessageTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'event_type' => WhatsappEventType::ReferrerActionOtp,
            'content' => 'Kode {otp_code} berlaku {otp_minutes} menit — {referrer_name}. Untuk: {action_label}.',
            'is_active' => true,
        ]);
    }

    private function packageWithTitipRate(?float $titip): PppPackage
    {
        $group = NetworkProfileGroup::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => NetworkProfileGroupType::Ppp,
        ]);

        $package = PppPackage::factory()->create([
            'tenant_id' => $this->tenant->id,
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

    private function referredCustomer(?PppPackage $package): Customer
    {
        return Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $package?->id,
            'referred_by_referrer_id' => $this->referrer->id,
        ]);
    }

    private function otpCodeFor(Customer $customer): string
    {
        return Cache::get("referrer-otp:{$this->referrer->id}:titip:{$customer->id}")['code'];
    }

    public function test_titip_is_not_available_for_a_customer_without_a_ppp_package(): void
    {
        $customer = $this->referredCustomer(null);

        Livewire::actingAs($this->referrer->user)
            ->test(Dashboard::class)
            ->assertSee('Belum tersedia')
            ->assertDontSee('Catat Titip');
    }

    public function test_titip_is_not_available_when_rate_has_no_titip_amount(): void
    {
        $customer = $this->referredCustomer($this->packageWithTitipRate(null));

        Livewire::actingAs($this->referrer->user)
            ->test(Dashboard::class)
            ->assertSee('Belum tersedia')
            ->assertDontSee('Catat Titip');
    }

    public function test_full_flow_with_correct_otp_creates_an_eligible_titip_row(): void
    {
        $customer = $this->referredCustomer($this->packageWithTitipRate(3000));

        $component = Livewire::actingAs($this->referrer->user)
            ->test(Dashboard::class)
            ->assertSee('Catat Titip')
            ->call('startTitip', $customer->id)
            ->assertSet('titipStage', 'confirm')
            ->call('sendTitipOtp')
            ->assertSet('titipStage', 'otp');

        $this->assertDatabaseHas('whatsapp_message_logs', [
            'phone_number' => '081200000001',
            'event_type' => WhatsappEventType::ReferrerActionOtp->value,
            'customer_id' => $customer->id,
        ]);

        // {action_label} dirender dengan konteks Titip yang benar (bukan
        // frasa Titip yang di-hardcode di template default v0.9.6 awal).
        $otpLog = WhatsappMessageLog::withoutGlobalScopes()
            ->where('event_type', WhatsappEventType::ReferrerActionOtp->value)->sole();
        $this->assertStringContainsString("mencatat titip pembayaran untuk {$customer->name}", $otpLog->rendered_content);

        $component->set('otpCode', $this->otpCodeFor($customer))
            ->call('submitTitip')
            ->assertSet('titipStage', '')
            ->assertSet('titipCustomerId', null);

        $row = CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->where('scheme', CommissionScheme::Titip->value)
            ->sole();

        $this->assertSame($this->referrer->id, $row->referrer_id);
        $this->assertSame(CommissionStatus::Eligible, $row->status);
        $this->assertSame('3000.00', $row->amount);
        $this->assertNull($row->invoice_id);
        $this->assertSame(now()->startOfMonth()->toDateString(), $row->payment_period->toDateString());
    }

    public function test_wrong_otp_is_rejected_and_no_row_is_created(): void
    {
        $customer = $this->referredCustomer($this->packageWithTitipRate(3000));

        Livewire::actingAs($this->referrer->user)
            ->test(Dashboard::class)
            ->call('startTitip', $customer->id)
            ->call('sendTitipOtp')
            ->set('otpCode', '000000')
            ->call('submitTitip')
            ->assertHasErrors('otpCode')
            ->assertSet('titipStage', 'otp');

        $this->assertDatabaseMissing('commission_ledger', [
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value,
        ]);
    }

    public function test_submitting_without_ever_requesting_a_code_is_rejected(): void
    {
        $customer = $this->referredCustomer($this->packageWithTitipRate(3000));

        // Force the component into the otp stage without a cached code by
        // issuing then clearing it.
        $component = Livewire::actingAs($this->referrer->user)
            ->test(Dashboard::class)
            ->call('startTitip', $customer->id)
            ->call('sendTitipOtp');

        Cache::forget("referrer-otp:{$this->referrer->id}:titip:{$customer->id}");

        $component->set('otpCode', '123456')
            ->call('submitTitip')
            ->assertHasErrors('otpCode');

        $this->assertDatabaseMissing('commission_ledger', [
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value,
        ]);
    }

    public function test_duplicate_this_month_shows_a_warning_and_blocks_until_acknowledged(): void
    {
        $customer = $this->referredCustomer($this->packageWithTitipRate(3000));

        CommissionLedger::factory()->create([
            'tenant_id' => $this->tenant->id,
            'referrer_id' => $this->referrer->id,
            'customer_id' => $customer->id,
            'scheme' => CommissionScheme::Titip->value,
            'status' => CommissionStatus::Eligible,
            'amount' => 3000,
            'payment_period' => now()->startOfMonth()->toDateString(),
        ]);

        $component = Livewire::actingAs($this->referrer->user)
            ->test(Dashboard::class)
            ->call('startTitip', $customer->id)
            ->assertSet('titipDuplicateWarning', true)
            ->call('sendTitipOtp')
            ->assertHasErrors('titipDuplicateAcknowledged')
            ->assertSet('titipStage', 'confirm');

        // Acknowledge, then it proceeds.
        $component->set('titipDuplicateAcknowledged', true)
            ->call('sendTitipOtp')
            ->assertSet('titipStage', 'otp');

        $component->set('otpCode', $this->otpCodeFor($customer))
            ->call('submitTitip');

        $this->assertSame(2, CommissionLedger::withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->where('scheme', CommissionScheme::Titip->value)
            ->count());
    }

    public function test_a_referrer_cannot_target_a_customer_they_did_not_refer(): void
    {
        $otherCustomer = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'reseller_id' => null,
            'ppp_package_id' => $this->packageWithTitipRate(3000)->id,
            'referred_by_referrer_id' => null,
        ]);

        Livewire::actingAs($this->referrer->user)
            ->test(Dashboard::class)
            ->call('startTitip', $otherCustomer->id)
            ->assertStatus(404);
    }

    public function test_dashboard_has_no_edit_or_delete_action_for_titip_rows(): void
    {
        // CREATE-ONLY (CLAUDE.md): the portal component must expose no
        // mutation of an existing commission_ledger row.
        $methods = get_class_methods(Dashboard::class);

        foreach (['deleteTitip', 'editTitip', 'updateTitip', 'destroyTitip', 'cancelLedger'] as $forbidden) {
            $this->assertNotContains($forbidden, $methods);
        }
    }

    public function test_resend_is_rate_limited_after_three_sends(): void
    {
        $customer = $this->referredCustomer($this->packageWithTitipRate(3000));
        $otp = app(ReferrerActionOtpService::class);
        $scope = "titip:{$customer->id}";

        $otp->issue($this->referrer, $scope, 'titip', $customer);
        $otp->issue($this->referrer, $scope, 'titip', $customer);
        $otp->issue($this->referrer, $scope, 'titip', $customer);

        $this->expectException(ReferrerOtpException::class);
        $otp->issue($this->referrer, $scope, 'titip', $customer);
    }
}
