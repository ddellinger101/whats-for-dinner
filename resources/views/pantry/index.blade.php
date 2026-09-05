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
            <ul class="mt-1.5 divide-y divide-leaf-500/20 overflow-hidden rounded-2xl border border-leaf-500/50
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
            <ul class="mt-1.5 divide-y divide-ink-100 overflow-hidden rounded-2xl border border-ink-200
                       bg-white shadow-sm">
                @foreach ($flags as $flag)
                    @include('pantry.row', ['flag' => $flag, 'today' => $today, 'urgent' => false])
                @endforeach
            </ul>
        </section>
    @endforeach

    @if ($recentlyUsed->isNotEmpty())
        <details class="mt-6">
            <summary class="cursor-pointer list-none px-1 text-xs font-semibold uppercase tracking-wide text-ink-400">
                Recently used up &middot; {{ $recentlyUsed->count() }}
            </summary>
            <ul class="mt-1.5 divide-y divide-ink-100 overflow-hidden rounded-2xl border border-ink-200 bg-white/60">
                @foreach ($recentlyUsed as $flag)
                    <li class="flex items-center gap-3 px-3 py-2">
                        <span class="min-w-0 flex-1 truncate text-sm text-ink-400">{{ $flag->ingredient->name }}</span>
                        <form method="POST" action="{{ route('pantry.restock', $flag) }}" class="shrink-0">
                            @csrf
                            <button type="submit"
                                    class="min-h-9 rounded-lg px-3 text-xs font-medium text-brand-600
                                           transition hover:bg-brand-50">
                                Got some again
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
@endsection
