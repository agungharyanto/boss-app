<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubscriptionRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Customer;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Subscription::class);

        // Subscription uses BelongsToResellerScope — auto-narrowed to the
        // caller's own reseller when reseller.context resolves one (see
        // routes/api.php), no manual where() needed here.
        $subscriptions = Subscription::query()
            ->with(['customer', 'reseller'])
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            SubscriptionResource::collection($subscriptions->items()),
            'Daftar subscription',
            ['pagination' => [
                'current_page' => $subscriptions->currentPage(),
                'per_page' => $subscriptions->perPage(),
                'total' => $subscriptions->total(),
                'last_page' => $subscriptions->lastPage(),
            ]]
        );
    }

    public function store(StoreSubscriptionRequest $request, SubscriptionService $service): JsonResponse
    {
        $data = $request->validated();
        $customer = Customer::findOrFail($data['customer_id']);
        unset($data['customer_id']);

        $subscription = $service->create($customer, $data);

        return $this->success(new SubscriptionResource($subscription->load(['customer', 'reseller'])), 'Subscription berhasil dibuat', [], 201);
    }

    public function show(Subscription $subscription): JsonResponse
    {
        $this->authorize('view', $subscription);

        return $this->success(new SubscriptionResource($subscription->load(['customer', 'reseller'])));
    }

    public function suspend(Subscription $subscription, SubscriptionService $service): JsonResponse
    {
        $this->authorize('update', $subscription);

        return $this->success(new SubscriptionResource($service->suspend($subscription)), 'Subscription di-suspend');
    }

    public function reactivate(Subscription $subscription, SubscriptionService $service): JsonResponse
    {
        $this->authorize('update', $subscription);

        return $this->success(new SubscriptionResource($service->reactivate($subscription)), 'Subscription diaktifkan kembali');
    }

    public function cancel(Subscription $subscription, SubscriptionService $service): JsonResponse
    {
        $this->authorize('update', $subscription);

        return $this->success(new SubscriptionResource($service->cancel($subscription)), 'Subscription dibatalkan');
    }
}
