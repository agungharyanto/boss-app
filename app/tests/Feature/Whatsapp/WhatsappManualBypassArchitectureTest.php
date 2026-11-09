<?php

namespace Tests\Feature\Whatsapp;

use Tests\TestCase;

/**
 * v0.13.5.3 — jaminan ARSITEKTUR: bypass saklar `customer_auto_notify_enabled`
 * (lewat `InvoiceSendContext` pada `buildAndQueue()`) HANYA boleh dibangun oleh
 * `InvoicePdfSendService`. Tidak ada pemanggil OTOMATIS (command/event lama)
 * yang bisa memakai bypass. Scan source, bukan reflection — murah & tegas.
 */
class WhatsappManualBypassArchitectureTest extends TestCase
{
    private function appPhpFiles(): array
    {
        $dir = base_path('app');
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function test_invoice_send_context_is_constructed_only_in_the_manual_service(): void
    {
        $constructors = [];
        foreach ($this->appPhpFiles() as $path) {
            $src = (string) file_get_contents($path);
            if (str_contains($src, 'new InvoiceSendContext(')) {
                $constructors[] = str_replace(base_path().'/', '', $path);
            }
        }

        $this->assertSame(
            ['app/Services/Billing/InvoicePdfSendService.php'],
            $constructors,
            'InvoiceSendContext hanya boleh dibangun oleh InvoicePdfSendService (jalur kirim manual).'
        );
    }

    public function test_automatic_callers_do_not_pass_a_send_context(): void
    {
        // Pemanggil OTOMATIS buildAndQueue() (4 event pelanggan + command).
        $automaticCallers = [
            'app/Services/InvoiceService.php',          // payment_received
            'app/Services/RegistrationService.php',     // customer_registered
            'app/Console/Commands/SendWhatsappDueReminders.php',
            'app/Console/Commands/SendWhatsappSuspendedReminders.php',
        ];

        foreach ($automaticCallers as $relative) {
            $src = (string) file_get_contents(base_path($relative));
            $this->assertStringNotContainsString(
                'InvoiceSendContext',
                $src,
                "{$relative} tidak boleh menyebut InvoiceSendContext (jalur otomatis tetap ber-guard)."
            );
        }
    }
}
