@extends('layouts.app')

@section('title', $recipe->name)
@section('heading', 'Recipe')

@section('content')
    <a href="{{ route('recipes') }}"
       class="inline-flex min-h-tap items-center gap-1.5 text-sm font-medium text-ink-600 hover:text-brand-700">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        All recipes
    </a>

    <article class="mt-2 overflow-hidden rounded-2xl border border-ink-200 bg-white shadow-sm">
        @if ($recipe->hasImage())
            <img src="{{ $recipe->imageUrl() }}" alt="" class="h-44 w-full object-cover sm:h-56">
        @endif

        <div class="p-5">
            <h1 class="text-2xl font-semibold tracking-tight text-ink-900">{{ $recipe->name }}</h1>

            <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-ink-600">
                <span>{{ $recipe->protein_type->label() }}</span>
                <span>Serves {{ $recipe->base_servings }}</span>
                <span>Made {{ $recipe->times_made }}&times;</span>
                @if ($recipe->last_cooked_on)
                    <span>Last {{ $recipe->last_cooked_on->format('j M Y') }}</span>
                @endif
            </p>

            @if ($recipe->category_tags->isNotEmpty() || $recipe->is_keto)
                <div class="mt-3 flex flex-wrap gap-1.5">
                    @foreach ($recipe->category_tags as $tag)
                        <span class="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-medium text-brand-700">
                            {{ $tag->label() }}
                        </span>
                    @endforeach
                </div>
            @endif

            @if (! $recipe->isSelectable())
                <div class="mt-4 rounded-xl bg-ink-100 px-4 py-3">
                    <p class="text-sm font-medium text-ink-800">Hidden from suggestions</p>
                    <p class="mt-0.5 text-sm text-ink-600">
                        Marked thumbs-down, so it will not be suggested or selectable until you change that.
                    </p>
                    <form method="POST" action="{{ route('recipes.rate', $recipe) }}" class="mt-2">
                        @csrf
                        <input type="hidden" name="rating" value="{{ \App\Enums\Rating::Unrated->value }}">
                        <button type="submit"
                                class="min-h-tap rounded-xl border border-ink-300 bg-white px-4 text-sm font-medium
                                       text-ink-800 transition hover:border-brand-400">
                            Put it back in rotation
                        </button>
                    </form>
                </div>
            @endif

            @foreach ($recipe->recipe_links ?? [] as $link)
                <a href="{{ $link }}" target="_blank" rel="noopener noreferrer"
                   class="mt-3 flex min-h-tap items-center gap-1.5 truncate text-sm font-medium
                          text-brand-600 hover:text-brand-700">
                    <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3"/>
                    </svg>
                    <span class="truncate">{{ parse_url($link, PHP_URL_HOST) ?: $link }}</span>
                </a>
            @endforeach

            @if ($recipe->ingredients->isNotEmpty())
                <h2 class="mt-5 text-sm font-semibold text-ink-900">Ingredients</h2>
                <ul class="mt-2 divide-y divide-ink-100 border-y border-ink-100">
                    @foreach ($recipe->ingredients as $ingredient)
                        <li class="flex items-baseline gap-3 py-2.5 text-sm">
                            <span class="w-24 shrink-0 font-medium text-ink-900">
                                @if ($ingredient->pivot->quantity_per_serving !== null)
                                    {{ rtrim(rtrim(number_format($ingredient->pivot->quantity_per_serving * $recipe->base_servings, 2), '0'), '.') }}
                                    {{ $ingredient->pivot->unit ?? $ingredient->default_unit }}
                                @else
                                    &mdash;
                                @endif
                            </span>
                            <span class="min-w-0 flex-1 text-ink-800">{{ $ingredient->name }}</span>
                            @if ($ingredient->hasStock())
                                <span class="shrink-0 rounded bg-ink-100 px-1.5 py-0.5 text-[11px] font-medium text-ink-600">
                                    in stock
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-5 rounded-xl bg-ink-100 px-4 py-3 text-sm text-ink-600">
                    No ingredients recorded yet. They will be imported from the recipe link, or you can add them by hand.
                </p>
            @endif

            {{-- Spec 4.4: just_ok is the rating that wants a note. --}}
            <form method="POST" action="{{ route('recipes.rate', $recipe) }}" class="mt-5 border-t border-ink-100 pt-4">
                @csrf
                <h2 class="text-sm font-semibold text-ink-900">Rating</h2>
                <div class="mt-2 flex flex-wrap gap-2">
                    {{-- Pairs, not an enum-keyed map: PHP array keys can only be
                         int or string, never an enum instance. --}}
                    @foreach ([
                        [\App\Enums\Rating::ThumbsUp, 'Loved it'],
                        [\App\Enums\Rating::JustOk, 'Just OK'],
                        [\App\Enums\Rating::ThumbsDown, 'No thanks'],
                    ] as [$rating, $label])
                        <label class="min-h-tap cursor-pointer rounded-xl border px-4 py-2.5 text-sm font-medium transition
                                      {{ $recipe->rating === $rating
                                          ? 'border-brand-600 bg-brand-600 text-white'
                                          : 'border-ink-200 bg-white text-ink-800 hover:border-brand-400' }}">
                            <input type="radio" name="rating" value="{{ $rating->value }}" class="sr-only"
                                   @checked($recipe->rating === $rating)>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>

                <label for="notes" class="mt-4 block text-sm font-medium text-ink-800">
                    Notes &mdash; how to improve it next time
                </label>
                <textarea id="notes" name="notes" rows="3"
                          class="mt-1.5 w-full rounded-xl border border-ink-200 px-3.5 py-2.5 text-base outline-none
                                 focus:border-brand-500 focus:ring-2 focus:ring-brand-200">{{ $recipe->notes }}</textarea>

                <button type="submit"
                        class="mt-3 min-h-tap w-full rounded-xl bg-brand-600 px-4 font-semibold text-white
                               shadow-sm transition hover:bg-brand-700 sm:w-auto sm:px-6">
                    Save
                </button>
            </form>
        </div>
    </article>
@endsection
