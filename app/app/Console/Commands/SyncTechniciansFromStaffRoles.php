<?php

namespace App\Console\Commands;

use App\Enums\TechnicianStatus;
use App\Models\Technician;
use App\Models\User;
use App\Services\StaffService;
use App\Support\WhatsappPhone;
use Illuminate\Console\Command;

/**
 * Sekali-jalan (aman diulang — idempoten, lewat StaffService::
 * syncTechnicianStatus() yang SAMA PERSIS dipakai StaffService::create()/
 * update()/disable()/enable(), bukan logic terpisah yang bisa drift) —
 * backfill baris `technicians` untuk staff (users) yang SUDAH punya role
 * Spatie 'teknisi' sebelum auto-sync ini dibangun.
 *
 * DRY-RUN BY DEFAULT — murni laporan, NOL tulis ke DB, sampai `--apply`
 * eksplisit disertakan. Dikunci sengaja: langkah pertama sinkronisasi ini
 * menyentuh data staff/teknisi REAL (termasuk 3 baris manual existing,
 * firman/Yusuf/Sahrul, dibuat 2026-09-20 sebagai tambal sementara — lihat
 * docs/ROADMAP.md "Backlog — Gap Arsitektur: Tabel technicians...") — perlu
 * direview manual dulu sebelum benar-benar diterapkan.
 */
class SyncTechniciansFromStaffRoles extends Command
{
    protected $signature = 'technicians:sync-from-staff-roles {--apply : Benar-benar tulis perubahan ke DB — tanpa flag ini murni laporan dry-run}';

    protected $description = 'Backfill/sinkronkan baris technicians dari staff (users) berrole Spatie "teknisi" (dry-run default, --apply untuk menerapkan)';

    public function handle(StaffService $service): int
    {
        $apply = (bool) $this->option('apply');

        $this->info($apply
            ? 'MODE APPLY — perubahan di bawah akan benar-benar ditulis ke database.'
            : 'MODE DRY-RUN — murni laporan, NOL tulis ke database. Tambahkan --apply untuk menerapkan.');
        $this->newLine();

        $teknisiUsers = User::role(StaffService::TECHNICIAN_ROLE)->orderBy('id')->get();
        $this->info("User dengan role '".StaffService::TECHNICIAN_ROLE."': {$teknisiUsers->count()}");

        foreach ($teknisiUsers as $user) {
            $existing = $service->findTechnicianFor($user);
            $normalizedPhone = WhatsappPhone::normalize($user->phone);

            if ($existing === null) {
                $this->line("  [BARU]           User #{$user->id} {$user->name} ({$normalizedPhone}) -> akan dibuat baris Technician baru, status Active.");
            } elseif (
                $existing->name === $user->name
                && $existing->phone === $normalizedPhone
                && $existing->status === TechnicianStatus::Active
            ) {
                $this->line("  [SUDAH SINKRON]  User #{$user->id} {$user->name} -> Technician #{$existing->id}, tidak ada perubahan.");
            } else {
                $this->line("  [UPDATE]         User #{$user->id} {$user->name} -> Technician #{$existing->id} (nilai lama: name={$existing->name} phone={$existing->phone} status={$existing->status->value}).");
            }

            if ($apply) {
                $service->syncTechnicianStatus($user);
            }
        }

        $this->newLine();

        // Baris Technician berstatus Active tapi user-nya SUDAH TIDAK
        // (lagi) punya role 'teknisi' saat ini (mis. role dicabut sebelum
        // auto-sync ini ada) — di LUAR scope "backfill dari staff role",
        // dilaporkan saja, TIDAK diubah otomatis oleh command ini. Baris
        // ini akan tersinkron sendiri (jadi Inactive) begitu
        // StaffService::update()/disable() berikutnya dipanggil untuk
        // staff itu lewat /staff — command ini sengaja tidak mendahului.
        $activeMismatches = Technician::withoutGlobalScopes()
            ->where('status', TechnicianStatus::Active)
            ->get()
            ->filter(function (Technician $technician) {
                if ($technician->user_id === null) {
                    return true;
                }

                $user = User::find($technician->user_id);

                return $user === null || ! $user->hasRole(StaffService::TECHNICIAN_ROLE);
            });

        if ($activeMismatches->isNotEmpty()) {
            $this->warn("Ditemukan {$activeMismatches->count()} baris Technician berstatus Active TANPA role 'teknisi' yang cocok saat ini — TIDAK diubah otomatis, cek manual:");
            foreach ($activeMismatches as $technician) {
                $this->line("  Technician #{$technician->id} {$technician->name} ({$technician->phone}) user_id=".($technician->user_id ?? 'NULL'));
            }
        } else {
            $this->info('Nol baris Technician Active yang mismatch dengan role saat ini.');
        }

        return self::SUCCESS;
    }
}
