<?php

namespace Tests\Feature\Network;

use App\Http\Requests\StoreFiberNodeRequest;
use App\Http\Requests\UpdateFiberNodeRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * v0.16.0 — Core Network Infrastructure Management, Langkah 2. No
 * Controller/route exists for these FormRequests yet (Langkah 3), so
 * rules()/withValidator() are exercised directly rather than via an HTTP
 * endpoint — a well-established Laravel technique for testing a
 * FormRequest's validation logic in isolation, without an authorize()/
 * route-resolution pass (authorize() itself isn't exercised by this
 * technique — that needs a real HTTP request, deferred to Langkah 3).
 */
class FiberNodeLossValidationTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        return User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
    }

    private function validateStore(array $data): \Illuminate\Validation\Validator
    {
        /** @var StoreFiberNodeRequest $request */
        $request = StoreFiberNodeRequest::create('/fiber-nodes', 'POST', $data);
        $request->setUserResolver(fn () => $this->actingUser());
        $validator = Validator::make($data, $request->rules());
        $request->withValidator($validator);
        $validator->fails();

        return $validator;
    }

    private function validateUpdate(array $data): \Illuminate\Validation\Validator
    {
        /** @var UpdateFiberNodeRequest $request */
        $request = UpdateFiberNodeRequest::create('/fiber-nodes/1', 'PUT', $data);
        $request->setUserResolver(fn () => $this->actingUser());
        $validator = Validator::make($data, $request->rules());
        $request->withValidator($validator);
        $validator->fails();

        return $validator;
    }

    public function test_store_rejects_odc_without_loss_in_db(): void
    {
        $validator = $this->validateStore([
            'node_type' => 'odc',
            'loss_out_db' => 1.5,
        ]);

        $this->assertTrue($validator->errors()->has('loss_in_db'));
    }

    public function test_store_rejects_odc_without_loss_out_db(): void
    {
        $validator = $this->validateStore([
            'node_type' => 'odc',
            'loss_in_db' => 1.5,
        ]);

        $this->assertTrue($validator->errors()->has('loss_out_db'));
    }

    public function test_store_accepts_odc_with_both_loss_values(): void
    {
        $validator = $this->validateStore([
            'node_type' => 'odc',
            'loss_in_db' => 1.5,
            'loss_out_db' => 2.0,
        ]);

        $this->assertFalse($validator->errors()->hasAny(['loss_in_db', 'loss_out_db']));
    }

    public function test_store_allows_otb_without_any_loss_value(): void
    {
        $validator = $this->validateStore([
            'node_type' => 'otb',
        ]);

        $this->assertFalse($validator->errors()->hasAny(['loss_in_db', 'loss_out_db']));
    }

    public function test_store_allows_closure_without_any_loss_value(): void
    {
        $validator = $this->validateStore([
            'node_type' => 'closure',
        ]);

        $this->assertFalse($validator->errors()->hasAny(['loss_in_db', 'loss_out_db']));
    }

    public function test_update_rejects_odc_without_loss_values(): void
    {
        $validator = $this->validateUpdate([
            'node_type' => 'odc',
        ]);

        $this->assertTrue($validator->errors()->hasAny(['loss_in_db', 'loss_out_db']));
    }

    public function test_update_allows_otb_without_loss_values(): void
    {
        $validator = $this->validateUpdate([
            'node_type' => 'otb',
        ]);

        $this->assertFalse($validator->errors()->hasAny(['loss_in_db', 'loss_out_db']));
    }
}
