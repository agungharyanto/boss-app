<?php

namespace Tests\Feature\Network;

use App\Enums\NetworkProfileGroupType;
use App\Livewire\Network\BandwidthProfileIndex;
use App\Livewire\Network\CustomerIpPoolIndex;
use App\Livewire\Network\HotspotPackageIndex;
use App\Livewire\Network\NetworkProfileGroupIndex;
use App\Livewire\Network\PppPackageIndex;
use App\Models\BandwidthProfile;
use App\Models\CustomerIpPool;
use App\Models\Nas;
use App\Models\NetworkProfileGroup;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Revisi Pesan Error Bahasa Indonesia — bukti nyata (bukan cuma manual cek)
 * bahwa pesan validasi genuinely Bahasa Indonesia, DENGAN nama field yang
 * juga diterjemahkan (mis. "Harga Jual" bukan "sell price") — lintas
 * seluruh cluster "Profil Paket" (BOSS-003: cek satu-satu, bukan cuma yang
 * dilaporkan Agung), lewat REST API DAN Livewire.
 *
 * `app()->setLocale('id')` eksplisit di setUp() meski lingkungan test ini
 * genuinely SUDAH default ke 'id' (root .env APP_LOCALE=id, dikonfirmasi
 * langsung sebelum revisi ini ditulis) — eksplisit di sini supaya test ini
 * tetap benar kalaupun default lingkungan berubah nanti.
 */
class ValidationMessagesInIndonesianTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        App::setLocale('id');
    }

    private function admin(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('superadmin');

        return $user;
    }

    public function test_bandwidth_profile_api_validation_message_is_indonesian(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/bandwidth-profiles', []);

        $response->assertUnprocessable();
        $message = $response->json('errors.name.0');
        $this->assertSame('Nama wajib diisi.', $message);
        $this->assertStringNotContainsString('field is required', $message);
    }

    public function test_customer_ip_pool_api_validation_message_translates_nas_id_attribute(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/customer-ip-pools', []);

        $response->assertUnprocessable();
        $message = $response->json('errors.nas_id.0');
        $this->assertSame('NAS wajib diisi.', $message);
    }

    public function test_hotspot_package_api_validation_message_translates_sell_price_attribute(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'type' => NetworkProfileGroupType::Hotspot]);
        $bandwidth = BandwidthProfile::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/hotspot-packages', [
            'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => $bandwidth->id,
            'name' => 'Paket Uji',
            'cost_price' => 10000,
            'sell_price' => 5000,
            'tax_percent' => 0,
            'profile_type' => 'unlimited',
            'shared_users' => 1,
        ]);

        $response->assertUnprocessable();
        $message = $response->json('errors.sell_price.0');
        $this->assertSame('Harga Jual harus bernilai lebih besar dari atau sama dengan 10000.', $message);
        $this->assertStringNotContainsString('sell price', $message);
    }

    public function test_ppp_package_api_validation_message_translates_sell_price_attribute(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'type' => NetworkProfileGroupType::Ppp]);
        $bandwidth = BandwidthProfile::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/ppp-packages', [
            'network_profile_group_id' => $group->id,
            'bandwidth_profile_id' => $bandwidth->id,
            'name' => 'Paket Uji',
            'cost_price' => 10000,
            'sell_price' => 5000,
            'tax_percent' => 0,
            'active_duration_value' => 1,
            'active_duration_unit' => 'month',
            'shared_users' => 1,
        ]);

        $response->assertUnprocessable();
        $message = $response->json('errors.sell_price.0');
        $this->assertSame('Harga Jual harus bernilai lebih besar dari atau sama dengan 10000.', $message);
    }

    public function test_network_profile_group_api_validation_message_is_indonesian(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->actingAs($this->admin($tenant))->postJson('/api/v1/network-profile-groups', []);

        $response->assertUnprocessable();
        $this->assertSame('NAS wajib diisi.', $response->json('errors.nas_id.0'));
    }

    public function test_bandwidth_profile_livewire_validation_message_is_indonesian(): void
    {
        $tenant = Tenant::factory()->create();

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(BandwidthProfileIndex::class)
            ->set('showCreateForm', true)
            ->call('createProfile');

        $component->assertHasErrors('name');
        $html = $component->html();
        $this->assertStringContainsString('Nama wajib diisi.', $html);
    }

    public function test_customer_ip_pool_livewire_validation_message_translates_nas_id_attribute(): void
    {
        $tenant = Tenant::factory()->create();

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(CustomerIpPoolIndex::class)
            ->set('showCreateForm', true)
            ->call('createPool');

        $component->assertHasErrors('nasId');
        $this->assertStringContainsString('NAS wajib diisi.', $component->html());
    }

    public function test_hotspot_package_livewire_validation_message_translates_sell_price_attribute(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'type' => NetworkProfileGroupType::Hotspot]);
        $bandwidth = BandwidthProfile::factory()->create(['tenant_id' => $tenant->id]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(HotspotPackageIndex::class)
            ->set('showCreateForm', true)
            ->set('networkProfileGroupId', (string) $group->id)
            ->set('bandwidthProfileId', (string) $bandwidth->id)
            ->set('name', 'Paket Uji')
            ->set('costPrice', '10000')
            ->set('sellPrice', '5000')
            ->call('createPackage');

        $component->assertHasErrors('sellPrice');
        $html = $component->html();
        $this->assertStringContainsString('Harga Jual harus bernilai lebih besar dari atau sama dengan 10000.', $html);
    }

    public function test_ppp_package_livewire_validation_message_translates_sell_price_attribute(): void
    {
        $tenant = Tenant::factory()->create();
        $nas = Nas::factory()->create(['tenant_id' => $tenant->id]);
        $pool = CustomerIpPool::factory()->create(['nas_id' => $nas->id]);
        $group = NetworkProfileGroup::factory()->create(['nas_id' => $nas->id, 'customer_ip_pool_id' => $pool->id, 'type' => NetworkProfileGroupType::Ppp]);
        $bandwidth = BandwidthProfile::factory()->create(['tenant_id' => $tenant->id]);

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(PppPackageIndex::class)
            ->set('showCreateForm', true)
            ->set('networkProfileGroupId', (string) $group->id)
            ->set('bandwidthProfileId', (string) $bandwidth->id)
            ->set('name', 'Paket Uji')
            ->set('costPrice', '10000')
            ->set('sellPrice', '5000')
            ->call('createPackage');

        $component->assertHasErrors('sellPrice');
        $this->assertStringContainsString('Harga Jual harus bernilai lebih besar dari atau sama dengan 10000.', $component->html());
    }

    public function test_network_profile_group_livewire_validation_message_is_indonesian(): void
    {
        $tenant = Tenant::factory()->create();

        $component = Livewire::actingAs($this->admin($tenant))
            ->test(NetworkProfileGroupIndex::class)
            ->set('showCreateForm', true)
            ->call('createGroup');

        $component->assertHasErrors('nasId');
        $this->assertStringContainsString('NAS wajib diisi.', $component->html());
    }
}
