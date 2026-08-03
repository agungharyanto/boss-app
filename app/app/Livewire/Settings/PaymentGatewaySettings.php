<?php

namespace App\Livewire\Settings;

use App\Models\PaymentGatewaySettings as PaymentGatewaySettingsModel;
use App\Services\Payment\PaymentGatewaySettingsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Admin-only (v0.3.5 Fase H) — "Pengaturan > Payment Gateway", modeled on
 * the MikRadius-style reference screenshot: API Secret + Webhook Token
 * fields (masked, never re-render the real saved value) and a per-category
 * channel checklist. Read-only report counterpart is
 * App\Livewire\Billing\ReconciliationReport; this component is the only
 * write path for payment_gateway_settings/payment_gateway_channels.
 */
class PaymentGatewaySettings extends Component
{
    use AuthorizesRequests;

    public string $xenditSecretKey = '';

    public string $xenditWebhookToken = '';

    /** @var array<int, string> */
    public array $enabledChannelCodes = [];

    public bool $isConfigured = false;

    public ?string $lastUpdatedAt = null;

    public function mount(PaymentGatewaySettingsService $service): void
    {
        $this->authorize('view', PaymentGatewaySettingsModel::class);

        $this->refreshFromSettings($service->current());

        $this->enabledChannelCodes = $service->enabledChannels()
            ->flatten()
            ->pluck('code')
            ->all();
    }

    public function save(PaymentGatewaySettingsService $service): void
    {
        $this->authorize('manage', PaymentGatewaySettingsModel::class);

        $this->validate([
            'xenditSecretKey' => ['nullable', 'string'],
            'xenditWebhookToken' => ['nullable', 'string'],
            'enabledChannelCodes' => ['required', 'array', 'min:1'],
        ], [
            'enabledChannelCodes.required' => 'Pilih minimal satu channel pembayaran sebelum menyimpan.',
            'enabledChannelCodes.min' => 'Pilih minimal satu channel pembayaran sebelum menyimpan.',
        ]);

        $settings = $service->update([
            // Blank submit must not erase an already-saved secret/token —
            // PaymentGatewaySettingsService::update() only overwrites when
            // a non-empty value is present, so an empty string is
            // deliberately normalized to null here.
            'xendit_secret_key' => $this->xenditSecretKey ?: null,
            'xendit_webhook_token' => $this->xenditWebhookToken ?: null,
            'channels' => $this->enabledChannelCodes,
        ], auth()->user());

        // Never keep the submitted plaintext in the form/browser state
        // after saving — the fields always redisplay blank + masked.
        $this->reset(['xenditSecretKey', 'xenditWebhookToken']);
        $this->refreshFromSettings($settings);

        session()->flash('status', 'Pengaturan Payment Gateway berhasil disimpan.');
    }

    public function render(PaymentGatewaySettingsService $service)
    {
        return view('livewire.settings.payment-gateway-settings', [
            'channelsByCategory' => $service->allChannelsGroupedByCategory(),
        ]);
    }

    private function refreshFromSettings(PaymentGatewaySettingsModel $settings): void
    {
        $this->isConfigured = $settings->is_configured;
        $this->lastUpdatedAt = $settings->updated_at?->translatedFormat('d M Y H:i');
    }
}
