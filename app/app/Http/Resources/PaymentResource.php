<?php

namespace App\Http\Resources;

use App\Models\PaymentGatewayChannel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'xendit_reference_id' => $this->xendit_reference_id,
            'channel_type' => $this->channel_type,
            'channel_type_label' => PaymentGatewayChannel::labelFor($this->channel_type),
            'amount' => (float) $this->amount,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'raw_response' => $this->raw_response,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
