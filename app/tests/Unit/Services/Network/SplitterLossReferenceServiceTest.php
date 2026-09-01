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
            '1:3' => ['1:3', 5.8],
            '1:4' => ['1:4', 7.2],
            '1:5' => ['1:5', 8.3],
            '1:6' => ['1:6', 9.2],
            '1:7' => ['1:7', 9.9],
            '1:8' => ['1:8', 10.5],
            '1:16' => ['1:16', 13.5],
            '1:32' => ['1:32', 17.1],
            '1:64' => ['1:64', 20.5],
            '50:50' => ['50:50', 3.6],
            '40:60' => ['40:60', 4.7],
            '30:70' => ['30:70', 6.0],
            '20:80' => ['20:80', 7.8],
            '10:90' => ['10:90', 11.0],
        ];
    }

    public function test_suggested_ratios_covers_the_pon_and_asymmetric_families_and_is_free_text_only(): void
    {
        $suggestions = (new SplitterLossReferenceService)->suggestedRatios();

        $this->assertContains('1:8', $suggestions);
        $this->assertContains('1:7', $suggestions);
        $this->assertContains('30:70', $suggestions);

        // A ratio not on the list is still perfectly valid input — the
        // field is free text, the reference lookup just returns null.
        $this->assertNotContains('1:128', $suggestions);
        $this->assertNull((new SplitterLossReferenceService)->expectedLossFor('1:128'));
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
