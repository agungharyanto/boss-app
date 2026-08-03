<?php

namespace App\Services;

use App\Enums\DashboardWidget;
use App\Models\User;
use App\Models\UserPreference;

class DashboardWidgetService
{
    /**
     * @return list<DashboardWidget>
     */
    public function activeWidgets(User $user): array
    {
        return collect($this->activeWidgetValues($user))
            ->map(fn (string $value) => DashboardWidget::tryFrom($value))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function activeWidgetValues(User $user): array
    {
        $saved = $user->preference?->dashboard_widgets;

        if ($saved === null) {
            return collect(DashboardWidget::defaults())->map(fn (DashboardWidget $w) => $w->value)->all();
        }

        return $saved;
    }

    /**
     * @param  list<string>  $widgetValues
     */
    public function update(User $user, array $widgetValues): UserPreference
    {
        return $user->preference()->updateOrCreate([], [
            'dashboard_widgets' => $widgetValues,
        ]);
    }
}
