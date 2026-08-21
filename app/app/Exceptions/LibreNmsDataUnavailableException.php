<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown by LibreNmsService when the LibreNMS REST API or the rrdtool/RRD
 * file path is unreachable/errors — a genuine degraded-dependency state,
 * distinct from "this device legitimately has no sensor of this class"
 * (which methods like getCpuUsage()/getTemperature() represent as an empty
 * array, not an exception — see LibreNmsService's own docblock). Livewire
 * components catch this to render a per-widget "Data monitoring tidak
 * tersedia" state without breaking sibling widgets on the same page — see
 * CLAUDE.md's "Dashboard Monitoring (v0.8.2)" section.
 */
class LibreNmsDataUnavailableException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'data' => null,
            'meta' => [],
        ], 503);
    }
}
