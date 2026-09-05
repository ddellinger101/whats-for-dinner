<!DOCTYPE html>
<html lang="en" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', "What's For Dinner") &middot; What's For Dinner</title>

    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <meta name="theme-color" content="#33883c">
    <meta name="apple-mobile-web-app-capable" content="yes">

    @vite(['resources/css/app.css'])
</head>
<body class="min-h-dvh bg-ink-50 text-ink-800 font-sans">

    <header class="sticky top-0 z-30 bg-linear-to-br from-brand-600 to-brand-700 text-white shadow-sm">
        <div class="mx-auto flex max-w-3xl items-center gap-3 px-4 py-3">
            <a href="{{ route('home') }}" class="flex shrink-0 items-center gap-2.5">
                <span class="grid size-9 place-items-center overflow-hidden rounded-lg bg-white shadow-sm">
                    <img src="{{ asset('logo-mark-96.png') }}" alt="" width="36" height="36" class="size-9">
                </span>
                <span class="text-base font-semibold tracking-tight">@yield('heading', "What's For Dinner")</span>
            </a>

            <div class="ml-auto flex items-center gap-1">
                @yield('header-actions')
                <a href="{{ route('settings') }}"
                   class="grid size-tap place-items-center rounded-lg text-brand-100 transition
                          hover:bg-white/10 hover:text-white"
                   aria-label="Settings" title="Settings">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="grid size-tap place-items-center rounded-lg text-brand-100 transition hover:bg-white/10 hover:text-white"
                            aria-label="Sign out" title="Sign out">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </header>

    @if (session('status'))
        <div class="mx-auto max-w-3xl px-4 pt-4">
            <p class="rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm font-medium text-brand-800">
                {{ session('status') }}
            </p>
        </div>
    @endif

    {{-- Bottom padding clears the fixed tab bar plus the iOS home indicator. --}}
    <main class="mx-auto max-w-3xl px-4 pt-4 pb-[calc(5.5rem+env(safe-area-inset-bottom))]">
        @yield('content')
    </main>

    <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-ink-200 bg-white/95 backdrop-blur
                pb-[env(safe-area-inset-bottom)]">
        <div class="mx-auto grid max-w-3xl grid-cols-5">
            @php
                $tabs = [
                    ['route' => 'home',     'label' => 'Home',    'icon' => 'M3 10.5 12 3l9 7.5M5 9.5V21h14V9.5'],
                    ['route' => 'plan',     'label' => 'Plan',    'icon' => 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z'],
                    ['route' => 'pantry',   'label' => 'Pantry',  'icon' => 'M5 3h14a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zM4 12h16M10 7.5h1M10 16h1'],
                    ['route' => 'grocery',  'label' => 'Grocery', 'icon' => 'M3 6h18l-2 13H5L3 6zM3 6 2 3M8 10v5M16 10v5'],
                    ['route' => 'recipes',  'label' => 'Recipes', 'icon' => 'M4 4h11a3 3 0 0 1 3 3v13H7a3 3 0 0 1-3-3V4zM8 8h7M8 12h7'],
                ];
            @endphp

            @foreach ($tabs as $tab)
                @php $active = request()->routeIs($tab['route']); @endphp
                <a href="{{ route($tab['route']) }}"
                   @if ($active) aria-current="page" @endif
                   class="flex min-h-tap flex-col items-center justify-center gap-1 py-2 text-xs font-medium transition
                          {{ $active ? 'text-brand-700' : 'text-ink-400 hover:text-ink-600' }}">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="{{ $tab['icon'] }}"/>
                    </svg>
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </div>
    </nav>

    @stack('scripts')
</body>
</html>
