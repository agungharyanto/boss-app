<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * v0.13.4.1 amendment — dilempar WorkOrderClaimService::submit() saat WO
 * yang dituju sudah punya claimed_at terisi (klaim pertama sudah berhasil
 * sebelumnya, lewat link yang sama atau link lain yang masih valid).
 * Selalu ditangkap eksplisit oleh WorkOrderClaimController (bukan lewat
 * exception handler global, ini bukan endpoint JSON/API) — sengaja TIDAK
 * override render() seperti WorkOrderClaimException/
 * IncompleteWorkOrderException (yang keduanya API-only).
 */
class WorkOrderAlreadyClaimedException extends RuntimeException {}
