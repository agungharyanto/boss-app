<?php

namespace App\Exceptions;

use App\Enums\WorkOrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * v0.13.4.2.1 — kegagalan guard aktivasi pelanggan oleh teknisi. Satu kelas,
 * banyak static factory (pesan Indonesia spesifik per penyebab), pola sama
 * App\Exceptions\WorkOrderClaimException: render() -> envelope 422 standar.
 * Dipakai WorkOrderActivationService::prepare().
 */
class WorkOrderActivationException extends RuntimeException
{
    public static function notClaimedByActor(): self
    {
        return new self('Aktivasi hanya boleh dilakukan oleh teknisi yang mengklaim Work Order ini.');
    }

    public static function missingCid(): self
    {
        return new self('Pelanggan belum punya CID — tidak bisa membentuk username PPPoE. Lengkapi CID pelanggan dulu.');
    }

    public static function conflictingRadcheckUsername(string $existingUsername): self
    {
        return new self(
            "Pelanggan ini sudah punya username RADIUS lain (\"{$existingUsername}\") di radcheck (kemungkinan hasil migrasi). ".
            'Aktivasi dibatalkan agar tidak membuat kredensial ganda — tangani migrasi username ini dulu secara manual.'
        );
    }

    public static function noModemUnits(): self
    {
        return new self('Work Order ini belum punya unit modem tercatat (hasil klaim) — aktivasi butuh minimal 1 modem.');
    }

    public static function noVlanPackage(string $reason): self
    {
        return new self($reason);
    }

    public static function illegalStatus(WorkOrderStatus $status): self
    {
        return new self(
            "Work Order berstatus \"{$status->label()}\" tidak bisa diaktivasi — ".
            'hanya status "Siap" (Ready) atau "Ditugaskan" (Assigned) yang boleh.'
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'data' => null,
            'meta' => [],
        ], 422);
    }
}
