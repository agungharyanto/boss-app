<?php

namespace Tests\Feature\Network;

use App\Models\VpnAccount;
use App\Services\Network\MikrotikScriptGenerator;
use App\Services\Network\ScriptDownloadTokenService;
use Tests\TestCase;

/**
 * Covers the fetch+import replacement for paste-the-whole-script (v0.6.3
 * gap-fix round 3): a real RouterOS device can't authenticate, so the
 * download endpoint is deliberately unauthenticated — these tests instead
 * verify the token itself is the security boundary (single-use, opaque,
 * 404s once consumed or when unknown).
 */
class VpnScriptDownloadTest extends TestCase
{
    public function test_a_valid_token_downloads_the_script_as_plain_text(): void
    {
        $token = app(ScriptDownloadTokenService::class)->store("/system identity print;\n");

        $response = $this->get("/vpn-script-generator/download/{$token}.rsc");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertSame("/system identity print;\n", $response->getContent());
    }

    public function test_a_token_can_only_be_downloaded_once(): void
    {
        $token = app(ScriptDownloadTokenService::class)->store('script-content');

        $this->get("/vpn-script-generator/download/{$token}.rsc")->assertOk();
        $this->get("/vpn-script-generator/download/{$token}.rsc")->assertNotFound();
    }

    public function test_an_unknown_token_404s(): void
    {
        $this->get('/vpn-script-generator/download/not-a-real-token.rsc')->assertNotFound();
    }

    public function test_fetch_command_uses_the_requests_own_scheme_not_a_hardcoded_https(): void
    {
        $command = app(ScriptDownloadTokenService::class)->buildFetchCommand('abc123', 'http://45.123.142.242');

        $this->assertStringContainsString('mode=http ', $command);
        $this->assertStringNotContainsString('mode=https', $command);
        $this->assertStringContainsString('http://45.123.142.242/vpn-script-generator/download/abc123.rsc', $command);
    }

    /**
     * P0 regression, end-to-end through the REAL HTTP download endpoint —
     * not just the generator directly (MikrotikScriptGeneratorTest already
     * covers that in isolation). Confirms the whole chain (generate →
     * store → HTTP download) delivers exactly what was generated, with no
     * corruption introduced anywhere along the way — this is what a real
     * RouterOS `/tool fetch` would actually receive.
     */
    public function test_downloaded_wireguard_script_has_no_stray_or_duplicated_lines(): void
    {
        $account = new VpnAccount;
        $account->username = 'nas-1';
        $account->internal_ip = '172.23.195.2';
        $account->wireguardPrivateKey = 'CLIENTPRIV==';

        $script = app(MikrotikScriptGenerator::class)->wireGuardScript(
            $account, '45.123.142.242', 51822, 'SERVERPUB==', 'CLIENTPRIV==', '172.28.0.224/27', '172.28.0.225',
            nasGatewayIp: '172.23.195.1',
        );

        $token = app(ScriptDownloadTokenService::class)->store($script);

        $response = $this->get("/vpn-script-generator/download/{$token}.rsc");
        $response->assertOk();

        $downloaded = $response->getContent();
        $this->assertSame($script, $downloaded, 'The download endpoint must return the generated script byte-for-byte.');

        $lines = explode("\n", $downloaded);
        $previousContinues = false;

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $previousContinues = false;

                continue;
            }

            if (! $previousContinues) {
                $this->assertMatchesRegularExpression(
                    '/^[#:\/]/',
                    $trimmed,
                    'Line '.($i + 1).' of the DOWNLOADED script is neither a comment/command nor a '
                    .'continuation — looks like corrupted/injected content: "'.$trimmed.'"'
                );
            }

            $previousContinues = str_ends_with(rtrim($line), '\\');
        }

        $this->assertSame(
            1,
            substr_count($downloaded, '/interface wireguard remove [find name="boss-vpn-wireguard"]'),
        );
    }
}
