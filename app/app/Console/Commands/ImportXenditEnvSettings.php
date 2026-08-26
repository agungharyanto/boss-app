<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Payment\PaymentGatewaySettingsService;
use Illuminate\Console\Command;

/**
 * One-time, manual-only transition command (v0.3.5 Fase H): reads the
 * XENDIT_SECRET_KEY/XENDIT_CALLBACK_TOKEN this server had configured in
 * `.env` during Fase A-G and writes them into payment_gateway_settings
 * (encrypted, DB-backed — the new runtime source, see
 * PaymentGatewaySettingsService). Deliberately NOT run automatically from
 * any migration/seeder — a server operator triggers it explicitly, once,
 * during the Fase A-G -> Fase H transition. After this runs successfully,
 * .env's Xendit values are no longer read by any request-time code path.
 */
class ImportXenditEnvSettings extends Command
{
    protected $signature = 'payment-gateway:import-env';

    protected $description = 'Import XENDIT_SECRET_KEY/XENDIT_CALLBACK_TOKEN dari .env ke payment_gateway_settings (sekali jalan, manual)';

    public function handle(PaymentGatewaySettingsService $service): int
    {
        $secretKey = config('services.xendit.secret_key');
        $callbackToken = config('services.xendit.callback_token');

        if (blank($secretKey) && blank($callbackToken)) {
            $this->warn('XENDIT_SECRET_KEY dan XENDIT_CALLBACK_TOKEN di .env kosong — tidak ada yang di-import.');

            return self::SUCCESS;
        }

        $actor = User::role('superadmin')->first();

        if ($actor === null) {
            $this->error('Tidak ada user dengan role superadmin — jalankan RolesAndPermissionsSeeder/DemoUsersSeeder dulu, lalu ulangi command ini.');

            return self::FAILURE;
        }

        $service->update([
            'xendit_secret_key' => $secretKey,
            'xendit_webhook_token' => $callbackToken,
        ], $actor);

        $this->info('Kredensial Xendit dari .env berhasil di-import ke payment_gateway_settings.');
        $this->line('Channel yang aktif TIDAK diubah oleh command ini — atur lewat Pengaturan > Payment Gateway.');

        return self::SUCCESS;
    }
}
