<x-layouts::guest title="Sign in">
    <form method="POST" action="{{ route('login') }}" class="space-y-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        @csrf

        <h1 class="text-base font-semibold">Sign in</h1>

        <div>
            <label for="email" class="block text-sm font-medium text-slate-700">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none">
            @error('email')
                <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                   class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 focus:outline-none">
            @error('password')
                <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
            @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="remember" value="1" class="rounded border-slate-300 text-indigo-600">
            Remember me
        </label>

        <button type="submit" class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:ring-2 focus:ring-indigo-500/40 focus:outline-none">
            Sign in
        </button>
    </form>

    <p class="mt-6 text-center text-xs text-slate-500">
        No account yet? Create one with <code class="rounded bg-slate-100 px-1 py-0.5">php artisan app:create-user</code>
    </p>
</x-layouts::guest>
