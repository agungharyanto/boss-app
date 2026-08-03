<?php

namespace App\Services\Payment;

use App\Models\PaymentGatewayChannel;
use App\Models\PaymentGatewaySettings;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * v0.3.5 Fase H — single source of truth for Xendit credentials and the
 * enabled/disabled state of each payment_gateway_channels row. Replaces
 * `.env`/config('services.xendit.*') as the RUNTIME source for
 * secret_key/callback_token (see XenditGatewayService and
 * PaymentService::verifySignature, both refactored to depend on this
 * service instead of config()). `.env`'s XENDIT_SECRET_KEY/
 * XENDIT_CALLBACK_TOKEN are only ever read once more, by
 * `payment-gateway:import-env`, to seed this table from whatever was
 * configured during Fase A-G — never read directly by request-time code
 * after that.
 */
class PaymentGatewaySettingsService
{
    private const CACHE_KEY = 'payment_gateway_settings';

    private const SINGLETON_ID = 1;

    public function getSecretKey(): ?string
    {
        return $this->cachedSettings()->xendit_secret_key;
    }

    public function getWebhookToken(): ?string
    {
        return $this->cachedSettings()->xendit_webhook_token;
    }

    public function isConfigured(): bool
    {
        return (bool) $this->cachedSettings()->is_configured;
    }

    public function current(): PaymentGatewaySettings
    {
        return $this->cachedSettings();
    }

    public function isChannelEnabled(string $code): bool
    {
        return PaymentGatewayChannel::query()->where('code', $code)->where('enabled', true)->exists();
    }

    /**
     * @return Collection<string, Collection<int, PaymentGatewayChannel>>
     */
    public function enabledChannels(): Collection
    {
        return PaymentGatewayChannel::query()
            ->where('enabled', true)
            ->get()
            ->groupBy(fn (PaymentGatewayChannel $channel) => $channel->category->value);
    }

    /**
     * @return Collection<string, Collection<int, PaymentGatewayChannel>>
     */
    public function allChannelsGroupedByCategory(): Collection
    {
        return PaymentGatewayChannel::query()
            ->orderBy('label')
            ->get()
            ->groupBy(fn (PaymentGatewayChannel $channel) => $channel->category->value);
    }

    /**
     * @param  array{xendit_secret_key?: ?string, xendit_webhook_token?: ?string, channels?: array<int, string>}  $data
     */
    public function update(array $data, User $actor): PaymentGatewaySettings
    {
        $settings = DB::transaction(function () use ($data, $actor) {
            $settings = PaymentGatewaySettings::query()->lockForUpdate()->firstOrCreate(['id' => self::SINGLETON_ID]);

            // A blank submit must NOT wipe an already-saved secret/token —
            // only overwrite when a genuinely new, non-empty value was sent
            // (the settings form always renders the field masked/empty, see
            // Livewire\Settings\PaymentGatewaySettings).
            if (filled($data['xendit_secret_key'] ?? null)) {
                $settings->xendit_secret_key = $data['xendit_secret_key'];
            }

            if (filled($data['xendit_webhook_token'] ?? null)) {
                $settings->xendit_webhook_token = $data['xendit_webhook_token'];
            }

            $settings->updated_by = $actor->id;
            $settings->is_configured = filled($settings->xendit_secret_key) && filled($settings->xendit_webhook_token);
            $settings->save();

            if (array_key_exists('channels', $data)) {
                $this->syncChannels($data['channels']);
            }

            return $settings;
        });

        Cache::forget(self::CACHE_KEY);

        return $settings;
    }

    /**
     * @param  array<int, string>  $enabledCodes
     */
    private function syncChannels(array $enabledCodes): void
    {
        PaymentGatewayChannel::query()->whereIn('code', $enabledCodes)->update(['enabled' => true]);
        PaymentGatewayChannel::query()->whereNotIn('code', $enabledCodes)->update(['enabled' => false]);
    }

    private function cachedSettings(): PaymentGatewaySettings
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addHour(),
            // Explicit `is_configured` default here matters: firstOrCreate's
            // "create" branch only sets attributes actually passed to it in
            // memory (Eloquent doesn't re-fetch DB column defaults after
            // insert), so omitting this leaves is_configured genuinely null
            // on a fresh row despite the migration's DB-level default(false)
            // — which then fails the property's `bool` type the moment a
            // caller (e.g. Livewire mount()) assigns it.
            fn () => PaymentGatewaySettings::query()->firstOrCreate(
                ['id' => self::SINGLETON_ID],
                ['is_configured' => false],
            ),
        );
    }
}
