<div class="p-6 max-w-6xl mx-auto space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Monitoring') }}</h1>
        <p class="text-sm text-gray-500 mt-1">
            {{ __('Status, resource usage, dan traffic router + OLT — data real dari LibreNMS.') }}
        </p>
    </div>

    <livewire:network.device-monitoring-list />

    <livewire:network.device-traffic-graph />
</div>
