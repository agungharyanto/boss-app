<?php

namespace Tests\Unit\Services\Company;

use App\Services\Company\TermsRenderer;
use PHPUnit\Framework\TestCase;

class TermsRendererTest extends TestCase
{
    private TermsRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new TermsRenderer;
    }

    public function test_renders_whitelisted_variables_from_context(): void
    {
        $out = $this->renderer->render(
            'Hubungi {nama_perusahaan} di {nomor_cs}, biaya Rp {biaya_bulanan}.',
            ['nama_perusahaan' => 'PT Nusa', 'nomor_cs' => '0812', 'biaya_bulanan' => '150.000'],
        );

        $this->assertSame('Hubungi PT Nusa di 0812, biaya Rp 150.000.', $out);
    }

    public function test_whitelisted_variable_missing_from_context_renders_empty(): void
    {
        $out = $this->renderer->render('Halo {nama_pelanggan}.', []);

        $this->assertSame('Halo .', $out);
    }

    public function test_unknown_variable_is_detected_for_save_rejection(): void
    {
        $unknown = $this->renderer->unknownVariables('Teks {nomor_cs} dan {variabel_aneh} serta {harga_gila}.');

        $this->assertEqualsCanonicalizing(['variabel_aneh', 'harga_gila'], $unknown);
    }

    public function test_body_with_only_whitelisted_variables_has_no_unknowns(): void
    {
        $body = 'Semua: {nama_perusahaan} {alamat_perusahaan} {nomor_cs} {nama_pelanggan} {alamat_pelanggan} {nama_paket} {biaya_bulanan} {tanggal}';

        $this->assertSame([], $this->renderer->unknownVariables($body));
    }

    public function test_tanggal_jatuh_tempo_is_not_a_v1_variable(): void
    {
        // Sengaja BUKAN whitelist — dipastikan terdeteksi sebagai tidak dikenal.
        $this->assertContains('tanggal_jatuh_tempo', $this->renderer->unknownVariables('{tanggal_jatuh_tempo}'));
        $this->assertNotContains('tanggal_jatuh_tempo', TermsRenderer::WHITELIST);
    }

    public function test_sample_context_covers_every_whitelisted_variable(): void
    {
        $sample = $this->renderer->sampleContext();

        foreach (TermsRenderer::WHITELIST as $variable) {
            $this->assertArrayHasKey($variable, $sample, "sampleContext harus punya {$variable}");
        }

        // Pratinjau tidak boleh menyisakan placeholder mentah untuk variabel whitelist.
        $rendered = $this->renderer->render(implode(' ', array_map(fn ($v) => '{'.$v.'}', TermsRenderer::WHITELIST)), $sample);
        $this->assertDoesNotMatchRegularExpression('/\{[a-zA-Z0-9_]+\}/', $rendered);
    }
}
