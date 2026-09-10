@extends('layouts.app')

@section('title', 'Pantry')
@section('heading', 'Pantry')

@section('content')
    <form method="POST" action="{{ route('pantry.store') }}" class="flex flex-wrap gap-2">
        @csrf
        <input type="text" name="quantity" inputmode="decimal" placeholder="2"
               class="min-h-tap w-16 rounded-xl border border-ink-200 bg-white px-3 text-base outline-none
                      focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
        <input type="text" name="unit" placeholder="lb" maxlength="20"
               class="min-h-tap w-20 rounded-xl border border-ink-200 bg-white px-3 text-base outline-none
                      focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
        <input type="text" name="name" required maxlength="120" placeholder="What have you got?"
               class="min-h-tap min-w-0 flex-1 rounded-xl border border-ink-200 bg-white px-3 text-base outline-none
                      focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
        <button type="submit"
                class="min-h-tap shrink-0 rounded-xl bg-brand-600 px-5 font-semibold text-white shadow-sm
                       transition hover:bg-brand-700 active:scale-95">Add</button>
    </form>

    <p class="mt-2 px-1 text-xs text-ink-400">
        Fills in as you tick things off the grocery list, and empties as you mark meals made.
        Leave the amount blank if you just know you have some.
    </p>

    @if ($errors->any())
        <p class="mt-3 rounded-xl bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $errors->first() }}</p>
    @endif

    {{-- Leads the screen: this is the whole reason to look here before
         planning, and it is what the suggestion ranker is boosting on. --}}
    @if ($atRisk->isNotEmpty())
        <section class="mt-4">
            <h2 class="px-1 text-xs font-semibold uppercase tracking-wide text-leaf-600">
                Use these up &middot; {{ $atRisk->count() }}
            </h2>
            {{-- See the note in the grocery list: overflow-hidden here would
                 clip each row's edit menu. --}}
            <ul class="mt-1.5 divide-y divide-leaf-500/20 rounded-2xl border border-leaf-500/50
                       bg-leaf-500/5 shadow-sm">
                @foreach ($atRisk as $flag)
                    @include('pantry.row', ['flag' => $flag, 'today' => $today, 'urgent' => true])
                @endforeach
            </ul>
            <p class="mt-1.5 px-1 text-xs text-ink-400">
                Recipes using these are pushed to the top of your dinner suggestions.
            </p>
        </section>
    @endif

    @if ($total === 0)
        <p class="mt-4 rounded-xl border border-dashed border-ink-200 px-4 py-8 text-center text-sm text-ink-600">
            Nothing recorded yet. Tick items off the grocery list and they&rsquo;ll appear here.
        </p>
    @endif

    @foreach ($byCategory as $categoryValue => $flags)
        <section class="mt-4">
            <h2 class="px-1 text-xs font-semibold uppercase tracking-wide text-ink-400">
                {{ $categories[$categoryValue]->label() }}
                <span class="font-normal normal-case tracking-normal">&middot; {{ $flags->count() }}</span>
            </h2>
            <ul class="mt-1.5 divide-y divide-ink-100 rounded-2xl border border-ink-200 bg-white shadow-sm">
                @foreach ($flags as $flag)
                    @include('pantry.row', ['flag' => $flag, 'today' => $today, 'urgent' => false])
                @endforeach
            </ul>
        </section>
    @endforeach

    {{-- Collapsed, and last. Forty jars that never change would otherwise
         bury the dozen things that do. --}}
    @if ($staples->isNotEmpty())
        <details class="mt-6">
            <summary class="cursor-pointer list-none px-1 text-xs font-semibold uppercase tracking-wide text-ink-400">
                Spice rack &middot; {{ $staples->count() }}
                <span class="font-normal normal-case tracking-normal text-ink-400">
                    &mdash; always in, never listed
                </span>
            </summary>
            <ul class="mt-1.5 grid grid-cols-2 gap-x-3 gap-y-1 rounded-2xl border border-ink-200 bg-white/60
                       px-4 py-3 sm:grid-cols-3">
                @foreach ($staples as $flag)
                    <li class="flex items-baseline gap-1.5 py-0.5 text-sm text-ink-700">
                        <span>{{ $flag->ingredient->name }}</span>
                        @if ($flag->ingredient->betterFresh())
                            <span class="text-xs text-leaf-600" title="Better with fresh">&#10022;</span>
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="mt-1.5 px-1 text-xs text-ink-400">
                These never expire and no recipe adds them to the grocery list. A recipe asking
                for <em>fresh</em> herbs still does &mdash; &#10022; marks the ones worth buying fresh
                when a recipe does not say.
            </p>
        </details>
    @endif

    {{-- Open when something went in the last day or so. Cooking empties things
         wholesale, and the evening it happened is exactly when you know there
         is still half a bunch of coriander left — not later, and not from a
         fold-out at the bottom of the screen nobody opens. --}}
    @if ($recentlyUsed->isNotEmpty())
        <details class="mt-6" @if ($recentlyUsedIsFresh) open @endif>
            <summary class="cursor-pointer list-none px-1 text-xs font-semibold uppercase tracking-wide text-ink-400">
                Recently used up &middot; {{ $recentlyUsed->count() }}
            </summary>
            <p class="mt-1 px-1 text-xs text-ink-400">
                Cooking takes the whole amount out. If some is left, say how much and it goes back.
            </p>
            <ul class="mt-1.5 divide-y divide-ink-100 rounded-2xl border border-ink-200 bg-white/60">
                @foreach ($recentlyUsed as $flag)
                    <li class="px-3 py-2">
                        <form method="POST" action="{{ route('pantry.restock', $flag) }}"
                              class="flex items-center gap-2">
                            @csrf
                            <span class="min-w-0 flex-1 truncate text-sm text-ink-500">
                                {{ $flag->ingredient->name }}
                            </span>
                            {{-- Blank puts it back as "some, amount unknown",
                                 which is the honest answer most of the time and
                                 keeps this a one-tap action. --}}
                            <input type="text" name="quantity" inputmode="decimal" placeholder="amount"
                                   class="min-h-9 w-16 shrink-0 rounded-lg border border-ink-200 px-2 text-sm
                                          outline-none focus:border-brand-500">
                            <input type="text" name="unit" value="{{ $flag->unit }}" placeholder="unit" maxlength="20"
                                   class="min-h-9 w-16 shrink-0 rounded-lg border border-ink-200 px-2 text-sm
                                          outline-none focus:border-brand-500">
                            <button type="submit"
                                    class="min-h-9 shrink-0 rounded-lg px-3 text-xs font-medium text-brand-600
                                           transition hover:bg-brand-50">
                                Put back
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
@endsection
