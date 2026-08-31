<?php

namespace Tests\Unit\Services\Network;

use App\Services\Network\FiberColorService;
use InvalidArgumentException;
use Tests\TestCase;

class FiberColorServiceTest extends TestCase
{
    public function test_position_1_resolves_to_biru(): void
    {
        $color = (new FiberColorService)->resolveColor(1);

        $this->assertSame('Biru', $color['name']);
    }

    public function test_position_12_resolves_to_toska_the_last_color_in_the_cycle(): void
    {
        $color = (new FiberColorService)->resolveColor(12);

        $this->assertSame('Toska', $color['name']);
    }

    public function test_position_13_wraps_back_around_to_the_same_color_as_position_1(): void
    {
        $service = new FiberColorService;

        $this->assertSame($service->resolveColor(1), $service->resolveColor(13));
    }

    public function test_position_24_wraps_back_around_to_the_same_color_as_position_12(): void
    {
        $service = new FiberColorService;

        $this->assertSame($service->resolveColor(12), $service->resolveColor(24));
    }

    public function test_position_25_wraps_back_around_to_the_same_color_as_position_1_a_second_time(): void
    {
        $service = new FiberColorService;

        $this->assertSame($service->resolveColor(1), $service->resolveColor(25));
    }

    public function test_every_color_in_the_cycle_has_a_name_and_a_hex_value(): void
    {
        $service = new FiberColorService;

        for ($position = 1; $position <= 12; $position++) {
            $color = $service->resolveColor($position);
            $this->assertNotEmpty($color['name']);
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $color['hex']);
        }
    }

    public function test_position_below_1_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FiberColorService)->resolveColor(0);
    }
}
