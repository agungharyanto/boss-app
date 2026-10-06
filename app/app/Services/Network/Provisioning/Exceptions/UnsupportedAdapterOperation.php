<?php

namespace App\Services\Network\Provisioning\Exceptions;

use RuntimeException;

/**
 * Operasi dipanggil pada adapter vendor yang GENUINELY belum didukung/
 * diverifikasi (stub) — mis. CPE merek Huawei/CMCC/ZTE-generic di v0.23.8.
 * Beda dari AdapterNotYetImplemented (vendor DIDUKUNG tapi operasinya belum
 * dikoding di sub-versi ini).
 */
class UnsupportedAdapterOperation extends RuntimeException {}
