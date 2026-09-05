<!DOCTYPE html>
<html lang="en" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in &middot; What's For Dinner</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <meta name="theme-color" content="#33883c">
    @vite(['resources/css/app.css'])
</head>
<body class="grid min-h-dvh place-items-center bg-linear-to-br from-brand-600 to-brand-800 px-5 py-10 font-sans">
    <div class="w-full max-w-sm">
        <div class="mb-6 flex flex-col items-center text-center">
            <span class="grid size-20 place-items-center overflow-hidden rounded-2xl bg-white shadow-lg">
                <img src="{{ asset('apple-touch-icon.png') }}" alt="" width="80" height="80" class="size-20">
            </span>
            <h1 class="mt-4 text-2xl font-semibold tracking-tight text-white">What&rsquo;s For Dinner</h1>
            <p class="mt-1 text-sm text-brand-100">Sign in to plan the week.</p>
        </div>

        <form method="POST" action="{{ route('login.store') }}"
              class="space-y-4 rounded-2xl bg-white p-6 shadow-xl">
            @csrf

            @if ($errors->any())
                <p class="rounded-lg bg-red-50 px-3 py-2 text-sm font-medium text-red-700">
                    {{ $errors->first() }}
                </p>
            @endif

            <div>
                <label for="email" class="block text-sm font-medium text-ink-800">Email</label>
                <input id="email" name="email" type="email" required autofocus
                       autocomplete="username" inputmode="email" value="{{ old('email') }}"
                       class="mt-1.5 block min-h-tap w-full rounded-xl border border-ink-200 px-3.5 text-base
                              text-ink-900 shadow-sm outline-none transition
                              focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-ink-800">Password</label>
                <input id="password" name="password" type="password" required
                       autocomplete="current-password"
                       class="mt-1.5 block min-h-tap w-full rounded-xl border border-ink-200 px-3.5 text-base
                              text-ink-900 shadow-sm outline-none transition
                              focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
            </div>

            <button type="submit"
                    class="min-h-tap w-full rounded-xl bg-brand-600 px-4 text-base font-semibold text-white
                           shadow-sm transition hover:bg-brand-700 active:bg-brand-800">
                Sign in
            </button>
        </form>

        <p class="mt-6 text-center text-xs text-brand-100/80">
            <a href="{{ route('legal.privacy') }}" class="underline underline-offset-2 hover:text-white">Privacy</a>
            <span class="px-1.5">&middot;</span>
            <a href="{{ route('legal.terms') }}" class="underline underline-offset-2 hover:text-white">Terms</a>
        </p>
    </div>
</body>
</html>
