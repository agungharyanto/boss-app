<?php

namespace Tests\Feature\Network;

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
}
