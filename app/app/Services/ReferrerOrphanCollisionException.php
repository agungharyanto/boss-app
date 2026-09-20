<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * v0.22.8 — dilempar `ReferrerService::createAndLinkToStaff()` saat
 * collision `(tenant_id, phone)` terdeteksi TAPI Referrer yang bentrok itu
 * ORPHAN (`user_id === null`, belum ada akun login siapa pun) — beda dari
 * collision ke Referrer yang SUDAH terhubung ke user lain (tetap
 * `InvalidArgumentException` polos, hard block, lihat docblock
 * `createAndLinkToStaff()`).
 *
 * Membawa data terstruktur (bukan cuma pesan string) supaya caller
 * (`StaffService::create()` → `StaffIndex`) bisa menampilkan tombol
 * "Link ke Referrer lama ini" dengan konteks lengkap (nama, tipe, kapan
 * dibuat, jumlah data nyantol) SEBELUM admin memutuskan klik — bukan
 * dilink otomatis diam-diam.
 */
class ReferrerOrphanCollisionException extends InvalidArgumentException
{
    public function __construct(
        public readonly int $referrerId,
        public readonly string $referrerName,
        public readonly string $referrerType,
        public readonly string $referrerCreatedAt,
        public readonly int $commissionLedgerCount,
        public readonly int $customerCount,
    ) {
        parent::__construct("Sudah ada Referrer orphan (belum ada akun login) dengan nomor HP yang sama: {$referrerName}.");
    }
}
