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
<body class="flex min-h-full flex-col font-sans text-slate-900 antialiased">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex h-14 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
            <div class="flex min-w-0 items-center gap-6">
                <a href="{{ route('dashboard') }}" class="flex shrink-0 items-center gap-2 font-semibold tracking-tight">
                    <span class="flex size-7 items-center justify-center rounded-md bg-indigo-600 text-sm font-bold text-white">L</span>
                    <span class="hidden sm:inline">{{ config('app.name') }}</span>
                </a>

                <nav class="flex items-center gap-1 overflow-x-auto text-sm font-medium">
                    @foreach ([
                        ['dashboard', 'Dashboard', 'dashboard'],
                        ['campaigns.index', 'Campaigns', 'campaigns.*'],
                        ['businesses.index', 'Businesses', 'businesses.*'],
                        ['settings.sources', 'Data sources', 'settings.*'],
                    ] as [$route, $label, $pattern])
                        <a href="{{ route($route) }}" @class([
                            'rounded-md px-3 py-1.5 whitespace-nowrap',
                            'bg-slate-100 text-slate-900' => request()->routeIs($pattern),
                            'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! request()->routeIs($pattern),
                        ])>{{ $label }}</a>
                    @endforeach
                </nav>
            </div>

            <div class="flex shrink-0 items-center gap-3 text-sm">
                <span class="hidden text-slate-500 md:inline">{{ auth()->user()?->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-md px-2 py-1 text-slate-600 hover:bg-slate-50 hover:text-slate-900">Sign out</button>
                </form>
            </div>
        </div>
    </header>

    <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-8 sm:px-6 lg:px-8">
        @if (session('status'))
            <div class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
                {{ session('status') }}
            </div>
        @endif

        {{ $slot }}
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-4 text-xs text-slate-500 sm:px-6 lg:px-8">
            Business data from <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer" class="underline hover:text-slate-700">© OpenStreetMap contributors</a> (ODbL),
            <a href="https://docs.overturemaps.org/attribution/" target="_blank" rel="noopener noreferrer" class="underline hover:text-slate-700">Overture Maps Foundation</a>,
            <a href="https://www.alltheplaces.xyz/" target="_blank" rel="noopener noreferrer" class="underline hover:text-slate-700">AllThePlaces</a>,
            the Name Suggestion Index, Wikidata and <a href="https://www.geonames.org/" target="_blank" rel="noopener noreferrer" class="underline hover:text-slate-700">GeoNames</a> (CC BY).
        </div>
    </footer>
</body>
</html>
