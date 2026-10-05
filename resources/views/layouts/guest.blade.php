@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full items-center justify-center px-4 py-12 font-sans text-slate-900 antialiased">
    <div class="w-full max-w-sm">
        <div class="mb-8 flex items-center justify-center gap-2 text-lg font-semibold tracking-tight">
            <span class="flex size-8 items-center justify-center rounded-md bg-indigo-600 font-bold text-white">L</span>
            {{ config('app.name') }}
        </div>

        {{ $slot }}
    </div>
</body>
</html>
