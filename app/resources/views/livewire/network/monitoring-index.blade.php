<div class="p-6 max-w-6xl mx-auto space-y-6">
    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-800">{{ __('Monitoring') }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                {{ __('Status, resource usage, dan traffic router + OLT — data real dari LibreNMS.') }}
            </p>
        </div>

        {{-- v0.8.2-monitoring-fixes — guarded here (not just inside the
             component's own mount()), so a future monitoring.view-only
             user (none exist today — super_admin/noc both get .manage
             too) never even mounts a component that would 403 for them. --}}
        @can('monitoring.manage')
            <livewire:network.add-monitoring-device-form />
        @endcan
    </div>

    <livewire:network.device-monitoring-list />

    {{-- v0.8.4 Bagian D — sibling components, opened via DeviceMonitoringList's
         dispatched device-history-requested/device-edit-requested events. --}}
    <livewire:network.device-history-modal />
    <livewire:network.device-syslog-modal />
    @can('monitoring.manage')
        <livewire:network.device-edit-form />
    @endcan

    <livewire:network.device-traffic-graph />

    {{-- v0.8.4 Bagian C — same monitoring.view gate as the components
         above, checked again inside ContainerStatsList::mount() too. --}}
    <livewire:network.container-stats-list />
</div>
