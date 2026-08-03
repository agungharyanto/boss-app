<?php

namespace App\Http\Resources;

use App\Enums\DashboardWidget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps the array shape {active} (list of active widget values) built in the
 * controller, not an Eloquent model. `available` lists every widget the
 * catalog knows about, active or not, so clients can render a full toggle list.
 */
class DashboardWidgetsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'active' => $this->resource['active'],
            'available' => collect(DashboardWidget::cases())->map(fn (DashboardWidget $widget) => [
                'value' => $widget->value,
                'label' => $widget->label(),
            ])->all(),
        ];
    }
}
