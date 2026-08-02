<?php

namespace Database\Seeders;

use App\Enums\AgentType;
use App\Models\Agent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class AgentSeeder extends Seeder
{
    /**
     * 3 dummy agents (sales, teknisi, freelance) for testing the registration
     * flow. Linked to the matching DemoUsersSeeder account when it exists, so
     * logging in as e.g. sales_internal@boss.local auto-fills its own agent
     * referral on the registration form. Run RolesAndPermissionsSeeder and
     * DemoUsersSeeder first.
     */
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'isp-demo'],
            ['name' => 'ISP Demo', 'is_active' => true]
        );

        $agents = [
            ['type' => AgentType::Sales, 'name' => 'Agen Sales Demo', 'phone' => '081200000001', 'linked_email' => 'sales_internal@boss.local'],
            ['type' => AgentType::Teknisi, 'name' => 'Agen Teknisi Demo', 'phone' => '081200000002', 'linked_email' => 'teknisi@boss.local'],
            ['type' => AgentType::Freelance, 'name' => 'Agen Freelance Demo', 'phone' => '081200000003', 'linked_email' => 'sales_freelance@boss.local'],
        ];

        foreach ($agents as $data) {
            $linkedUser = User::where('email', $data['linked_email'])->first();

            Agent::firstOrCreate(
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
