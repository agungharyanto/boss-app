<?php

namespace Tests\Unit\Support;

use App\Support\RouterOsQueuePriority;
use PHPUnit\Framework\TestCase;

/**
 * DARURAT 2026-09-06 — `toRateLimitString()`'s old burst group
 * (`"{rate} {rate} {rate} 1s/1s {priority}"`) was accepted by
 * `/ppp profile/set` but broke the dynamic `/queue simple` creation when a
 * real PPPoE session connected ("could not add queue: no download-burst-time"),
 * terminating ~200 live customer sessions on ro-hotspot.bajastu.id. Fix:
 * plain `rx-rate/tx-rate` only. `composeRateLimit()` is the correct
 * all-or-nothing burst path for future use.
 */
class RouterOsQueuePriorityTest extends TestCase
{
    public function test_to_rate_limit_string_is_plain_rx_tx_with_no_burst_and_no_priority(): void
    {
        $this->assertSame('15000k/15000k', RouterOsQueuePriority::toRateLimitString(15000, 15000));
        // priority argument is accepted for signature stability but NOT embedded
        // (positionally it can only exist after a full burst group).
        $this->assertSame('5000k/10000k', RouterOsQueuePriority::toRateLimitString(5000, 10000, 3));
    }

    public function test_to_rate_limit_string_never_emits_the_ns_burst_time_that_broke_pppoe(): void
    {
        $result = RouterOsQueuePriority::toRateLimitString(20000, 20000, 1);

        $this->assertStringNotContainsString('1s/1s', $result);
        $this->assertStringNotContainsString(' ', $result);
    }

    public function test_compose_rate_limit_falls_back_to_plain_when_burst_is_incomplete(): void
    {
        // burst-rate + threshold present, burst-time MISSING -> plain fallback
        $this->assertSame(
            '10000k/10000k',
            RouterOsQueuePriority::composeRateLimit(
                10000, 10000,
                burstUploadKbps: 15000, burstDownloadKbps: 15000,
                thresholdUploadKbps: 12000, thresholdDownloadKbps: 12000,
                burstTimeSeconds: null,
            ),
        );

        // only burst-rate present -> plain fallback
        $this->assertSame(
            '10000k/10000k',
            RouterOsQueuePriority::composeRateLimit(10000, 10000, burstUploadKbps: 15000, burstDownloadKbps: 15000),
        );

        // nothing -> plain
        $this->assertSame('10000k/10000k', RouterOsQueuePriority::composeRateLimit(10000, 10000));
    }

    public function test_compose_rate_limit_emits_a_full_valid_burst_group_when_everything_is_present(): void
    {
        $result = RouterOsQueuePriority::composeRateLimit(
            10000, 10000,
            burstUploadKbps: 15000, burstDownloadKbps: 15000,
            thresholdUploadKbps: 12000, thresholdDownloadKbps: 12000,
            burstTimeSeconds: 16,
            priority: 5,
        );

        // rx/tx  burst-rate  burst-threshold  burst-time(plain int, NO "s")  priority
        $this->assertSame('10000k/10000k 15000k/15000k 12000k/12000k 16/16 5', $result);
        $this->assertStringNotContainsString('s/', $result);
    }

    public function test_compose_rate_limit_omits_priority_when_not_given_but_burst_is_complete(): void
    {
        $result = RouterOsQueuePriority::composeRateLimit(
            10000, 10000,
            burstUploadKbps: 15000, burstDownloadKbps: 15000,
            thresholdUploadKbps: 12000, thresholdDownloadKbps: 12000,
            burstTimeSeconds: 8,
        );

        $this->assertSame('10000k/10000k 15000k/15000k 12000k/12000k 8/8', $result);
    }
}
