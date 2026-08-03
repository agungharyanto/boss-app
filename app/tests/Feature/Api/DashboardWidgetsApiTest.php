<?php

namespace Tests\Feature\Api;

use App\Enums\DashboardWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardWidgetsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_widgets_are_active_by_default_with_no_saved_preference(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/settings/dashboard-widgets');

        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            collect(DashboardWidget::defaults())->map(fn (DashboardWidget $w) => $w->value)->all(),
            $response->json('data.active')
        );
        $this->assertCount(count(DashboardWidget::cases()), $response->json('data.available'));
    }

    public function test_updating_persists_the_selection(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/v1/settings/dashboard-widgets', [
            'widgets' => [DashboardWidget::TotalCustomers->value],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.active', [DashboardWidget::TotalCustomers->value]);

        $preference = $user->fresh()->preference;
        $this->assertSame([DashboardWidget::TotalCustomers->value], $preference->dashboard_widgets);
    }

    public function test_an_unknown_widget_value_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/v1/settings/dashboard-widgets', [
            'widgets' => ['not_a_real_widget'],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['widgets.0']);
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/settings/dashboard-widgets')->assertUnauthorized();
    }
}
