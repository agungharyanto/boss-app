<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase A-G shipped payments.channel_type as a plain `string` column (see
 * create_payments_table's migration) — it was never a native Postgres ENUM
 * type, only a fixed-case PHP backed enum (App\Enums\PaymentChannelType) at
 * the application layer. So there is no SQL column type to actually "alter"
 * here. What genuinely changed in Fase H is the *vocabulary*: channel_type
 * now stores a payment_gateway_channels.code value (e.g. "BRI_VA", "QRIS",
 * "XENDIT_INVOICE") instead of the old fixed enum values
 * ("virtual_account"|"qris"|"invoice"), since channels are now a dynamic,
 * admin-managed catalog instead of 3 hardcoded cases.
 *
 * This migration exists purely to keep any pre-existing payments rows
 * written during Fase A-G manual/local testing (this sprint was never
 * deployed to production — see CLAUDE.md) valid under the new vocabulary,
 * so nothing is silently left pointing at a channel code that no longer
 * exists in the catalog.
 */
return new class extends Migration
{
    private const OLD_TO_NEW = [
        'virtual_account' => 'BRI_VA',
        'qris' => 'QRIS',
        'invoice' => 'XENDIT_INVOICE',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::OLD_TO_NEW as $old => $new) {
            DB::table('payments')->where('channel_type', $old)->update(['channel_type' => $new]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::OLD_TO_NEW as $old => $new) {
            DB::table('payments')->where('channel_type', $new)->update(['channel_type' => $old]);
        }
    }
};
