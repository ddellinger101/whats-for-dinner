<!DOCTYPE html>
<html lang="en" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') &middot; What's For Dinner</title>

    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <meta name="theme-color" content="#33883c">

    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-ink-50 text-ink-800 font-sans">
    <header class="bg-linear-to-br from-brand-500 to-brand-700 text-white">
        <div class="mx-auto max-w-2xl px-5 py-8 sm:py-12">
            {{-- The mark is artwork on white, so it sits on a light tile rather
                 than directly on the gradient, which would show as a white box. --}}
            <a href="{{ url('/') }}" class="inline-flex items-center gap-3 group">
                <span class="grid size-11 shrink-0 place-items-center overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-black/5">
                    <img src="{{ asset('logo-mark.png') }}" alt="" width="44" height="44" class="size-11">
                </span>
                <span class="text-sm font-medium text-brand-100 group-hover:text-white">
                    What&rsquo;s For Dinner
                </span>
            </a>
            <h1 class="mt-4 text-3xl font-semibold tracking-tight sm:text-4xl">@yield('title')</h1>
            <p class="mt-2 text-sm text-brand-100">Last updated {{ $updated }}</p>
        </div>
    </header>

    <main class="mx-auto max-w-2xl px-5 py-8 sm:py-12">
        <div class="space-y-6 text-base/7">
            @yield('content')
        </div>
    </main>

    <footer class="mx-auto max-w-2xl px-5 pb-12">
        <div class="border-t border-ink-200 pt-6 text-sm text-ink-600">
            <nav class="flex flex-wrap gap-x-5 gap-y-2">
                <a class="min-h-tap py-2 hover:text-brand-600" href="{{ route('legal.privacy') }}">Privacy Policy</a>
                <a class="min-h-tap py-2 hover:text-brand-600" href="{{ route('legal.terms') }}">Terms of Service</a>
            </nav>
        </div>
    </footer>
</body>
</html>
