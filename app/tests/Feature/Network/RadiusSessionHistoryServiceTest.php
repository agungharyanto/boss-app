<?php

namespace Tests\Feature\Network;

use App\Models\Customer;
use App\Services\Network\RadiusSessionHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * v0.8.4 — the `radius` connection normally points at the real, separate
 * `radius_db` Postgres instance (config/database.php) — for genuine test
 * isolation (this must NEVER touch real production radacct data), it's
 * repointed to an isolated in-memory SQLite database here, with a minimal
 * `radacct` table (just the columns RadiusSessionHistoryService actually
 * reads) created by hand, since radacct is FreeRADIUS's own schema, not a
 * Laravel migration.
 */
class RadiusSessionHistoryServiceTest extends TestCase
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
                nasipaddress TEXT,
                acctstarttime TEXT,
                acctstoptime TEXT,
                acctsessiontime INTEGER,
                acctinputoctets INTEGER,
                acctoutputoctets INTEGER,
                acctterminatecause TEXT
            )
        ');
    }

    private function insertRow(array $overrides = []): void
    {
        DB::connection('radius')->table('radacct')->insert(array_merge([
            'username' => '081229565701',
            'nasipaddress' => '172.23.195.6',
            'acctstarttime' => '2026-08-25 04:09:20+00',
            'acctstoptime' => '2026-08-25 05:18:57+00',
            'acctsessiontime' => 29377,
            'acctinputoctets' => 378714,
            'acctoutputoctets' => 72,
            'acctterminatecause' => 'NAS-Request',
        ], $overrides));
    }

    public function test_returns_history_matched_by_phone_number(): void
    {
        $this->insertRow();
        $customer = Customer::factory()->create(['phone_number' => '081229565701', 'legacy_username' => null]);

        $history = app(RadiusSessionHistoryService::class)->getHistoryForCustomer($customer);

        $this->assertCount(1, $history);
        $this->assertSame(378714, $history[0]['upload_bytes']);
        $this->assertSame(72, $history[0]['download_bytes']);
        $this->assertSame('NAS-Request', $history[0]['terminate_cause']);
        $this->assertFalse($history[0]['is_active']);
        $this->assertSame(29377, $history[0]['session_seconds']);
    }

    public function test_falls_back_to_legacy_username_when_it_differs_from_phone_number(): void
    {
        $this->insertRow(['username' => 'legacy-name-123']);
        $customer = Customer::factory()->create(['phone_number' => '089900001111', 'legacy_username' => 'legacy-name-123']);

        $history = app(RadiusSessionHistoryService::class)->getHistoryForCustomer($customer);

        $this->assertCount(1, $history);
    }

    public function test_customer_with_no_matching_username_gets_empty_array_not_an_error(): void
    {
        $this->insertRow();
        $customer = Customer::factory()->create(['phone_number' => '000000000000', 'legacy_username' => null]);

        $history = app(RadiusSessionHistoryService::class)->getHistoryForCustomer($customer);

        $this->assertSame([], $history);
    }

    public function test_still_active_session_computes_a_live_duration_instead_of_a_static_zero(): void
    {
        $this->insertRow([
            'acctstarttime' => now()->subMinutes(5)->setTimezone('UTC')->toDateTimeString().'+00',
            'acctstoptime' => null,
            'acctsessiontime' => 0,
            'acctinputoctets' => 0,
            'acctoutputoctets' => 0,
            'acctterminatecause' => null,
        ]);
        $customer = Customer::factory()->create(['phone_number' => '081229565701', 'legacy_username' => null]);

        $history = app(RadiusSessionHistoryService::class)->getHistoryForCustomer($customer);

        $this->assertTrue($history[0]['is_active']);
        $this->assertNull($history[0]['stopped_at']);
        // ~5 minutes = ~300s, allow generous margin for test execution time.
        $this->assertGreaterThan(250, $history[0]['session_seconds']);
        $this->assertLessThan(400, $history[0]['session_seconds']);
    }

    public function test_orders_newest_session_first_and_respects_limit(): void
    {
        $this->insertRow(['acctstarttime' => '2026-08-20 00:00:00+00', 'acctstoptime' => '2026-08-20 01:00:00+00']);
        $this->insertRow(['acctstarttime' => '2026-08-24 00:00:00+00', 'acctstoptime' => '2026-08-24 01:00:00+00']);
        $this->insertRow(['acctstarttime' => '2026-08-22 00:00:00+00', 'acctstoptime' => '2026-08-22 01:00:00+00']);
        $customer = Customer::factory()->create(['phone_number' => '081229565701', 'legacy_username' => null]);

        $history = app(RadiusSessionHistoryService::class)->getHistoryForCustomer($customer, limit: 2);

        $this->assertCount(2, $history);
        $this->assertSame('2026-08-24', $history[0]['started_at']->toDateString());
        $this->assertSame('2026-08-22', $history[1]['started_at']->toDateString());
    }

    #[DataProvider('byteFormatProvider')]
    public function test_format_bytes(int $bytes, string $expected): void
    {
        $this->assertSame($expected, app(RadiusSessionHistoryService::class)->formatBytes($bytes));
    }

    public static function byteFormatProvider(): array
    {
        return [
            'zero' => [0, '0 B'],
            'bytes' => [512, '512 B'],
            'kilobytes' => [378714, '369.84 KB'],
            'megabytes' => [5_242_880, '5.00 MB'],
            'gigabytes' => [3_221_225_472, '3.00 GB'],
        ];
    }
}
