<?php

namespace Tests\Feature\Dashboard;

use App\Enums\DashboardWidget;
use App\Livewire\Dashboard\WidgetSelector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_widgets_show_by_default_with_no_saved_preference(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');
        $content = $response->getContent();

        // Each label legitimately appears once as a selector checkbox caption
        // regardless of active state, and a second time only if the widget's
        // own card also renders in the grid — so assert count 2, not just
        // "contains", to actually prove the widget card is there.
        foreach (DashboardWidget::cases() as $widget) {
            $this->assertSame(2, substr_count($content, $widget->label()), "{$widget->label()} widget card did not render");
        }
    }

    public function test_deselecting_a_widget_persists_and_hides_it(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(WidgetSelector::class)
            ->set('selected.'.DashboardWidget::TopAgents->value, false)
            ->call('save');

        $this->assertDatabaseHas('user_preferences', ['user_id' => $user->id]);

        $preference = $user->fresh()->preference;
        $this->assertNotContains(DashboardWidget::TopAgents->value, $preference->dashboard_widgets);
        $this->assertContains(DashboardWidget::TotalCustomers->value, $preference->dashboard_widgets);

        // Re-fetch the user: the original $user's "preference" relation was
        // cached (as null) back when WidgetSelector::mount() first accessed
        // it, before save() inserted the row — a test-only staleness issue,
        // not a real one (a real page navigation is a fresh request/process).
        $response = $this->actingAs($user->fresh())->get('/dashboard');
        $response->assertSee(__('Total Pelanggan'));
        $this->assertSame(
            1,
            substr_count($response->getContent(), __('Agent Referral Teratas')),
        );
    }

    public function test_selector_reflects_a_previously_saved_configuration(): void
    {
        $user = User::factory()->create();
        $user->preference()->create([
            'dashboard_widgets' => [DashboardWidget::TotalCustomers->value],
        ]);

        Livewire::actingAs($user)
            ->test(WidgetSelector::class)
            ->assertSet('selected.'.DashboardWidget::TotalCustomers->value, true)
            ->assertSet('selected.'.DashboardWidget::TopAgents->value, false);
    }
}
