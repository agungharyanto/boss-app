<?php

namespace App\Support\Filesystem;

use Illuminate\Support\Facades\Log;

/**
 * v0.13.5.3.1 — normalisasi kepemilikan file/direktori yang ditulis oleh proses
 * yang berjalan SEBAGAI ROOT (scheduler/worker di container ini jalan sebagai
 * root, php-fpm sebagai www-data) menjadi www-data, supaya request web / tombol
 * manual berikutnya (www-data) tetap bisa menimpa file yang sama tanpa 500.
 *
 * NO-OP TOTAL bila: proses bukan root (`posix_geteuid() !== 0`), ekstensi posix
 * tak tersedia, atau user www-data tidak ada. Gagal chown hanya dicatat warning,
 * TIDAK pernah melempar exception — menulis file tetap berhasil, kepemilikan
 * bersifat best-effort.
 *
 * Kelas bug izin ini TIDAK bisa dibuktikan penuh oleh PHPUnit (proses test =
 * non-root → helper no-op); verifikasi nyata dilakukan di container
 * `boss-scheduler` sebagai root (lihat CLAUDE.md v0.13.5.3.1).
 */
class WebOwnership
{
    private const WEB_USER = 'www-data';

    /**
     * Setel kepemilikan file $absolutePath + setiap direktori induk yang saat
     * ini MILIK ROOT (yang baru dibuat oleh proses root ini), naik dari file
     * hingga $rootDir (inklusif, tidak pernah di atasnya), ke www-data. Hanya
     * berjalan bila proses ini euid = 0; selain itu no-op.
     */
    public function normalize(string $absolutePath, string $rootDir): void
    {
        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return; // bukan root → no-op
        }
        if (! function_exists('posix_getpwnam')) {
            return;
        }

        $pw = @posix_getpwnam(self::WEB_USER);
        if (! is_array($pw) || ! isset($pw['uid'], $pw['gid'])) {
            return; // user www-data tidak ada → no-op
        }

        $uid = (int) $pw['uid'];
        $gid = (int) $pw['gid'];

        // File dulu.
        $this->chownOne($absolutePath, $uid, $gid);

        // Lalu direktori, naik dari direktori file sampai akar penyimpanan.
        $root = $this->canonical($rootDir);
        if ($root === null) {
            return;
        }

        $dir = $this->canonical(dirname($absolutePath));
        while ($dir !== null && str_starts_with($dir.'/', $root.'/')) {
            // Hanya sentuh direktori yang SAAT INI milik root (dibuat proses
            // root ini); direktori milik www-data dibiarkan.
            if (@fileowner($dir) === 0) {
                $this->chownOne($dir, $uid, $gid);
            }

            if ($dir === $root) {
                break;
            }

            $parent = $this->canonical(dirname($dir));
            if ($parent === null || $parent === $dir) {
                break;
            }
            $dir = $parent;
        }
    }

    private function chownOne(string $path, int $uid, int $gid): void
    {
        $okOwner = @chown($path, $uid);
        $okGroup = @chgrp($path, $gid);

        if (! $okOwner || ! $okGroup) {
            Log::warning('WebOwnership: gagal menormalkan kepemilikan ke www-data', [
                'path' => $path,
                'chown_ok' => $okOwner,
                'chgrp_ok' => $okGroup,
            ]);
        }
    }

    private function canonical(string $path): ?string
    {
        $real = realpath($path);

        return $real === false ? null : rtrim($real, '/');
    }
}
