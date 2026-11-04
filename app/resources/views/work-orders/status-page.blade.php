{{-- v0.13.4.5 — Halaman kartu status WO (terminal / sudah diambil / belum
     diambil / belum diaktivasi). Wrapper tipis di atas layout publik bersama
     + partial kartu status. Dirender oleh Claim/Activation/Close controller
     untuk state yang BUKAN form/progress. --}}
@extends('layouts.wo-public')

@section('title', $pageTitle ?? 'Status Work Order')

@section('content')
    <h1 class="text-xl font-semibold text-gray-800 mb-1">{{ $pageTitle ?? 'Status Work Order' }}</h1>
    <p class="text-sm text-gray-500 mb-4">{{ config('app.name') }}</p>

    @include('work-orders.partials.wo-status-card', [
        'icon' => $icon ?? null,
        'tone' => $tone ?? 'gray',
        'heading' => $heading,
        'detail' => $detail ?? null,
        'workOrder' => $workOrder,
        'actionUrl' => $actionUrl ?? null,
        'actionLabel' => $actionLabel ?? null,
    ])
@endsection
