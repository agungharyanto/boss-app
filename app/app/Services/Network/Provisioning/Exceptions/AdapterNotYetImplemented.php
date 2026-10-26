<?php

namespace App\Services\Network\Provisioning\Exceptions;

use RuntimeException;

/**
 * Vendor DIDUKUNG (is_supported=true) tapi body operasi belum dikoding di
 * sub-versi ini — diisi di sub-versi berikutnya (v0.23.8.2/.2b). Dipakai
 * skeleton adapter v0.23.8.1 (pondasi) supaya registry/kapabilitas/mirror
 * bisa dites tanpa logika tulis device apa pun.
 */
class AdapterNotYetImplemented extends RuntimeException {}
