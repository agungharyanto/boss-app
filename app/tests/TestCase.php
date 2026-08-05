<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // v0.6.5 safety net: FreeradiusVirtualServerService writes real
        // files wherever services.freeradius.nas_config_dir points.
        // Without this default, any test exercising NasService::create()/
        // update() (directly, or indirectly via NasIndex Livewire's
        // save()) would silently write into the REAL production shared
        // volume path (/freeradius-nas-config) — found for real running
        // this project's own full suite: it left a stray nas-1.conf in
        // the actual freeradius container's shared volume, which then
        // collided with the real NAS's own allocated port and broke
        // radiusd startup. Same CLASS of "tests touching real
        // infrastructure" bug already fixed once for the DB connection
        // itself (see tests/bootstrap.php + CLAUDE.md's v0.1.0 phpunit
        // entry) — a systemic default here instead of trusting every test
        // file to remember its own override.
        config(['services.freeradius.nas_config_dir' => sys_get_temp_dir().'/phpunit-freeradius-nas-config-'.uniqid()]);
    }
}
