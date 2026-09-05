@extends('layouts.app')

@section('title', $recipe->name)
@section('heading', 'Recipe')

@section('header-actions')
    <a href="{{ route('recipes.edit', $recipe) }}"
       class="grid size-tap place-items-center rounded-lg text-brand-100 transition hover:bg-white/10 hover:text-white"
       aria-label="Edit recipe details" title="Edit name, protein, tags and links">
        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>
        </svg>
    </a>
@endsection

@section('content')
    <a href="{{ route('recipes') }}"
       class="inline-flex min-h-tap items-center gap-1.5 text-sm font-medium text-ink-600 hover:text-brand-700">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        All recipes
    </a>

    @if ($errors->any())
        <p class="mt-2 rounded-xl bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $errors->first() }}</p>
    @endif

    <article class="mt-2 overflow-hidden rounded-2xl border border-ink-200 bg-white shadow-sm">
        @if ($recipe->hasImage())
            <div class="relative">
                <img src="{{ $recipe->imageUrl() }}" alt="" class="h-44 w-full object-cover sm:h-56">
                <form method="POST" action="{{ route('recipes.photo.destroy', $recipe) }}"
                      class="absolute right-2 top-2">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="grid size-9 place-items-center rounded-lg bg-black/45 text-white backdrop-blur
                                   transition hover:bg-black/65"
                            aria-label="Remove photo">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </form>
            </div>
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

            @if ($recipe->category_tags->isNotEmpty())
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

            @if ($recipe->source->needsAttribution() && $recipe->source_url)
                {{-- Someone else's work, credited wherever it is shown. --}}
                <p class="mt-3 text-sm text-ink-600">
                    From
                    <a href="{{ $recipe->source_url }}" target="_blank" rel="noopener noreferrer"
                       class="font-medium text-brand-600 underline underline-offset-2 hover:text-brand-700">
                        {{ $recipe->source_name ?: parse_url($recipe->source_url, PHP_URL_HOST) }}
                    </a>
                </p>
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

            {{-- ------------------------------------------------ ingredients --}}
            <div class="mt-5 flex items-baseline gap-2">
                <h2 class="text-sm font-semibold text-ink-900">Ingredients</h2>
                <span class="text-xs text-ink-400">for {{ $recipe->base_servings }} servings</span>
            </div>

            @if ($recipe->ingredients->isNotEmpty())
                <ul class="mt-2 divide-y divide-ink-100 border-y border-ink-100">
                    @foreach ($recipe->ingredients as $ingredient)
                        @php
                            $total = $ingredient->pivot->quantity_per_serving === null
                                ? null
                                : trim(rtrim(rtrim(number_format(
                                    $ingredient->pivot->quantity_per_serving * $recipe->base_servings, 2), '0'), '.')
                                    .' '.($ingredient->pivot->unit ?? $ingredient->default_unit));
                        @endphp
                        <li class="flex items-center gap-3 py-2 text-sm">
                            <span class="w-24 shrink-0 font-medium text-ink-900">{{ $total ?? '—' }}</span>
                            <span class="min-w-0 flex-1 text-ink-800">{{ $ingredient->name }}</span>
                            @if ($ingredient->hasStock())
                                <span class="shrink-0 rounded bg-ink-100 px-1.5 py-0.5 text-[11px] font-medium text-ink-600">
                                    in stock
                                </span>
                            @endif
                            <form method="POST"
                                  action="{{ route('recipes.ingredients.destroy', [$recipe, $ingredient]) }}"
                                  class="shrink-0">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="grid size-9 place-items-center rounded-lg text-ink-300
                                               transition hover:bg-ink-100 hover:text-red-600"
                                        aria-label="Remove {{ $ingredient->name }}">
                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="mt-2 rounded-xl bg-ink-100 px-4 py-3">
                    <p class="text-sm text-ink-600">No ingredients recorded yet.</p>
                    @if (($recipe->recipe_links ?? []) !== [])
                        <form method="POST" action="{{ route('recipes.import', $recipe) }}" class="mt-2">
                            @csrf
                            <button type="submit"
                                    class="min-h-tap rounded-xl border border-ink-300 bg-white px-4 text-sm
                                           font-medium text-ink-800 transition hover:border-brand-400">
                                Try fetching from the link
                            </button>
                        </form>
                    @endif
                </div>
            @endif

            {{-- Add one at a time. Amounts are what the whole recipe needs,
                 because that is how the page being copied from writes them. --}}
            <form method="POST" action="{{ route('recipes.ingredients.store', $recipe) }}"
                  class="mt-3 flex flex-wrap gap-2">
                @csrf
                <input type="text" name="quantity" inputmode="decimal" placeholder="2"
                       class="min-h-tap w-16 rounded-xl border border-ink-200 px-3 text-base outline-none
                              focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
                <input type="text" name="unit" placeholder="cups" maxlength="20"
                       class="min-h-tap w-24 rounded-xl border border-ink-200 px-3 text-base outline-none
                              focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
                <input type="text" name="name" required placeholder="Ingredient" maxlength="120"
                       class="min-h-tap min-w-0 flex-1 rounded-xl border border-ink-200 px-3 text-base outline-none
                              focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
                <button type="submit"
                        class="min-h-tap shrink-0 rounded-xl bg-brand-600 px-4 font-semibold text-white
                               transition hover:bg-brand-700">Add</button>
            </form>

            {{-- The bulk path matters: over half the archive has no usable link,
                 and typing 15 ingredients one at a time is nobody's evening. --}}
            <details class="mt-3">
                <summary class="cursor-pointer list-none text-sm font-medium text-brand-600 hover:text-brand-700">
                    Paste a whole list instead
                </summary>
                <form method="POST" action="{{ route('recipes.ingredients.bulk', $recipe) }}" class="mt-2">
                    @csrf
                    <textarea name="lines" rows="6" required
                              placeholder="1 lb ground beef&#10;2 cups beef broth&#10;1 onion, diced&#10;1/2 tsp salt"
                              class="w-full rounded-xl border border-ink-200 px-3.5 py-2.5 font-mono text-sm
                                     outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200"></textarea>
                    <p class="mt-1 text-xs text-ink-400">
                        One per line. Amounts, units and prep notes are worked out for you.
                    </p>
                    <button type="submit"
                            class="mt-2 min-h-tap rounded-xl bg-brand-600 px-5 font-semibold text-white
                                   transition hover:bg-brand-700">
                        Add all
                    </button>
                </form>
            </details>

            {{-- ------------------------------------------------ yield + photo --}}
            <div class="mt-5 grid gap-3 border-t border-ink-100 pt-4 sm:grid-cols-2">
                <form method="POST" action="{{ route('recipes.servings', $recipe) }}">
                    @csrf
                    <label for="base_servings" class="block text-sm font-medium text-ink-800">Recipe serves</label>
                    <div class="mt-1.5 flex gap-2">
                        <input id="base_servings" type="number" name="base_servings" min="1" max="60"
                               value="{{ $recipe->base_servings }}"
                               class="min-h-tap w-20 rounded-xl border border-ink-200 px-3 text-base outline-none
                                      focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
                        <button type="submit"
                                class="min-h-tap rounded-xl border border-ink-200 bg-white px-4 text-sm font-medium
                                       text-ink-800 transition hover:border-brand-400">Save</button>
                    </div>
                    <p class="mt-1 text-xs text-ink-400">Amounts stay the same; the per-serving split adjusts.</p>
                </form>

                <form method="POST" action="{{ route('recipes.photo.store', $recipe) }}"
                      enctype="multipart/form-data">
                    @csrf
                    <label for="photo" class="block text-sm font-medium text-ink-800">
                        {{ $recipe->hasImage() ? 'Replace photo' : 'Add a photo' }}
                    </label>
                    {{-- capture hints a phone straight to its camera, which is the
                         point: photograph the dish while it is on the table. --}}
                    <input id="photo" type="file" name="photo" accept="image/*" capture="environment" required
                           onchange="this.form.requestSubmit()"
                           class="mt-1.5 block w-full text-sm text-ink-600
                                  file:mr-3 file:min-h-tap file:rounded-xl file:border-0 file:bg-brand-600
                                  file:px-4 file:font-semibold file:text-white hover:file:bg-brand-700">
                    <p class="mt-1 text-xs text-ink-400">A photo you take is never replaced by a scraped image.</p>
                </form>
            </div>

            {{-- ---------------------------------------------------- rating --}}
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
                        {{-- Styled from :has(:checked) rather than from the saved
                             value, so the button lights up the instant it is
                             tapped instead of only after saving. --}}
                        <label class="min-h-tap cursor-pointer rounded-xl border border-ink-200 bg-white px-4 py-2.5
                                      text-sm font-medium text-ink-800 transition hover:border-brand-400
                                      has-checked:border-brand-600 has-checked:bg-brand-600 has-checked:text-white">
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
