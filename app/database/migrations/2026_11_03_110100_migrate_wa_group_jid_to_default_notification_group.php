<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v0.13.4.4 — migrasi data IDEMPOTEN: salin `work_order_dispatch_settings.wa_group_jid`
 * lama (grup tunggal per tenant) ke SATU baris `whatsapp_notification_groups`
 * default (session_key 'direct'). Setelah ini, perilaku kirim grup identik
 * dengan sebelum migrasi (routing kosong -> semua kategori jatuh ke default
 * ini). Kolom lama TIDAK di-drop (deprecated).
 *
 * Idempoten: dilewati kalau JID itu sudah punya baris grup, ATAU tenant itu
 * sudah punya default 'direct' (hindari tabrakan partial-unique-index
 * one-default). Query DB langsung + tenant_id eksplisit (tanpa model/scope).
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('work_order_dispatch_settings')
            ->whereNotNull('wa_group_jid')
            ->where('wa_group_jid', '!=', '')
            ->get(['tenant_id', 'wa_group_jid', 'wa_group_name']);

        foreach ($rows as $row) {
            $jidExists = DB::table('whatsapp_notification_groups')
                ->where('tenant_id', $row->tenant_id)
                ->where('jid', $row->wa_group_jid)
                ->exists();

            if ($jidExists) {
                continue;
            }

            $hasDefault = DB::table('whatsapp_notification_groups')
                ->where('tenant_id', $row->tenant_id)
                ->where('session_key', 'direct')
                ->where('is_default', true)
                ->exists();

            DB::table('whatsapp_notification_groups')->insert([
                'tenant_id' => $row->tenant_id,
                'session_key' => 'direct',
                'jid' => $row->wa_group_jid,
                'label' => $row->wa_group_name ?: 'Grup Utama',
                // Jadi default hanya kalau tenant belum punya default 'direct'.
                'is_default' => ! $hasDefault,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Tidak menghapus apa pun — kolom lama wa_group_jid tetap sumber
        // kebenaran lama, grup yang dibuat bisa dikelola lewat UI. Rollback
        // non-destruktif disengaja (data grup buatan admin tidak ikut hilang).
    }
};
