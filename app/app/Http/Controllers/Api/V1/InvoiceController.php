<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        // Invoice uses BelongsToResellerScope — auto-narrowed to the
        // caller's own reseller when reseller.context resolves one.
        $invoices = Invoice::query()
            ->with(['customer', 'reseller'])
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('generated_at')
            ->paginate($request->integer('per_page', 15));

        return $this->success(
            InvoiceResource::collection($invoices->items()),
            'Daftar invoice',
            ['pagination' => [
                'current_page' => $invoices->currentPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
                'last_page' => $invoices->lastPage(),
            ]]
        );
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        return $this->success(new InvoiceResource($invoice->load(['customer', 'reseller', 'lineItems'])));
    }

    /**
     * Manual trigger for App\Services\InvoiceService::generateNextForSubscription()
     * — the same idempotent entry point the scheduled command uses.
     * Generated as 'draft' here (unlike the scheduled command, which
     * auto-issues to 'pending' immediately) — a manually-triggered invoice
     * gets a review step via the separate PATCH .../pending endpoint.
     */
    public function generate(GenerateInvoiceRequest $request, InvoiceService $service): JsonResponse
    {
        $subscription = Subscription::findOrFail($request->validated('subscription_id'));

        $invoice = $service->generateNextForSubscription($subscription);

        return $this->success(
            new InvoiceResource($invoice->load(['customer', 'reseller', 'lineItems'])),
            'Invoice berhasil digenerate',
            [],
            $invoice->wasRecentlyCreated ? 201 : 200
        );
    }

    public function markPending(Invoice $invoice, InvoiceService $service): JsonResponse
    {
        $this->authorize('manage', Invoice::class);

        $invoice = $service->markPending($invoice);

        return $this->success(new InvoiceResource($invoice->load(['customer', 'reseller', 'lineItems'])), 'Status invoice berhasil diubah');
    }

    public function markPaid(Invoice $invoice, InvoiceService $service): JsonResponse
    {
        $this->authorize('manage', Invoice::class);

        $invoice = $service->markPaid($invoice);

        return $this->success(new InvoiceResource($invoice->load(['customer', 'reseller', 'lineItems'])), 'Status invoice berhasil diubah');
    }

    public function cancel(Invoice $invoice, InvoiceService $service): JsonResponse
    {
        $this->authorize('manage', Invoice::class);

        $invoice = $service->cancel($invoice);

        return $this->success(new InvoiceResource($invoice->load(['customer', 'reseller', 'lineItems'])), 'Status invoice berhasil diubah');
    }
}
