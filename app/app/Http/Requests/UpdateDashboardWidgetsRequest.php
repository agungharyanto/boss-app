<?php

namespace App\Http\Requests;

use App\Enums\DashboardWidget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateDashboardWidgetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'widgets' => ['present', 'array'],
            'widgets.*' => ['string', new Enum(DashboardWidget::class)],
        ];
    }
}
