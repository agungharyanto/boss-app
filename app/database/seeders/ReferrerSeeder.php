<?php

namespace Database\Seeders;

use App\Enums\ReferrerType;
use App\Models\Referrer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class ReferrerSeeder extends Seeder
{
    /**
     * 3 dummy referrers (sales, teknisi, freelance) for testing the registration
     * flow. Linked to the matching DemoUsersSeeder account when it exists, so
     * logging in as e.g. sales_internal@boss.local auto-fills its own referrer
     * referral on the registration form. Run RolesAndPermissionsSeeder and
     * DemoUsersSeeder first.
     */
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'isp-demo'],
            ['name' => 'ISP Demo', 'is_active' => true]
        );

        $referrers = [
            ['type' => ReferrerType::Sales, 'name' => 'Agen Sales Demo', 'phone' => '081200000001', 'linked_email' => 'sales_internal@boss.local'],
            ['type' => ReferrerType::Teknisi, 'name' => 'Agen Teknisi Demo', 'phone' => '081200000002', 'linked_email' => 'teknisi@boss.local'],
            ['type' => ReferrerType::Freelance, 'name' => 'Agen Freelance Demo', 'phone' => '081200000003', 'linked_email' => 'sales_freelance@boss.local'],
        ];

        foreach ($referrers as $data) {
            $linkedUser = User::where('email', $data['linked_email'])->first();

            Referrer::firstOrCreate(
                ['tenant_id' => $tenant->id, 'phone' => $data['phone']],
                [
                    'user_id' => $linkedUser?->id,
                    'name' => $data['name'],
                    'type' => $data['type'],
                    'is_active' => true,
                ]
            );
        }
    }
}
