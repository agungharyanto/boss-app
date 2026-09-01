{{--
    v0.16.0 Langkah 5 — shared Leaflet location picker. Included by
    FiberNodeForm (create) and GpsPhotoCapture (edit, used by OdpEdit).
    The host owns the Latitude/Longitude inputs and the "Ambil lokasi
    saya" button; this partial is only the map, two-way bound to the
    host's own `latitude`/`longitude` Livewire properties via the
    window.fiberLocationMap factory (resources/js/app.js).

    $mapPoints — list<array{latitude,longitude,label,type}> of other
    existing topology points, drawn as read-only grey markers.

    Leaflet is bundled via Vite (leaflet in package.json, imported in
    app.js) — same approach as chart.js, not a CDN <script>, so
    FrontendBuildTest covers the factory and there's no runtime CDN
    dependency for an internal tool. OSM tile images still load from
    tile.openstreetmap.org at runtime (no API key, no CSP to allowlist).
--}}
<div
    wire:ignore
    x-data="fiberLocationMap({ points: @js($mapPoints ?? []) })"
    class="rounded-md border border-gray-200 overflow-hidden"
>
    <div x-ref="map" class="h-64 w-full bg-gray-100" role="application" aria-label="{{ __('Peta pemilih lokasi — seret pin untuk menetapkan koordinat') }}"></div>
</div>
