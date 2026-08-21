import './bootstrap';
import { Chart } from 'chart.js/auto';

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
            const inData = series.map((p) => (p.in_bytes_per_second !== null ? p.in_bytes_per_second * 8 : null));
            const outData = series.map((p) => (p.out_bytes_per_second !== null ? p.out_bytes_per_second * 8 : null));

            return new Chart(this.$refs.canvas, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        { label: 'In (bps)', data: inData, borderColor: '#2563eb', backgroundColor: '#2563eb', tension: 0.3, pointRadius: 0 },
                        { label: 'Out (bps)', data: outData, borderColor: '#16a34a', backgroundColor: '#16a34a', tension: 0.3, pointRadius: 0 },
                    ],
                },
                options: {
                    responsive: true,
                    animation: false,
                    scales: { y: { beginAtZero: true } },
                },
            });
        },
    };
};
