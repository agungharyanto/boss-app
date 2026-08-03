<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    use ApiResponds;

    public function index(Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        return $this->success(PaymentResource::collection($invoice->payments()->latest()->get()), 'Daftar payment untuk invoice ini');
    }

    public function store(CreatePaymentRequest $request, Invoice $invoice, PaymentService $service): JsonResponse
    {
        $payment = $service->createPaymentFor($invoice, $request->validated('channel_type'));

        return $this->success(new PaymentResource($payment), 'Payment berhasil dibuat', [], 201);
    }
}
