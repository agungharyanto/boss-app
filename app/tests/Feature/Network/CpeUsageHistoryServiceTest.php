<?php

namespace Tests\Feature\Network;

use App\Models\Customer;
use App\Services\Network\CpeUsageHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * v0.12.6 Bagian 2 — "Grafik Pemakaian". Sama pola isolasi
 * RadiusSessionHistoryServiceTest (v0.8.4): koneksi `radius` di-repoint ke
 * SQLite in-memory dengan tabel `radacct` minimal buatan tangan (bukan
 * migration Laravel — radacct adalah skema FreeRADIUS sendiri).
 */
class CpeUsageHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.radius' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        DB::purge('radius');

        DB::connection('radius')->statement('
            CREATE TABLE radacct (
                radacctid INTEGER PRIMARY KEY,
                username TEXT,
                acctstarttime TEXT,
                acctstoptime TEXT,
                acctinputoctets INTEGER,
                acctoutputoctets INTEGER
            )
        ');
    }

    private function insertRow(array $overrides = []): void
    {
        DB::connection('radius')->table('radacct')->insert(array_merge([
            'username' => '081229565701',
            'acctstarttime' => '2026-08-25 04:09:20',
            'acctstoptime' => '2026-08-25 05:18:57',
            'acctinputoctets' => 1048576, // 1 MB
            'acctoutputoctets' => 2097152, // 2 MB
        ], $overrides));
    }

    public function test_has_any_recorded_usage_is_false_when_customer_never_had_a_single_radacct_row(): void
    {
        $customer = Customer::factory()->create(['phone_number' => '081229565701', 'legacy_username' => null]);

        $this->assertFalse(app(CpeUsageHistoryService::class)->hasAnyRecordedUsage($customer));
    }

    public function test_has_any_recorded_usage_is_true_even_for_a_row_outside_the_30_day_window(): void
    {
        $this->insertRow(['acctstoptime' => Carbon::now()->subDays(90)->toDateTimeString()]);
        $customer = Customer::factory()->create(['phone_number' => '081229565701', 'legacy_username' => null]);

        // Ini state "no_data" vs "ok" dibedakan dari ADA-tidaknya row SAMA
        // SEKALI, bukan cuma dalam 30 hari terakhir -- lihat
        // CpeUsageHistoryService's own docblock.
        $this->assertTrue(app(CpeUsageHistoryService::class)->hasAnyRecordedUsage($customer));
    }

    public function test_daily_usage_fills_every_day_in_range_including_days_with_no_finished_session(): void
    {
        $this->insertRow(['acctstoptime' => Carbon::today()->toDateTimeString()]);
        $customer = Customer::factory()->create(['phone_number' => '081229565701', 'legacy_username' => null]);

        $series = app(CpeUsageHistoryService::class)->dailyUsageForCustomer($customer, 30);

        $this->assertCount(30, $series);
        $this->assertSame(Carbon::today()->subDays(29)->toDateString(), $series[0]['date']);
        $this->assertSame(Carbon::today()->toDateString(), $series[29]['date']);

        // Hari-hari lain (tanpa sesi selesai) tampil 0, bukan dilewati --
        // sumbu X harus tetap kontinu.
        $this->assertSame(0.0, $series[0]['upload_mb']);
        $this->assertSame(0.0, $series[0]['download_mb']);

        $todayRow = $series[29];
        $this->assertSame(1.0, $todayRow['upload_mb']);
        $this->assertSame(2.0, $todayRow['download_mb']);
    }

    public function test_daily_usage_aggregates_multiple_sessions_finishing_on_the_same_day(): void
    {
        $today = Carbon::today();
        $this->insertRow(['acctstoptime' => $today->copy()->setTime(6, 0)->toDateTimeString(), 'acctinputoctets' => 1048576, 'acctoutputoctets' => 0]);
        $this->insertRow(['acctstoptime' => $today->copy()->setTime(20, 0)->toDateTimeString(), 'acctinputoctets' => 1048576, 'acctoutputoctets' => 0]);
        $customer = Customer::factory()->create(['phone_number' => '081229565701', 'legacy_username' => null]);

        $series = app(CpeUsageHistoryService::class)->dailyUsageForCustomer($customer, 30);

        $this->assertSame(2.0, $series[29]['upload_mb']);
    }

    public function test_daily_usage_excludes_a_session_that_finished_outside_the_requested_window(): void
    {
        $this->insertRow(['acctstoptime' => Carbon::now()->subDays(45)->toDateTimeString()]);
        $customer = Customer::factory()->create(['phone_number' => '081229565701', 'legacy_username' => null]);

        $series = app(CpeUsageHistoryService::class)->dailyUsageForCustomer($customer, 30);

        foreach ($series as $row) {
            $this->assertSame(0.0, $row['upload_mb']);
            $this->assertSame(0.0, $row['download_mb']);
        }
    }

    public function test_daily_usage_falls_back_to_legacy_username_when_it_differs_from_phone_number(): void
    {
        $this->insertRow(['username' => 'legacy-user-01', 'acctstoptime' => Carbon::today()->toDateTimeString()]);
        $customer = Customer::factory()->create(['phone_number' => '081200000000', 'legacy_username' => 'legacy-user-01']);

        $series = app(CpeUsageHistoryService::class)->dailyUsageForCustomer($customer, 30);

        $this->assertSame(1.0, $series[29]['upload_mb']);
    }

    // ── v0.12.6 (revisi) — Bagian 2: customDailyUsageForCustomer() (modal "Riwayat", tab Custom) ──

    public function test_custom_daily_usage_covers_exactly_the_requested_date_range_inclusive(): void
    {
        $this->insertRow(['acctstoptime' => '2026-06-01 10:00:00']);
        $this->insertRow(['acctstoptime' => '2026-06-10 10:00:00']);
        $customer = Customer::factory()->create(['phone_number' => '081229565701', 'legacy_username' => null]);

        $series = app(CpeUsageHistoryService::class)->customDailyUsageForCustomer(
            $customer,
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-10'),
        );

        $this->assertCount(10, $series);
        $this->assertSame('2026-06-01', $series[0]['date']);
        $this->assertSame('2026-06-10', $series[9]['date']);
        $this->assertSame(1.0, $series[0]['upload_mb']);
        $this->assertSame(1.0, $series[9]['upload_mb']);
        // Hari di antara (tanpa sesi selesai) tetap 0, bukan dilewati.
        $this->assertSame(0.0, $series[4]['upload_mb']);
    }

    public function test_custom_daily_usage_excludes_a_session_outside_the_requested_range(): void
    {
        $this->insertRow(['acctstoptime' => '2026-05-15 10:00:00']); // di luar rentang
        $customer = Customer::factory()->create(['phone_number' => '081229565701', 'legacy_username' => null]);

        $series = app(CpeUsageHistoryService::class)->customDailyUsageForCustomer(
            $customer,
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-05'),
        );

        foreach ($series as $row) {
            $this->assertSame(0.0, $row['upload_mb']);
            $this->assertSame(0.0, $row['download_mb']);
        }
    }

    public function test_custom_daily_usage_for_a_single_day_range_returns_exactly_one_row(): void
    {
        $this->insertRow(['acctstoptime' => '2026-06-05 10:00:00']);
        $customer = Customer::factory()->create(['phone_number' => '081229565701', 'legacy_username' => null]);

        $series = app(CpeUsageHistoryService::class)->customDailyUsageForCustomer(
            $customer,
            Carbon::parse('2026-06-05'),
            Carbon::parse('2026-06-05'),
        );

        $this->assertCount(1, $series);
        $this->assertSame('2026-06-05', $series[0]['date']);
        $this->assertSame(1.0, $series[0]['upload_mb']);
    }
}
