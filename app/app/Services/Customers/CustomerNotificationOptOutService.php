<?php

namespace App\Services\Customers;

use App\Enums\CustomerOptOutAction;
use App\Exceptions\CustomerOptOutException;
use App\Models\Customer;
use App\Models\CustomerNotificationOptOutLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * v0.28.1 (T1) — opt-out / opt-in notifikasi WA OTOMATIS per-pelanggan.
 * Logika di service (BUKAN Livewire). Tiap toggle menulis TEPAT SATU baris
 * jejak append-only + meng-update flag `customers.auto_notify_opt_out`, dalam
 * SATU transaksi. Bila status sudah sama → tolak dengan pesan jelas, TANPA
 * menulis log. Alasan wajib (minimal MIN_REASON_LENGTH karakter).
 */
class CustomerNotificationOptOutService
{
    public const MIN_REASON_LENGTH = 3;

    public function optOut(Customer $customer, string $reason, ?User $actor = null): CustomerNotificationOptOutLog
    {
        return $this->apply($customer, true, CustomerOptOutAction::OptedOut, $reason, $actor);
    }

    public function optIn(Customer $customer, string $reason, ?User $actor = null): CustomerNotificationOptOutLog
    {
        return $this->apply($customer, false, CustomerOptOutAction::OptedIn, $reason, $actor);
    }

    private function apply(
        Customer $customer,
        bool $target,
        CustomerOptOutAction $action,
        string $reason,
        ?User $actor,
    ): CustomerNotificationOptOutLog {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw new CustomerOptOutException('Alasan wajib diisi (minimal '.self::MIN_REASON_LENGTH.' karakter).');
        }

        $current = (bool) $customer->auto_notify_opt_out;
        if ($current === $target) {
            $state = $target
                ? 'sudah opt-out (tidak menerima notifikasi otomatis)'
                : 'sudah menerima notifikasi otomatis';
            throw new CustomerOptOutException("Status tidak berubah — pelanggan ini {$state}.");
        }

        return DB::transaction(function () use ($customer, $current, $target, $action, $reason, $actor) {
            $customer->update(['auto_notify_opt_out' => $target]);

            return CustomerNotificationOptOutLog::create([
                'tenant_id' => $customer->tenant_id,
                'customer_id' => $customer->id,
                'action' => $action,
                'reason' => $reason,
                'before' => ['auto_notify_opt_out' => $current],
                'after' => ['auto_notify_opt_out' => $target],
                'user_id' => $actor?->id,
            ]);
        });
    }
}
