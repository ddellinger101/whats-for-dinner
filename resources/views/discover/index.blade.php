@extends('layouts.app')

@section('title', 'Try Something New')
@section('heading', 'Try Something New')

@section('content')
    @php
        $base = array_filter([
            'q' => $search ?: null,
            'protein' => $protein,
            'tag' => $tag,
            'keto' => $keto ? 1 : null,
            'return_to' => $returnTo,
        ]);
    @endphp

    <a href="{{ $returnTo ?: route('recipes') }}"
       class="inline-flex min-h-tap items-center gap-1.5 text-sm font-medium text-ink-600 hover:text-brand-700">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        {{ $returnTo ? 'Back to the plan' : 'My recipes' }}
    </a>

    @if (! $configured)
        <p class="mt-3 rounded-xl bg-ink-100 px-4 py-3 text-sm text-ink-600">
            Recipe search isn&rsquo;t configured on this server yet.
        </p>
    @endif

    <form method="GET" action="{{ route('discover') }}" class="mt-2">
        @if ($returnTo) <input type="hidden" name="return_to" value="{{ $returnTo }}"> @endif
        @if ($keto) <input type="hidden" name="keto" value="1"> @endif
        @if ($protein) <input type="hidden" name="protein" value="{{ $protein }}"> @endif
        @if ($tag) <input type="hidden" name="tag" value="{{ $tag }}"> @endif
        <input type="search" name="q" value="{{ $search }}" autofocus
               placeholder="Something you fancy&hellip;"
               class="min-h-tap w-full rounded-xl border border-ink-200 bg-white px-4 text-base outline-none
                      focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
    </form>

    {{-- Filters are folded into the search phrase: a search engine has no
         structured cuisine or diet parameters the way a recipe database does. --}}
    <div class="-mx-4 mt-2 overflow-x-auto px-4 pb-1">
        <div class="flex w-max gap-2">
            <a href="{{ route('discover', collect($base)->except(['protein', 'tag'])->all()) }}"
               class="min-h-9 whitespace-nowrap rounded-full border px-3.5 py-1.5 text-sm font-medium transition
                      {{ ! $protein && ! $tag ? 'border-brand-600 bg-brand-600 text-white' : 'border-ink-200 bg-white text-ink-600' }}">
                Anything
            </a>
            @foreach ($proteins as $option)
                <a href="{{ route('discover', collect($base)->except('protein')->merge(['protein' => $option->value])->all()) }}"
                   class="min-h-9 whitespace-nowrap rounded-full border px-3.5 py-1.5 text-sm font-medium transition
                          {{ $protein === $option->value ? 'border-brand-600 bg-brand-600 text-white' : 'border-ink-200 bg-white text-ink-600' }}">
                    {{ $option->label() }}
                </a>
            @endforeach
        </div>
    </div>

    <div class="-mx-4 mt-2 overflow-x-auto px-4 pb-1">
        <div class="flex w-max gap-2">
            <a href="{{ route('discover', collect($base)->except('keto')->merge($keto ? [] : ['keto' => 1])->all()) }}"
               class="min-h-9 whitespace-nowrap rounded-full border px-3.5 py-1.5 text-sm font-medium transition
                      {{ $keto ? 'border-leaf-600 bg-leaf-600 text-white' : 'border-ink-200 bg-white text-ink-600' }}">
                Keto
            </a>
            @foreach ($cuisines as $option)
                <a href="{{ route('discover', collect($base)->except('tag')->merge(['tag' => $option->value])->all()) }}"
                   class="min-h-9 whitespace-nowrap rounded-full border px-3.5 py-1.5 text-sm font-medium transition
                          {{ $tag === $option->value ? 'border-leaf-600 bg-leaf-600 text-white' : 'border-ink-200 bg-white text-ink-600' }}">
                    {{ $option->label() }}
                </a>
            @endforeach
            @foreach ($styles as $option)
                <a href="{{ route('discover', collect($base)->except('tag')->merge(['tag' => $option->value])->all()) }}"
                   class="min-h-9 whitespace-nowrap rounded-full border px-3.5 py-1.5 text-sm font-medium transition
                          {{ $tag === $option->value ? 'border-brand-600 bg-brand-600 text-white' : 'border-ink-200 bg-white text-ink-600' }}">
                    {{ $option->label() }}
                </a>
            @endforeach
        </div>
    </div>

    @if ($error)
        <p class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $error }}</p>
    @endif

    @if ($query->isEmpty())
        <p class="mt-4 rounded-xl border border-dashed border-ink-200 px-4 py-8 text-center text-sm text-ink-600">
            Search for something, or pick a cuisine, and ideas from around the web will appear here.
        </p>
    @elseif ($results->isEmpty() && ! $error)
        <p class="mt-4 rounded-xl border border-dashed border-ink-200 px-4 py-8 text-center text-sm text-ink-600">
            Nothing came back for that. Try fewer filters, or different words.
        </p>
    @endif

    @if ($results->isNotEmpty())
        <p class="mt-4 px-1 text-xs text-ink-400">
            {{ $results->count() }} ideas from {{ $providerName }}
        </p>

        <ul class="mt-2 space-y-2">
            @foreach ($results as $result)
                @php $already = $existing->get($result->externalId()); @endphp
                <li class="overflow-hidden rounded-2xl border border-ink-200 bg-white shadow-sm">
                    <div class="flex gap-3 p-3">
                        @if ($result->thumbnail)
                            <img src="{{ $result->thumbnail }}" alt="" loading="lazy"
                                 class="size-16 shrink-0 rounded-xl object-cover">
                        @else
                            <span class="grid size-16 shrink-0 place-items-center rounded-xl bg-brand-50
                                         text-xs font-semibold text-brand-700">?</span>
                        @endif

                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-ink-900">{{ $result->cleanTitle() }}</p>
                            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-ink-400">
                                {{-- Attribution: these are someone else's recipes. --}}
                                <span>{{ $result->sourceName() }}</span>
                                @if ($result->rating)
                                    <span class="text-brand-600">&#9733; {{ number_format($result->rating, 1) }}</span>
                                @endif
                                @if ($result->totalTime)
                                    <span>{{ $result->totalTime }}</span>
                                @endif
                            </p>
                            @if ($result->ingredients !== [])
                                <p class="mt-1 truncate text-xs text-ink-400">
                                    {{ implode(', ', array_slice($result->ingredients, 0, 6)) }}
                                </p>
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center gap-2 border-t border-ink-100 bg-ink-50/50 px-3 py-2">
                        <a href="{{ $result->url }}" target="_blank" rel="noopener noreferrer"
                           class="min-h-9 rounded-lg px-2 text-xs font-medium text-ink-600 hover:text-brand-700">
                            View recipe
                        </a>

                        @if ($already)
                            <a href="{{ route('recipes.show', $already) }}"
                               class="ml-auto min-h-9 rounded-lg px-3 text-xs font-semibold text-brand-700">
                                Already in your recipes
                            </a>
                        @else
                            <form method="POST" action="{{ route('discover.store') }}" class="ml-auto">
                                @csrf
                                <input type="hidden" name="title" value="{{ $result->cleanTitle() }}">
                                <input type="hidden" name="url" value="{{ $result->url }}">
                                <input type="hidden" name="source" value="{{ $result->sourceName() }}">
                                @if ($result->thumbnail)
                                    <input type="hidden" name="thumbnail" value="{{ $result->thumbnail }}">
                                @endif
                                @if ($returnTo)
                                    <input type="hidden" name="return_to" value="{{ $returnTo }}">
                                @endif
                                <button type="submit"
                                        class="min-h-9 rounded-lg bg-brand-600 px-3.5 text-xs font-semibold text-white
                                               transition hover:bg-brand-700 active:scale-95">
                                    + Add to my recipes
                                </button>
                            </form>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <p class="mt-4 px-1 text-xs text-ink-400">
            Adding a recipe fetches its full ingredients from the original page, so it works with
            your meal plan, grocery list and use-up suggestions like any other.
        </p>
    @endif
@endsection
