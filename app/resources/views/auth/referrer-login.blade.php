<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Portal Referrer - {{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-gray-100 min-h-screen flex items-center justify-center">
        <div class="w-full max-w-sm p-6 bg-white rounded-md shadow">
            <div class="flex justify-end mb-2">
                <x-language-switcher />
            </div>

            <h1 class="text-xl font-semibold text-gray-800 mb-1">{{ __('Portal Referrer') }}</h1>
            <p class="text-sm text-gray-500 mb-4">{{ config('app.name') }}</p>

            @if ($errors->any())
                <div class="mb-4 text-sm text-red-600">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            @if (session('status'))
                <div class="mb-4 text-sm text-green-600">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('referrer.login.attempt') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Nomor HP') }}</label>
                    <input type="text" name="phone" value="{{ old('phone') }}" required autofocus
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Password') }}</label>
                    <input type="password" name="password" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                </div>

                <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                    {{ __('Masuk') }}
                </button>
            </form>

            <div class="mt-4 text-sm text-center">
                <a href="{{ route('referrer.password.request') }}" class="text-blue-600 hover:underline">{{ __('Lupa password?') }}</a>
            </div>
        </div>
    </body>
</html>
