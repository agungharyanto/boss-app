<?php

namespace Tests\Feature\Network;

use App\Services\Network\RadcheckWriterService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * v0.13.4.2.1 — unit RadcheckWriterService: idempotensi (delete-then-insert
 * per username, tanpa duplikat) + penulisan atomik (2 delete + 2 insert dalam
 * SATU transaksi koneksi 'radius'). Koneksi 'radius' dipetakan ke sqlite
 * in-memory (pola sama CtcomActivationServiceTest).
 */
class RadcheckWriterServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.radius' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        DB::purge('radius');
        DB::connection('radius')->statement(
            'CREATE TABLE radcheck (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, attribute TEXT, op TEXT, value TEXT)'
        );
        DB::connection('radius')->statement(
            'CREATE TABLE radreply (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, attribute TEXT, op TEXT, value TEXT)'
        );
    }

    private function service(): RadcheckWriterService
    {
        return app(RadcheckWriterService::class);
    }

    public function test_write_produces_exactly_one_radcheck_and_three_radreply_rows(): void
    {
        $this->service()->write('261000000001@ppp.bajastu.id', 'wifijadipasti', 'POOL-10M');

        $this->assertSame(1, DB::connection('radius')->table('radcheck')->count());
        $this->assertSame(3, DB::connection('radius')->table('radreply')->count());

        $check = DB::connection('radius')->table('radcheck')->first();
        $this->assertSame('Cleartext-Password', $check->attribute);
        $this->assertSame(':=', $check->op);
        $this->assertSame('wifijadipasti', $check->value);

        $pool = DB::connection('radius')->table('radreply')
            ->where('attribute', 'Framed-Pool')->value('value');
        $this->assertSame('POOL-10M', $pool);
    }

    public function test_write_is_idempotent_no_duplicate_on_repeat(): void
    {
        $this->service()->write('u@ppp.bajastu.id', 'wifijadipasti', 'POOL-A');
        // Tulis ulang username yang SAMA dengan pool berbeda.
        $this->service()->write('u@ppp.bajastu.id', 'wifijadipasti', 'POOL-B');

        // Tetap 1 radcheck + 3 radreply (bukan 2 + 6).
        $this->assertSame(1, DB::connection('radius')->table('radcheck')->where('username', 'u@ppp.bajastu.id')->count());
        $this->assertSame(3, DB::connection('radius')->table('radreply')->where('username', 'u@ppp.bajastu.id')->count());

        // Framed-Pool ter-update ke nilai terbaru.
        $pool = DB::connection('radius')->table('radreply')
            ->where('username', 'u@ppp.bajastu.id')->where('attribute', 'Framed-Pool')->value('value');
        $this->assertSame('POOL-B', $pool);
    }

    public function test_write_replaces_a_preexisting_row_atomically(): void
    {
        // Baris lama (mis. sisa migrasi) untuk username yang sama.
        DB::connection('radius')->table('radcheck')->insert([
            'username' => 'u@ppp.bajastu.id', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'OLD',
        ]);

        $this->service()->write('u@ppp.bajastu.id', 'NEW', 'POOL-X');

        $rows = DB::connection('radius')->table('radcheck')->where('username', 'u@ppp.bajastu.id')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('NEW', $rows->first()->value);
    }

    public function test_remove_clears_both_tables_for_the_username_only(): void
    {
        $this->service()->write('keep@ppp.bajastu.id', 'wifijadipasti', 'POOL-K');
        $this->service()->write('drop@ppp.bajastu.id', 'wifijadipasti', 'POOL-D');

        $this->service()->remove('drop@ppp.bajastu.id');

        $this->assertSame(0, DB::connection('radius')->table('radcheck')->where('username', 'drop@ppp.bajastu.id')->count());
        $this->assertSame(0, DB::connection('radius')->table('radreply')->where('username', 'drop@ppp.bajastu.id')->count());
        // Username lain tak tersentuh.
        $this->assertSame(1, DB::connection('radius')->table('radcheck')->where('username', 'keep@ppp.bajastu.id')->count());
    }
}
