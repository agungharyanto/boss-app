<?php

namespace Tests\Feature\Network;

use App\Exceptions\NasPortPoolExhaustedException;
use App\Models\NasPortAllocatorState;
use App\Services\Network\NasPortAllocatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NasPortAllocatorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_allocates_an_auth_acct_pair_stepped_by_ten(): void
    {
        $first = app(NasPortAllocatorService::class)->allocate();
        $second = app(NasPortAllocatorService::class)->allocate();

        $this->assertSame($first['auth_port'] + 1, $first['acct_port']);
        $this->assertArrayNotHasKey('coa_port', $first);
        $this->assertSame($first['auth_port'] + 10, $second['auth_port']);
    }

    public function test_never_returns_the_same_block_twice_even_across_many_calls(): void
    {
        $seen = [];

        for ($i = 0; $i < 25; $i++) {
            $block = app(NasPortAllocatorService::class)->allocate();
            $seen[] = $block['auth_port'];
        }

        $this->assertSame($seen, array_unique($seen));
    }

    public function test_throws_once_the_range_is_exhausted(): void
    {
        NasPortAllocatorState::query()->where('id', 1)->update(['next_auth_port' => 65000]);

        $this->expectException(NasPortPoolExhaustedException::class);

        app(NasPortAllocatorService::class)->allocate();
    }
}
