import './bootstrap';
import { Chart } from 'chart.js/auto';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
// Vite rewrites these three to real asset URLs — Leaflet's own default
// marker icon paths (relative to leaflet.css) don't survive bundling, the
// standard documented fix. Tile images still come straight from OSM's CDN
// at runtime (unavoidable, and this app has no CSP to allowlist).
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';

L.Icon.Default.mergeOptions({
    iconRetinaUrl: markerIcon2x,
    iconUrl: markerIcon,
    shadowUrl: markerShadow,
});

// v0.16.0 Langkah 5 — the location picker shared by FiberNodeForm (create
// AND edit) and GpsPhotoCapture (edit, used by OdpEdit). Leaflet + free
// OSM tiles, no API key. Two-way bound to the host component's own
// `latitude`/`longitude` Livewire properties (both components use those
// exact names): dragging/clicking the pin writes them, typing into the
// fields moves the pin. Grey circle markers are other existing topology
// points, read-only reference only. The map <div> lives inside a
// `wire:ignore` wrapper (same "a JS library owns this subtree" reasoning
// as trafficChart's canvas) so Livewire's DOM morph never wipes Leaflet's
// own DOM out from under it.
window.fiberLocationMap = function ({ points }) {
    // Central Java-ish fallback centre for a brand-new point with no
    // coordinates surveyed yet — the pin still starts placeable.
    const FALLBACK = [-6.9, 109.65];

    const parse = (v) => {
        const n = parseFloat(v);
        return Number.isFinite(n) ? n : null;
    };

    return {
        map: null,
        marker: null,
        syncingFromField: false,

        init() {
            const lat = parse(this.$wire.latitude);
            const lng = parse(this.$wire.longitude);
            const hasCoords = lat !== null && lng !== null;

            this.map = L.map(this.$refs.map, { scrollWheelZoom: false }).setView(
                hasCoords ? [lat, lng] : FALLBACK,
                hasCoords ? 16 : 11,
            );

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap',
                maxZoom: 19,
            }).addTo(this.map);

            (points || []).forEach((p) => {
                L.circleMarker([p.latitude, p.longitude], {
                    radius: 5,
                    color: '#6b7280',
                    weight: 1,
                    fillColor: '#9ca3af',
                    fillOpacity: 0.7,
                })
                    .addTo(this.map)
                    .bindTooltip(p.label, { direction: 'top' });
            });

            this.marker = L.marker(hasCoords ? [lat, lng] : FALLBACK, { draggable: true }).addTo(this.map);
            this.marker.on('dragend', () => this.writeFields(this.marker.getLatLng()));
            this.map.on('click', (e) => {
                this.marker.setLatLng(e.latlng);
                this.writeFields(e.latlng);
            });

            this.$watch('$wire.latitude', () => this.syncFromFields());
            this.$watch('$wire.longitude', () => this.syncFromFields());

            // Leaflet mis-measures its container when it initialises inside
            // a not-yet-fully-laid-out element (common under Livewire).
            setTimeout(() => this.map.invalidateSize(), 200);
        },

        writeFields(latlng) {
            this.syncingFromField = true;
            this.$wire.set('latitude', latlng.lat.toFixed(7), false);
            this.$wire.set('longitude', latlng.lng.toFixed(7), false);
            this.$nextTick(() => {
                this.syncingFromField = false;
            });
        },

        syncFromFields() {
            if (this.syncingFromField) {
                return;
            }
            const lat = parse(this.$wire.latitude);
            const lng = parse(this.$wire.longitude);
            if (lat === null || lng === null) {
                return;
            }
            this.marker.setLatLng([lat, lng]);
            this.map.setView([lat, lng], Math.max(this.map.getZoom(), 15));
        },
    };
};

// v0.16.0 Langkah 8/9 — "Peta Topologi" map (App\Livewire\Network\
// FiberTopologyMap). All fiber_nodes/odps as markers, NO cable lines by
// default. `lines` (initial + subsequent, via the dispatched
// `topology-lines-updated` browser event since the map is inside
// wire:ignore) each draw one polyline per CABLE (unit of selection is
// always the cable, never a core): endpoints[0] -> waypoints ->
// endpoints[1], in the neutral colour the server sends. Clicking a
// polyline makes that cable editable — drag its waypoint handles, click
// the line to insert a new one, double-click a handle to remove it —
// then "Simpan Rute" calls $wire.saveRoute(cableId, points), which
// replaces every waypoint of that cable server-side. Langkah 9 also adds
// an Esri World Imagery satellite base layer (free, no API key) via
// Leaflet's standard layer control.
window.fiberTopologyMap = function ({ markers, customers, lines, canManage, defaultLayers }) {
    const NODE_COLORS = {
        otb: '#2563eb',
        closure: '#7c3aed',
        odc: '#16a34a',
        odp: '#f97316',
        customer: '#dc2626',
    };
    const CATEGORY_LABELS = {
        cable: 'Kabel',
        otb: 'OTB',
        closure: 'Closure',
        odc: 'ODC',
        odp: 'ODP',
        customer: 'Pelanggan',
    };
    const on = new Set(defaultLayers || []);

    return {
        map: null,
        groups: {},
        editLayer: null,
        drawn: [],
        canManage: !!canManage,

        editCableId: null,
        editLabel: '',
        editColor: '#6366f1',
        editWaypoints: [],
        editServerWaypoints: [],
        editEndpoints: [],

        init() {
            const anchor = (markers || [])[0] || (customers || [])[0];
            this.map = L.map(this.$refs.map, { scrollWheelZoom: true }).setView(
                anchor ? [anchor.latitude, anchor.longitude] : [-6.9, 109.65],
                anchor ? 13 : 10,
            );

            const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap',
                maxZoom: 19,
            }).addTo(this.map);

            // Esri World Imagery — free for general use, no API key.
            const satellite = L.tileLayer(
                'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                {
                    attribution: 'Tiles &copy; Esri — Source: Esri, Maxar, Earthstar Geographics',
                    maxZoom: 19,
                },
            );

            // one layerGroup per category; on-by-default ones get added to
            // the map now, the rest only appear when their box is checked.
            ['cable', 'otb', 'closure', 'odc', 'odp', 'customer'].forEach((cat) => {
                this.groups[cat] = L.layerGroup();
                if (on.has(cat)) {
                    this.groups[cat].addTo(this.map);
                }
            });
            this.editLayer = L.layerGroup().addTo(this.map);

            const overlays = {};
            Object.keys(CATEGORY_LABELS).forEach((cat) => {
                overlays[CATEGORY_LABELS[cat]] = this.groups[cat];
            });
            L.control.layers(
                { Peta: osm, Satelit: satellite },
                overlays,
                { position: 'topright', collapsed: false },
            ).addTo(this.map);

            (markers || []).forEach((m) => {
                const group = this.groups[m.node_type];
                if (!group) {
                    return;
                }
                const marker = L.circleMarker([m.latitude, m.longitude], {
                    radius: 6,
                    color: '#374151',
                    weight: 1,
                    fillColor: NODE_COLORS[m.node_type] || '#9ca3af',
                    fillOpacity: 0.85,
                })
                    .addTo(group)
                    .bindTooltip(m.label, { direction: 'top' });

                // v0.16.0 Langkah 11 Bagian E — ODP marker popup shows the
                // same "X/Y port terpakai" + traffic-light badge as the
                // Capacity Report (data comes straight from
                // FiberTopologyService::odpCapacities()).
                if (m.node_type === 'odp') {
                    marker.bindPopup(this.odpPopupHtml(m));
                }
            });

            (customers || []).forEach((c) => {
                const rows = ['<strong>' + this.esc(c.name) + '</strong>'];
                if (c.address) {
                    rows.push(this.esc(c.address));
                }
                rows.push('<em>' + this.esc(c.status) + '</em>');
                L.marker([c.latitude, c.longitude], {
                    icon: L.divIcon({
                        className: 'boss-customer-marker',
                        html: '<span style="display:block;width:14px;height:14px;border:2px solid #fff;border-radius:3px;background:' +
                            NODE_COLORS.customer + ';box-shadow:0 0 0 1px #991b1b;"></span>',
                        iconSize: [14, 14],
                        iconAnchor: [7, 7],
                    }),
                })
                    .addTo(this.groups.customer)
                    .bindPopup(rows.join('<br>'));
            });

            this.renderLines(lines || []);

            if (this.$wire) {
                this.$wire.on('topology-lines-updated', (payload) => {
                    const next = Array.isArray(payload) ? payload[0]?.lines : payload?.lines;
                    this.stopEditing();
                    this.renderLines(next || []);
                });
            }

            setTimeout(() => this.map.invalidateSize(), 200);
        },

        esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
            })[c]);
        },

        odpPopupHtml(m) {
            let html = '<strong>' + this.esc(m.label) + '</strong>';
            const cap = m.capacity;
            if (!cap) {
                return html + '<br><span style="color:#9ca3af">Kapasitas port belum diatur</span>';
            }
            const badge = '<span style="display:inline-block;padding:1px 6px;border-radius:9999px;font-size:11px;color:#fff;background:' +
                cap.zone_color + '">' + this.esc(cap.zone_label) +
                (cap.percent === null ? '' : ' · ' + cap.percent + '%') + '</span>';
            return html + '<br>' + cap.used + '/' + cap.total + ' port terpakai ' + badge;
        },

        fullPoints(line) {
            return [line.endpoints[0], ...line.waypoints, line.endpoints[1]];
        },

        renderLines(list) {
            this.groups.cable.clearLayers();
            this.drawn = [];

            (list || []).forEach((line) => {
                const poly = L.polyline(this.fullPoints(line), {
                    color: line.color,
                    weight: 4,
                    opacity: 0.9,
                }).addTo(this.groups.cable);

                poly.bindTooltip(line.label, { sticky: true });
                poly.on('click', () => this.startEditing(line));
                this.drawn.push({ line, poly });
            });

            // auto-reveal the "Kabel" layer once something is selected —
            // the picker/chip UI is the deliberate action, no reason to
            // also make the user hunt for the checkbox.
            if ((list || []).length > 0 && !this.map.hasLayer(this.groups.cable)) {
                this.groups.cable.addTo(this.map);
            }

            const bounds = [];
            (list || []).forEach((line) => this.fullPoints(line).forEach((p) => bounds.push(p)));
            if (bounds.length > 0) {
                this.map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
            }
        },

        startEditing(line) {
            if (!this.canManage) {
                return;
            }
            this.editCableId = line.cable_id;
            this.editLabel = line.label;
            this.editColor = line.color;
            this.editServerWaypoints = line.waypoints.map((p) => [p[0], p[1]]);
            this.editWaypoints = this.editServerWaypoints.map((p) => [p[0], p[1]]);
            this.editEndpoints = [line.endpoints[0], line.endpoints[1]];
            this.redrawEdit();
        },

        stopEditing() {
            this.editCableId = null;
            this.editLabel = '';
            this.editWaypoints = [];
            this.editServerWaypoints = [];
            if (this.editLayer) {
                this.editLayer.clearLayers();
            }
        },

        resetRoute() {
            this.editWaypoints = this.editServerWaypoints.map((p) => [p[0], p[1]]);
            this.redrawEdit();
        },

        editFullPoints() {
            return [this.editEndpoints[0], ...this.editWaypoints, this.editEndpoints[1]];
        },

        redrawEdit() {
            this.editLayer.clearLayers();
            if (this.editCableId === null) {
                return;
            }

            const line = L.polyline(this.editFullPoints(), {
                color: this.editColor,
                weight: 4,
                dashArray: '6 4',
            }).addTo(this.editLayer);

            line.on('click', (e) => this.insertWaypoint(e.latlng));

            this.editWaypoints.forEach((wp, idx) => {
                const handle = L.circleMarker(wp, {
                    radius: 6,
                    color: '#111827',
                    weight: 2,
                    fillColor: '#ffffff',
                    fillOpacity: 1,
                }).addTo(this.editLayer);

                handle.on('mousedown', () => this.dragHandle(handle, idx));
                handle.on('dblclick', () => {
                    this.editWaypoints.splice(idx, 1);
                    this.redrawEdit();
                });
                handle.bindTooltip('Titik ' + (idx + 1) + ' — seret untuk pindah, klik dua kali untuk hapus');
            });
        },

        dragHandle(handle, idx) {
            this.map.dragging.disable();
            const move = (e) => {
                this.editWaypoints[idx] = [e.latlng.lat, e.latlng.lng];
                handle.setLatLng(e.latlng);
                this.redrawEditLineOnly();
            };
            const up = () => {
                this.map.off('mousemove', move);
                this.map.off('mouseup', up);
                this.map.dragging.enable();
                this.redrawEdit();
            };
            this.map.on('mousemove', move);
            this.map.on('mouseup', up);
        },

        redrawEditLineOnly() {
            // cheap update of just the dashed line while dragging
            this.editLayer.eachLayer((l) => {
                if (l instanceof L.Polyline) {
                    l.setLatLngs(this.editFullPoints());
                }
            });
        },

        insertWaypoint(latlng) {
            const pts = this.editFullPoints();
            let bestSeg = 0;
            let bestDist = Infinity;
            const clicked = this.map.latLngToLayerPoint(latlng);

            for (let i = 0; i < pts.length - 1; i++) {
                const a = this.map.latLngToLayerPoint(L.latLng(pts[i]));
                const b = this.map.latLngToLayerPoint(L.latLng(pts[i + 1]));
                const d = L.LineUtil.pointToSegmentDistance(clicked, a, b);
                if (d < bestDist) {
                    bestDist = d;
                    bestSeg = i;
                }
            }

            // segment bestSeg is between full-point bestSeg and bestSeg+1;
            // waypoints occupy full indices 1..n, so insert at editWaypoints[bestSeg].
            this.editWaypoints.splice(bestSeg, 0, [latlng.lat, latlng.lng]);
            this.redrawEdit();
        },

        persistRoute() {
            if (this.editCableId === null || !this.$wire) {
                return;
            }
            const points = this.editWaypoints.map((p) => ({ lat: p[0], lng: p[1] }));
            this.$wire.saveRoute(this.editCableId, points);
        },
    };
};

// v0.16.0 Langkah 11 — "Cek Jalur ke ODP" map (App\Livewire\Network\
// OdpRouteCheck). A draggable prospect pin (two-way bound to
// $wire.latitude/$wire.longitude, same as fiberLocationMap), the nearest
// ODP candidates as clickable markers (click sets $wire.targetOdpId),
// and — after "Hitung Rute" — every route OSRM returned drawn at once:
// the shortest ("Rekomendasi") solid + bold, the alternatives dashed +
// thinner, each its own colour. Route geometry arrives as GeoJSON
// [lng,lat] pairs via the dispatched `routes-updated` event (map is
// inside wire:ignore).
window.odpRouteMap = function ({ candidates, lat, lng }) {
    const ROUTE_COLORS = ['#2563eb', '#f97316', '#16a34a', '#7c3aed', '#dc2626', '#0891b2', '#ca8a04'];
    const FALLBACK_CENTER = [-6.9, 109.65];
    const parse = (v) => {
        const n = parseFloat(v);
        return Number.isFinite(n) ? n : null;
    };

    return {
        map: null,
        pin: null,
        candidateLayer: null,
        routeLayer: null,
        syncingFromField: false,

        init() {
            const startLat = parse(this.$wire.latitude) ?? parse(lat);
            const startLng = parse(this.$wire.longitude) ?? parse(lng);
            const hasStart = startLat !== null && startLng !== null;

            this.map = L.map(this.$refs.map, { scrollWheelZoom: true }).setView(
                hasStart ? [startLat, startLng] : FALLBACK_CENTER,
                hasStart ? 15 : 11,
            );

            const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap',
                maxZoom: 19,
            }).addTo(this.map);
            const satellite = L.tileLayer(
                'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                { attribution: 'Tiles &copy; Esri', maxZoom: 19 },
            );
            L.control.layers({ Peta: osm, Satelit: satellite }, {}, { position: 'topright' }).addTo(this.map);

            this.candidateLayer = L.layerGroup().addTo(this.map);
            this.routeLayer = L.layerGroup().addTo(this.map);

            this.pin = L.marker(hasStart ? [startLat, startLng] : FALLBACK_CENTER, { draggable: true })
                .addTo(this.map)
                .bindTooltip('Lokasi calon pelanggan', { direction: 'top' });
            this.pin.on('dragend', () => this.writeFields(this.pin.getLatLng()));
            this.map.on('click', (e) => {
                this.pin.setLatLng(e.latlng);
                this.writeFields(e.latlng);
            });

            this.drawCandidates(candidates || []);

            this.$watch('$wire.latitude', () => this.syncFromFields());
            this.$watch('$wire.longitude', () => this.syncFromFields());

            if (this.$wire) {
                this.$wire.on('routes-updated', (e) => {
                    const payload = Array.isArray(e) ? e[0]?.payload : e?.payload;
                    this.drawRoutes(payload || {});
                });
                this.$wire.on('candidates-updated', (e) => {
                    const next = Array.isArray(e) ? e[0]?.candidates : e?.candidates;
                    this.drawCandidates(next || []);
                });
            }

            setTimeout(() => this.map.invalidateSize(), 200);
        },

        esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
            })[c]);
        },

        drawCandidates(list) {
            this.candidateLayer.clearLayers();
            (list || []).forEach((c) => {
                const badge = '<span style="display:inline-block;padding:1px 6px;border-radius:9999px;font-size:11px;color:#fff;background:' +
                    c.zone_color + '">' + this.esc(c.zone_label) + '</span>';
                L.circleMarker([c.latitude, c.longitude], {
                    radius: 7, color: '#7c2d12', weight: 2, fillColor: '#f97316', fillOpacity: 0.9,
                })
                    .addTo(this.candidateLayer)
                    .bindPopup(
                        '<strong>' + this.esc(c.code + ' - ' + c.name) + '</strong><br>' +
                        c.distance_km + ' km · ' + c.used_ports + '/' + c.total_ports + ' port ' + badge +
                        '<br><button type="button" data-odp="' + c.odp_id +
                        '" class="odp-pick" style="margin-top:4px;padding:2px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;cursor:pointer">Pilih ODP ini</button>',
                    );
            });
            this.candidateLayer.on('popupopen', (e) => {
                const btn = e.popup.getElement().querySelector('.odp-pick');
                if (btn) {
                    btn.addEventListener('click', () => {
                        this.$wire.set('targetOdpId', parseInt(btn.dataset.odp, 10));
                        this.map.closePopup();
                    });
                }
            });
        },

        drawRoutes(payload) {
            this.routeLayer.clearLayers();
            const options = payload.options || [];
            const all = [];

            options.forEach((opt, i) => {
                const latlngs = (opt.geometry?.coordinates || []).map((p) => [p[1], p[0]]);
                if (latlngs.length < 2) {
                    return;
                }
                const isRec = i === 0;
                L.polyline(latlngs, {
                    color: ROUTE_COLORS[i % ROUTE_COLORS.length],
                    weight: isRec ? 6 : 4,
                    opacity: 0.9,
                    dashArray: isRec ? null : (opt.is_fallback ? '4 6' : '8 6'),
                })
                    .addTo(this.routeLayer)
                    .bindTooltip(opt.label + ' — ' + this.km(opt.distance_meters), { sticky: true });
                latlngs.forEach((p) => all.push(p));
            });

            if (payload.to) {
                L.circleMarker(payload.to, { radius: 7, color: '#7c2d12', weight: 2, fillColor: '#f97316', fillOpacity: 1 })
                    .addTo(this.routeLayer)
                    .bindTooltip('ODP tujuan', { direction: 'top' });
                all.push(payload.to);
            }
            if (payload.from) {
                all.push(payload.from);
            }
            if (all.length > 0) {
                this.map.fitBounds(all, { padding: [40, 40], maxZoom: 17 });
            }
        },

        km(m) {
            return m >= 1000 ? (m / 1000).toFixed(2) + ' km' : Math.round(m) + ' m';
        },

        writeFields(latlng) {
            this.syncingFromField = true;
            this.$wire.set('latitude', latlng.lat.toFixed(7), false);
            this.$wire.set('longitude', latlng.lng.toFixed(7), false);
            this.$nextTick(() => { this.syncingFromField = false; });
        },

        syncFromFields() {
            if (this.syncingFromField) {
                return;
            }
            const la = parse(this.$wire.latitude);
            const ln = parse(this.$wire.longitude);
            if (la === null || ln === null) {
                return;
            }
            this.pin.setLatLng([la, ln]);
            this.map.setView([la, ln], Math.max(this.map.getZoom(), 15));
        },
    };
};

// v0.8.2-monitoring-fixes — picks ONE bps/Kbps/Mbps/Gbps unit for the
// WHOLE graph, based on the single largest value across both In and Out
// series (never a per-point unit — a graph mixing units per point would
// be unreadable). Exported on window (not just a closure inside
// trafficChart) so it's independently verifiable — see
// tests/Feature/FrontendBuildTest.php's own docblock for why this
// codebase has no JS test runner and what "verified" means for this
// function instead (a real, one-time Node script run, documented in
// CLAUDE.md "Dashboard Monitoring Fixes").
window.pickBpsUnit = function (maxBps) {
    if (maxBps >= 1_000_000_000) {
        return { divisor: 1_000_000_000, label: 'Gbps' };
    }
    if (maxBps >= 1_000_000) {
        return { divisor: 1_000_000, label: 'Mbps' };
    }
    if (maxBps >= 1_000) {
        return { divisor: 1_000, label: 'Kbps' };
    }

    return { divisor: 1, label: 'bps' };
};

// v0.8.2 — DeviceTrafficGraph's traffic chart. Chart.js instantiates
// against a canvas inside a `wire:ignore` wrapper (see
// resources/views/livewire/network/device-traffic-graph.blade.php) —
// Livewire never touches that subtree after first render, same
// "third-party JS library owns this subtree" reasoning already documented
// for OltDeviceIndex's DataTables table (CLAUDE.md "Network Navigation
// Restructure & OLT Credential Registry (v0.8.1)"). Updates arrive via a
// dispatched `traffic-series-updated` browser event instead of a Livewire
// re-render, so the chart instance is destroyed/rebuilt with fresh data
// without ever being wiped by Livewire's own DOM morph.
window.trafficChart = function (initialSeries) {
    return {
        chart: null,
        init() {
            this.chart = this.build(initialSeries || []);
        },
        update(detail) {
            const series = (detail && detail.series) || [];

            if (this.chart) {
                this.chart.destroy();
            }

            this.chart = this.build(series);
        },
        build(series) {
            const labels = series.map((p) => new Date(p.timestamp * 1000).toLocaleTimeString());
            // LibreNMS's own RRD stores INOCTETS/OUTOCTETS (bytes/second,
            // via a DERIVE datasource) — converted to bits/second here to
            // match the networking convention LibreNMS's own graphs use.
            const inBps = series.map((p) => (p.in_bytes_per_second !== null ? p.in_bytes_per_second * 8 : null));
            const outBps = series.map((p) => (p.out_bytes_per_second !== null ? p.out_bytes_per_second * 8 : null));

            // v0.8.2-monitoring-fixes — ONE unit for the whole graph,
            // chosen from the single largest value across BOTH series
            // (not per-point, not per-dataset) — see window.pickBpsUnit's
            // own docblock.
            const allValues = [...inBps, ...outBps].filter((v) => v !== null);
            const maxBps = allValues.length > 0 ? Math.max(...allValues) : 0;
            const unit = window.pickBpsUnit(maxBps);

            const inData = inBps.map((v) => (v !== null ? v / unit.divisor : null));
            const outData = outBps.map((v) => (v !== null ? v / unit.divisor : null));

            // Same theme-consistency reasoning as signalHistoryChart's own
            // tooltip below — read from the app's live CSS variable rather
            // than a hardcoded hex value.
            const rootStyle = getComputedStyle(document.documentElement);
            const textColor = rootStyle.getPropertyValue('--color-text').trim() || '#1f2937';

            return new Chart(this.$refs.canvas, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        // pointRadius: 2 — same value as signalHistoryChart's
                        // own dataset (RX Power History), reused verbatim
                        // rather than a new number: pointRadius: 0 (this
                        // chart's original value) leaves Chart.js's default
                        // pointHitRadius (1px) as the ONLY hoverable area
                        // around an otherwise-invisible point, which is why
                        // hovering never showed a dot before this fix.
                        { label: 'In', data: inData, borderColor: '#2563eb', backgroundColor: '#2563eb', tension: 0.3, pointRadius: 2 },
                        { label: 'Out', data: outData, borderColor: '#16a34a', backgroundColor: '#16a34a', tension: 0.3, pointRadius: 2 },
                    ],
                },
                options: {
                    responsive: true,
                    animation: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: unit.label },
                        },
                    },
                    plugins: {
                        // v0.8.2-monitoring-fixes — reuses the exact same
                        // precision-tooltip pattern built for
                        // CpeSignalHistoryGraph/signalHistoryChart (full
                        // local date+time title, real-unit body), not a
                        // new one invented for this chart.
                        tooltip: {
                            backgroundColor: '#ffffff',
                            titleColor: textColor,
                            bodyColor: textColor,
                            borderColor: '#e5e7eb',
                            borderWidth: 1,
                            padding: 10,
                            callbacks: {
                                title(items) {
                                    const point = series[items[0].dataIndex];
                                    return new Date(point.timestamp * 1000).toLocaleString('id-ID', {
                                        day: 'numeric',
                                        month: 'short',
                                        year: 'numeric',
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    });
                                },
                                label(item) {
                                    const value = item.raw;

                                    return value === null || value === undefined
                                        ? `${item.dataset.label}: -`
                                        : `${item.dataset.label}: ${value.toFixed(2)} ${unit.label}`;
                                },
                            },
                        },
                    },
                },
            });
        },
    };
};

// v0.8.3 — CpeSignalHistoryGraph's RX Power history chart. Same
// wire:ignore + dispatched-browser-event update mechanism as v0.8.2's
// DeviceTrafficGraph/trafficChart above — reused directly, not reinvented.
// A separate factory function from trafficChart because the data shape
// differs (one value per point, not an in/out pair).
window.signalHistoryChart = function (initialSeries) {
    return {
        chart: null,
        init() {
            this.chart = this.build(initialSeries || []);
        },
        update(detail) {
            const series = (detail && detail.series) || [];

            if (this.chart) {
                this.chart.destroy();
            }

            this.chart = this.build(series);
        },
        build(series) {
            const labels = series.map((p) => new Date(p.recorded_at * 1000).toLocaleString());
            const rxData = series.map((p) => p.rx_power_dbm);

            // Read the app's own theme CSS variables (resources/css/app.css
            // --color-text/--color-primary, user-editable via Pengaturan
            // Tema) at build time rather than hardcoding hex values, so the
            // tooltip stays visually consistent with whatever theme is
            // currently active instead of drifting from it.
            const rootStyle = getComputedStyle(document.documentElement);
            const textColor = rootStyle.getPropertyValue('--color-text').trim() || '#1f2937';

            return new Chart(this.$refs.canvas, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'RX Power (dBm)',
                            data: rxData,
                            borderColor: '#7c3aed',
                            backgroundColor: '#7c3aed',
                            tension: 0.3,
                            pointRadius: 2,
                            // Explicit, not just relying on the library
                            // default — a null reading (a real, confirmed
                            // gap, see CLAUDE.md's "RX Power History
                            // (v0.8.3)") must render as a genuine break in
                            // the line, never a misleading straight
                            // connection across the gap or a false 0.
                            spanGaps: false,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    animation: false,
                    scales: {
                        y: {
                            title: { display: true, text: 'dBm' },
                        },
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#ffffff',
                            titleColor: textColor,
                            bodyColor: textColor,
                            borderColor: '#e5e7eb',
                            borderWidth: 1,
                            padding: 10,
                            displayColors: false,
                            callbacks: {
                                // Full local date+time for the exact hovered
                                // point (e.g. "21 Agu 2026, 04.00") — Chart.js's
                                // default title is just the raw label string,
                                // not reliably this precise across every
                                // range/browser locale combination.
                                title(items) {
                                    const point = series[items[0].dataIndex];
                                    return new Date(point.recorded_at * 1000).toLocaleString('id-ID', {
                                        day: 'numeric',
                                        month: 'short',
                                        year: 'numeric',
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    });
                                },
                                label(item) {
                                    const value = item.raw;

                                    return value === null || value === undefined
                                        ? 'RX Power: -'
                                        : `RX Power: ${value.toFixed(2)} dBm`;
                                },
                            },
                        },
                    },
                },
            });
        },
    };
};

// v0.8.4 Bagian D — DeviceHistoryModal's CPU/Memory/Suhu history chart.
// Same wire:ignore + dispatched-browser-event mechanism as trafficChart/
// signalHistoryChart above. Unlike either of those (a fixed 1 or 2
// datasets), a device can have SEVERAL sensors of the same class (e.g. a
// real ZTE C300 OLT has 7 processor sensors, one per line card) — every
// sensor gets its OWN line, never averaged away, so this factory takes a
// variable-length `series` array (`[{sensor_id, label, points}]`) and
// builds one dataset per entry. X-axis labels come from the FIRST series'
// own timestamps (every sensor on the same device is polled on the same
// interval in practice — a reasonable, not perfect, alignment assumption,
// same simplification already accepted for the single/dual-series charts
// above).
window.deviceHistoryChart = function (initialSeries, initialUnit) {
    const palette = ['#7c3aed', '#2563eb', '#16a34a', '#d97706', '#dc2626', '#0891b2', '#db2777', '#65a30d'];

    return {
        chart: null,
        unit: initialUnit || '',
        init() {
            this.chart = this.build(initialSeries || [], this.unit);
        },
        update(detail) {
            const series = (detail && detail.series) || [];
            this.unit = (detail && detail.unit) || '';

            if (this.chart) {
                this.chart.destroy();
            }

            this.chart = this.build(series, this.unit);
        },
        build(series, unit) {
            const referencePoints = (series[0] && series[0].points) || [];
            const labels = referencePoints.map((p) => new Date(p.timestamp * 1000).toLocaleString());

            const rootStyle = getComputedStyle(document.documentElement);
            const textColor = rootStyle.getPropertyValue('--color-text').trim() || '#1f2937';

            const datasets = series.map((s, index) => {
                const color = palette[index % palette.length];

                return {
                    label: s.label,
                    data: s.points.map((p) => p.value),
                    borderColor: color,
                    backgroundColor: color,
                    tension: 0.3,
                    pointRadius: 2,
                    spanGaps: false,
                };
            });

            return new Chart(this.$refs.canvas, {
                type: 'line',
                data: { labels, datasets },
                options: {
                    responsive: true,
                    animation: false,
                    scales: {
                        y: {
                            title: { display: true, text: unit },
                        },
                    },
                    plugins: {
                        legend: { display: series.length > 1 },
                        tooltip: {
                            backgroundColor: '#ffffff',
                            titleColor: textColor,
                            bodyColor: textColor,
                            borderColor: '#e5e7eb',
                            borderWidth: 1,
                            padding: 10,
                            callbacks: {
                                title(items) {
                                    const point = referencePoints[items[0].dataIndex];
                                    return point
                                        ? new Date(point.timestamp * 1000).toLocaleString('id-ID', {
                                              day: 'numeric',
                                              month: 'short',
                                              year: 'numeric',
                                              hour: '2-digit',
                                              minute: '2-digit',
                                          })
                                        : '';
                                },
                                label(item) {
                                    const value = item.raw;

                                    return value === null || value === undefined
                                        ? `${item.dataset.label}: -`
                                        : `${item.dataset.label}: ${value.toFixed(2)}${unit}`;
                                },
                            },
                        },
                    },
                },
            });
        },
    };
};
