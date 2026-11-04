@extends('layouts.wo-public')

@section('title', 'Status Penutupan WO')

@section('content')
    {{-- v0.13.4.5 — fallback status penutupan untuk WO yang BELUM terminal
         (mis. masih InProgress, dibuka via link status sebelum ditutup).
         State terminal (SELESAI/DIBATALKAN) dirender sebagai kartu status
         seragam (status-page), bukan halaman ini. --}}
    <h1 class="text-xl font-semibold text-gray-800 mb-1">Penutupan Work Order</h1>
    <p class="text-sm text-gray-500 mb-4">WO #{{ $workOrder->id }} — {{ $workOrder->customer?->name ?? '-' }}</p>

    <div class="border border-amber-200 bg-amber-50 text-amber-800 rounded-md p-4 text-sm">
        Work Order ini belum ditutup (status: {{ $workOrder->status->label() }}).
    </div>
@endsection
