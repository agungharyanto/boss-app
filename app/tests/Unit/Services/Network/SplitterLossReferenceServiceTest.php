<?php

namespace Tests\Unit\Services\Network;

use App\Enums\FiberAccessoryType;
use App\Services\Network\SplitterLossReferenceService;
use Tests\TestCase;

class SplitterLossReferenceServiceTest extends TestCase
{
    /**
     * @return array<string, array{string, float}>
     */
    public static function knownRatios(): array
    {
        return [
            '1:2' => ['1:2', 3.5],
            '1:4' => ['1:4', 7.2],
            '1:8' => ['1:8', 10.5],
            '1:16' => ['1:16', 13.5],
            '1:32' => ['1:32', 17.1],
            '1:64' => ['1:64', 20.5],
        ];
    }

    /**
     * @dataProvider knownRatios
     */
    public function test_expected_loss_for_known_ratio_returns_the_typical_db_reference_value(string $ratio, float $expectedTypicalDb): void
    {
        $service = new SplitterLossReferenceService;

        $this->assertSame($expectedTypicalDb, $service->expectedLossFor($ratio));
    }

    public function test_expected_loss_for_a_custom_unknown_ratio_returns_null_not_an_error(): void
    {
        $service = new SplitterLossReferenceService;

        $this->assertNull($service->expectedLossFor('1:128'));
        $this->assertNull($service->expectedLossFor('custom-ratio'));
    }

    public function test_default_accessory_loss_values(): void
    {
        $service = new SplitterLossReferenceService;

        $this->assertSame(0.15, $service->defaultAccessoryLossFor(FiberAccessoryType::SpliceFusion));
        $this->assertSame(0.2, $service->defaultAccessoryLossFor(FiberAccessoryType::SpliceMechanical));
        $this->assertSame(0.25, $service->defaultAccessoryLossFor(FiberAccessoryType::Connector));
        $this->assertSame(0.25, $service->defaultAccessoryLossFor(FiberAccessoryType::PinAdaptor));
    }
}
