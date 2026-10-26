<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Real incident (v0.8.2-monitoring-fixes): `public/build/assets/*.js` is a
 * compiled artifact, NOT tracked by git — switching branches or merging
 * `resources/js/app.js` changes never regenerates it on its own (`npm run
 * build` must be run manually in this dev environment, or via
 * scripts/deploy.sh in production, see CLAUDE.md "Frontend build"). Across
 * several branch switches during this sprint's own git-history work
 * (v0.8.2 merge into develop, v0.8.3 sync), the built bundle went stale —
 * `window.trafficChart` was completely absent from what was actually being
 * served, even though the SOURCE app.js correctly defined it, because the
 * bundle on disk was still the one built from a DIFFERENT branch's app.js.
 * Alpine's `x-data="trafficChart(...)"` on the Monitoring page's Traffic
 * panel therefore called an undefined global function and silently never
 * rendered anything — exactly the reported "kosong" bug. The fix
 * (rebuilding) leaves no trace in PHP/Blade source at all, so a normal
 * PHPUnit test of DeviceTrafficGraph's own logic (already covered,
 * unaffected, in DeviceTrafficGraphLivewireTest) could never have caught
 * this — this file exists purely to catch a FUTURE recurrence of the same
 * class of staleness, by asserting the CURRENTLY BUILT bundle actually
 * contains every Alpine factory function a Blade view references.
 */
class FrontendBuildTest extends TestCase
{
    public function test_built_bundle_exists_and_is_readable(): void
    {
        $manifestPath = public_path('build/manifest.json');

        $this->assertFileExists(
            $manifestPath,
            'public/build/manifest.json is missing — run `npm run build` (see CLAUDE.md "Frontend build").'
        );

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $jsFile = $manifest['resources/js/app.js']['file'] ?? null;

        $this->assertNotNull($jsFile, 'manifest.json has no entry for resources/js/app.js.');
        $this->assertFileExists(public_path("build/{$jsFile}"));
    }

    /**
     * Scans every Blade view under resources/views for `x-data="someFn("`
     * — a bare identifier immediately followed by `(` (deliberately NOT
     * matching Alpine's own inline object-literal form, `x-data="{ ... }"`,
     * which starts with `{`, not an identifier) — and asserts each such
     * factory name is genuinely registered by ONE of the two mechanisms this
     * codebase actually uses:
     *   (a) `window.<name>` present in the built JS bundle (resources/js/app.js
     *       → public/build) — the staleness guard the 8 chart factories rely on
     *       (trafficChart/signalHistoryChart/deviceHistoryChart/...); OR
     *   (b) registered inline via `Alpine.data('<name>', ...)` inside a
     *       `<script>` on a Blade page itself — work-orders/claim.blade.php's
     *       `claimForm` (a standalone public signed-link page that ships its
     *       own factory, never a window.X global in app.js, so the bundle
     *       check alone would wrongly flag it missing — the v0.13.4.1.1 false
     *       positive this OR-branch exists to fix).
     * Auto-discovers new charts/factories as they're added rather than relying
     * on a manually-maintained list, which would itself be exactly the kind of
     * thing that silently goes stale.
     */
    public function test_every_alpine_factory_referenced_in_blade_views_is_present_in_the_built_bundle(): void
    {
        $builtJs = $this->builtJsBundle();
        $factories = $this->bladeXDataFactories();
        $inlineRegistered = $this->inlineAlpineDataNames();

        $this->assertNotEmpty($factories, 'No x-data="factory(...)" reference found in any Blade view — the scan itself may be broken.');

        foreach ($factories as $factory) {
            $this->assertTrue(
                $this->factoryIsRegistered($factory, $builtJs, $inlineRegistered),
                "Alpine factory '{$factory}' (referenced via x-data in a Blade view) is registered by NEITHER mechanism this codebase uses: ".
                "it is not present as `window.{$factory}` in the built JS bundle (the staleness guard the chart factories rely on — ".
                'run `npm run build`, see CLAUDE.md "Dashboard Monitoring Fixes"), '.
                "AND it is not registered inline via `Alpine.data('{$factory}', ...)` in any Blade view (the pattern work-orders/claim.blade.php uses)."
            );
        }
    }

    /**
     * A factory registered ONLY inline via `Alpine.data('<name>', ...)` on its
     * own Blade page (never as a window.X global in app.js) must count as
     * present — this is the v0.13.4.1.1 fix for the `claimForm` false positive.
     */
    public function test_a_factory_registered_inline_via_alpine_data_in_a_blade_counts_as_present(): void
    {
        $builtJs = $this->builtJsBundle();
        $inlineRegistered = $this->inlineAlpineDataNames();

        $this->assertContains('claimForm', $this->bladeXDataFactories(), 'claimForm should still be referenced via x-data in work-orders/claim.blade.php.');
        $this->assertContains('claimForm', $inlineRegistered, "claimForm must be detected as inline-registered via Alpine.data('claimForm', ...).");
        $this->assertStringNotContainsString('window.claimForm', $builtJs, 'claimForm is deliberately NOT a window.X global in app.js — this test guards the inline path specifically.');
        $this->assertTrue($this->factoryIsRegistered('claimForm', $builtJs, $inlineRegistered));
    }

    /**
     * The guard must NOT be weakened: a factory registered by neither mechanism
     * (not window.X in the bundle, not inline Alpine.data() in any Blade) is
     * still reported missing — this is the exact staleness the chart factories
     * rely on being caught.
     */
    public function test_a_factory_registered_by_neither_mechanism_is_reported_missing(): void
    {
        $builtJs = $this->builtJsBundle();
        $inlineRegistered = $this->inlineAlpineDataNames();

        $this->assertFalse(
            $this->factoryIsRegistered('totallyMadeUpFactoryThatExistsNowhere', $builtJs, $inlineRegistered),
            'A factory registered by neither window.X nor inline Alpine.data() must still be reported missing — the staleness guard must not be weakened.'
        );
    }

    private function builtJsBundle(): string
    {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $jsFile = $manifest['resources/js/app.js']['file'];

        return file_get_contents(public_path("build/{$jsFile}"));
    }

    /**
     * Distinct factory names referenced as `x-data="fn("` across every Blade view.
     *
     * @return list<string>
     */
    private function bladeXDataFactories(): array
    {
        $factories = [];
        foreach ($this->bladeViewContents() as $contents) {
            preg_match_all('/x-data="([a-zA-Z_][a-zA-Z0-9_]*)\(/', $contents, $matches);
            $factories = array_merge($factories, $matches[1]);
        }

        return array_values(array_unique($factories));
    }

    /**
     * Distinct names registered inline via `Alpine.data('name'|"name"` across
     * every Blade view (the standalone-page registration mechanism, as opposed
     * to a window.X global in the built bundle).
     *
     * @return list<string>
     */
    private function inlineAlpineDataNames(): array
    {
        $names = [];
        foreach ($this->bladeViewContents() as $contents) {
            preg_match_all('/Alpine\.data\(\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]/', $contents, $matches);
            $names = array_merge($names, $matches[1]);
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  list<string>  $inlineRegistered
     */
    private function factoryIsRegistered(string $factory, string $builtJs, array $inlineRegistered): bool
    {
        return str_contains($builtJs, "window.{$factory}")
            || in_array($factory, $inlineRegistered, true);
    }

    /**
     * @return \Generator<string>
     */
    private function bladeViewContents(): \Generator
    {
        foreach ((new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        )) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            yield file_get_contents($file->getPathname());
        }
    }

    /**
     * `window.pickBpsUnit` (the dynamic bps/Kbps/Mbps/Gbps unit selector
     * used by `trafficChart`) isn't reached by the x-data scan above — it's
     * called from WITHIN trafficChart's own build(), never referenced
     * directly in a Blade `x-data` attribute. This is a plain presence
     * check (guards against the function silently disappearing or the
     * bundle going stale again), NOT a logic test — the actual
     * bps/Kbps/Mbps/Gbps threshold behavior was verified for real via a
     * one-time Node script run directly against this source file (10
     * scenarios: each unit's low/high boundary plus a real router traffic
     * magnitude, 53 Mbps) — see CLAUDE.md "Dashboard Monitoring Fixes" for
     * the full verification record. No JS test runner (Jest/Vitest/etc.)
     * exists anywhere in this codebase, and adding one for a single ~10-line
     * utility function would be disproportionate — the source itself
     * (resources/js/app.js) is the readable, permanent record of the
     * verified logic.
     */
    public function test_traffic_graph_unit_selector_is_present_in_the_built_bundle(): void
    {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $jsFile = $manifest['resources/js/app.js']['file'];
        $builtJs = file_get_contents(public_path("build/{$jsFile}"));

        $this->assertStringContainsString('window.pickBpsUnit', $builtJs);

        // The 3 threshold constants and all 4 unit labels — a very weak
        // check on their own, but cheap insurance against an accidental
        // edit silently dropping one of them. Checked against the REAL
        // minified form (Vite's build renders 1_000_000 as `1e6`, not the
        // literal digits — confirmed by reading the actual built output
        // before writing this assertion, not assumed).
        foreach (['1e3', '1e6', '1e9'] as $threshold) {
            $this->assertStringContainsString($threshold, $builtJs);
        }

        foreach (['Kbps', 'Mbps', 'Gbps'] as $label) {
            $this->assertStringContainsString($label, $builtJs);
        }
    }
}
