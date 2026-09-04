<?php

namespace App\Support;

/**
 * Normalisasi nomor Indonesia ke format yang dipakai WhatsApp: kode negara
 * `62`, TANPA `0` di depan, TANPA `+`, TANPA pemisah.
 *
 * BUG NYATA yang ini perbaiki (laten sejak v0.4.0, ketemu 2026-09-03):
 * nomor disimpan/dikirim mentah (`087884374939`) → `whatsapp-gateway`'s
 * `toJid()` lama cuma strip non-digit → `087884374939@s.whatsapp.net` (JID
 * tidak sah) → `sock.sendMessage()` hang → timeout (sempat salah
 * didiagnosis "akun di-restrict"). Kirim ke `6287884374939@s.whatsapp.net`
 * sukses ~1 detik.
 *
 * Dipakai di `WhatsappGatewayService` saat mengisi
 * `whatsapp_message_logs.phone_number` supaya nilai tersimpan + payload ke
 * gateway sama-sama sudah benar; `toJid()` di sisi Node tetap melakukan
 * normalisasi yang sama sebagai jaring pengaman.
 */
class WhatsappPhone
{
    public static function normalize(?string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }

        if (! str_starts_with($digits, '62')) {
            return '62'.$digits;
        }

        return $digits;
    }
}
