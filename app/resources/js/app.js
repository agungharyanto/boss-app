import './bootstrap';
import { Chart } from 'chart.js/auto';

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
