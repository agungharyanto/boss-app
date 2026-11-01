<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Status Penutupan WO - {{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-gray-100 min-h-screen py-6 px-4">
        <div class="max-w-2xl mx-auto bg-white rounded-md shadow p-6">
            <h1 class="text-xl font-semibold text-gray-800 mb-1">Penutupan Work Order</h1>
            <p class="text-sm text-gray-500 mb-4">WO #{{ $workOrder->id }} — {{ $workOrder->customer?->name ?? '-' }}</p>

            @if ($completed)
                <div class="border border-green-200 bg-green-50 text-green-800 rounded-md p-4 text-sm">
                    Work Order sudah ditutup (Completed). Terima kasih — dokumentasi foto dan tanda tangan pelanggan telah tersimpan.
                </div>
            @else
                <div class="border border-amber-200 bg-amber-50 text-amber-800 rounded-md p-4 text-sm">
                    Work Order ini belum ditutup (status: {{ $workOrder->status->label() }}).
                </div>
            @endif
        </div>

        @livewireScripts
    </body>
</html>
