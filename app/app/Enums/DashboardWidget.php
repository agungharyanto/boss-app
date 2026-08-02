<?php

namespace App\Enums;

enum DashboardWidget: string
{
    case TotalCustomers = 'total_customers';
    case RecentCustomers = 'recent_customers';
    case RegistrationStatusBreakdown = 'registration_status_breakdown';
    case TopAgents = 'top_agents';

    public function label(): string
    {
        return match ($this) {
            self::TotalCustomers => __('Total Pelanggan'),
            self::RecentCustomers => __('Pelanggan Terbaru'),
            self::RegistrationStatusBreakdown => __('Status Registrasi'),
            self::TopAgents => __('Agent Referral Teratas'),
        };
    }

    /**
     * Livewire component tag name, used for dynamic per-widget rendering.
     */
    public function component(): string
    {
        return match ($this) {
            self::TotalCustomers => 'dashboard.widgets.total-customers',
            self::RecentCustomers => 'dashboard.widgets.recent-customers',
            self::RegistrationStatusBreakdown => 'dashboard.widgets.registration-status-breakdown',
            self::TopAgents => 'dashboard.widgets.top-agents',
        };
    }

    /**
     * @return list<self>
     */
    public static function defaults(): array
    {
        return self::cases();
    }
}
