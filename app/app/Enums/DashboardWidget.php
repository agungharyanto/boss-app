<?php

namespace App\Enums;

enum DashboardWidget: string
{
    case TotalCustomers = 'total_customers';
    case RecentCustomers = 'recent_customers';
    case RegistrationStatusBreakdown = 'registration_status_breakdown';
    // Value deliberately kept as 'top_agents' (not 'top_referrers') even
    // though the case name changed — this is a persisted value inside
    // user_preferences.dashboard_widgets (json), and changing it would
    // silently drop this widget from every user's already-saved dashboard
    // preferences. Same "case name can change, backed value must not"
    // discipline already established for App\Enums\ReferrerType.
    case TopReferrers = 'top_agents';

    public function label(): string
    {
        return match ($this) {
            self::TotalCustomers => __('Total Pelanggan'),
            self::RecentCustomers => __('Pelanggan Terbaru'),
            self::RegistrationStatusBreakdown => __('Status Registrasi'),
            self::TopReferrers => __('Referrer Teratas'),
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
            self::TopReferrers => 'dashboard.widgets.top-referrers',
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
