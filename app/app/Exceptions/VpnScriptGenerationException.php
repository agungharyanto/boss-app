<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by VpnScriptService for conditions that are a UI-level "can't do
 * that" rather than an infrastructure failure (e.g. WireGuard + RouterOS 6,
 * or asking for a script for a WireGuard account whose one-time private key
 * is already gone). Rendered directly as a flashed error by the Livewire
 * component, not the API JSON envelope — this is web-UI-only, unlike the
 * other Vpn*Exception classes.
 */
class VpnScriptGenerationException extends RuntimeException {}
