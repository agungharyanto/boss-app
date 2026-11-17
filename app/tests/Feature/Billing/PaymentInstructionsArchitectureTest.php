<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\PaymentInstructionsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * v0.13.5.4 — JAGA ARSITEKTUR: jalur render instruksi pembayaran TIDAK PERNAH
 * memanggil Xendit / membuat tautan bayar. Sumber PaymentInstructionsService
 * dilarang menyebut createPaymentFor / XenditGatewayService / Http.
 */
class PaymentInstructionsArchitectureTest extends TestCase
{
    public function test_service_source_never_references_xendit_or_http(): void
    {
        $file = (new ReflectionClass(PaymentInstructionsService::class))->getFileName();
        // Buang komentar/whitespace supaya hanya KODE yang diperiksa (docblock
        // boleh menyebut istilah ini untuk menjelaskan larangannya).
        $src = php_strip_whitespace($file);

        foreach (['createPaymentFor', 'XenditGatewayService', 'Http::', 'PaymentService'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "PaymentInstructionsService tidak boleh memakai {$forbidden} (jalur render tanpa Xendit).");
        }
    }
}
