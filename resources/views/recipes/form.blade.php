@extends('layouts.app')

@php $isNew = ! $recipe->exists; @endphp

@section('title', $isNew ? 'New Recipe' : 'Edit ' . $recipe->name)
@section('heading', $isNew ? 'New Recipe' : 'Edit Recipe')

@section('content')
    <a href="{{ $isNew ? route('recipes') : route('recipes.show', $recipe) }}"
       class="inline-flex min-h-tap items-center gap-1.5 text-sm font-medium text-ink-600 hover:text-brand-700">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        {{ $isNew ? 'All recipes' : 'Back to recipe' }}
    </a>

    @if ($errors->any())
        <p class="mt-2 rounded-xl bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $errors->first() }}</p>
    @endif

    @if ($isNew)
        {{-- The usual way a recipe arrives is as a link, and typing its title,
             ingredients, method and tags back in by hand is exactly the chore
             the scraper already exists to avoid. Offered first, and the form
             below stays for anything it cannot read. --}}
            <form method="POST" action="{{ route('recipes.from-link') }}"
              class="mt-2 rounded-2xl border border-leaf-500/50 bg-leaf-500/5 p-5 shadow-sm">
            @csrf
            <h2 class="text-base font-semibold text-ink-900">Have a link?</h2>
            <p class="mt-1 text-sm text-ink-600">
                Paste it and the title, ingredients, method, photo and tags are read from the page.
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                <input type="url" name="url" required inputmode="url"
                       value="{{ old('url', request('url')) }}"
                       placeholder="https://example.com/beef-tacos"
                       class="min-h-tap min-w-0 flex-1 rounded-xl border border-ink-200 bg-white px-4 text-base
                              outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
                <button type="submit"
                        class="min-h-tap shrink-0 rounded-xl bg-brand-600 px-5 font-semibold text-white shadow-sm
                               transition hover:bg-brand-700 active:scale-95">
                    Fetch it
                </button>
            </div>
            <p class="mt-2 text-xs text-ink-400">
                Takes a few seconds. You can correct anything afterwards.
            </p>
        </form>

        <p class="mt-4 px-1 text-xs font-semibold uppercase tracking-wide text-ink-400">
            Or enter it yourself
        </p>
    @endif

    <form method="POST" action="{{ $isNew ? route('recipes.store') : route('recipes.update', $recipe) }}"
          class="mt-2 space-y-5 rounded-2xl border border-ink-200 bg-white p-5 shadow-sm">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <div>
            <label for="name" class="block text-sm font-medium text-ink-800">Name</label>
            <input id="name" name="name" type="text" required maxlength="160" autofocus
                   value="{{ old('name', $recipe->name) }}" placeholder="Beef Tacos"
                   class="mt-1.5 block min-h-tap w-full rounded-xl border border-ink-200 px-3.5 text-base
                          outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
        </div>

        <fieldset>
            <legend class="text-sm font-medium text-ink-800">Protein</legend>
            <div class="mt-1.5 flex flex-wrap gap-2">
                @foreach ($proteins as $option)
                    {{-- Styled from :has(:checked) so the selection lights up the
                         moment it is tapped, rather than only after saving. --}}
                    <label class="min-h-tap cursor-pointer rounded-xl border border-ink-200 bg-white px-4 py-2.5
                                  text-sm font-medium text-ink-800 transition hover:border-brand-400
                                  has-checked:border-brand-600 has-checked:bg-brand-600 has-checked:text-white">
                        <input type="radio" name="protein_type" value="{{ $option->value }}" class="sr-only"
                               @checked(old('protein_type', $recipe->protein_type?->value ?? 'none') === $option->value)>
                        {{ $option->label() }}
                    </label>
                @endforeach
            </div>
        </fieldset>

        <fieldset>
            <legend class="text-sm font-medium text-ink-800">Meal</legend>
            <div class="mt-1.5 flex flex-wrap gap-2">
                @foreach ($mealTypes as $option)
                    <label class="min-h-tap cursor-pointer rounded-xl border border-ink-200 bg-white px-4 py-2.5
                                  text-sm font-medium text-ink-800 transition hover:border-brand-400
                                  has-checked:border-brand-600 has-checked:bg-brand-600 has-checked:text-white">
                        <input type="radio" name="meal_type" value="{{ $option->value }}" class="sr-only"
                               @checked(old('meal_type', $recipe->meal_type?->value ?? 'dinner') === $option->value)>
                        {{ $option->label() }}
                    </label>
                @endforeach
            </div>
        </fieldset>

        @php
            $selectedTags = collect(old('category_tags', $recipe->category_tags?->map->value->all() ?? []))->all();
        @endphp

        <fieldset>
            <legend class="text-sm font-medium text-ink-800">Cuisine</legend>
            <p class="text-xs text-ink-400">Pick as many as fit.</p>
            <div class="mt-1.5 flex flex-wrap gap-2">
                @foreach ($cuisines as $tag)
                    <label class="min-h-tap cursor-pointer rounded-xl border border-ink-200 bg-white px-4 py-2.5
                                  text-sm font-medium text-ink-800 transition hover:border-brand-400
                                  has-checked:border-leaf-600 has-checked:bg-leaf-600 has-checked:text-white">
                        <input type="checkbox" name="category_tags[]" value="{{ $tag->value }}" class="sr-only"
                               @checked(in_array($tag->value, $selectedTags, true))>
                        {{ $tag->label() }}
                    </label>
                @endforeach
            </div>
        </fieldset>

        <fieldset>
            <legend class="text-sm font-medium text-ink-800">Category</legend>
            <div class="mt-1.5 flex flex-wrap gap-2">
                @foreach ($styles as $tag)
                    <label class="min-h-tap cursor-pointer rounded-xl border border-ink-200 bg-white px-4 py-2.5
                                  text-sm font-medium text-ink-800 transition hover:border-brand-400
                                  has-checked:border-brand-600 has-checked:bg-brand-600 has-checked:text-white">
                        <input type="checkbox" name="category_tags[]" value="{{ $tag->value }}" class="sr-only"
                               @checked(in_array($tag->value, $selectedTags, true))>
                        {{ $tag->label() }}
                    </label>
                @endforeach
            </div>
            <p class="mt-1 text-xs text-ink-400">Tagging Keto also switches on the keto diet filter for this recipe.</p>
        </fieldset>

        <div>
            <label for="base_servings" class="block text-sm font-medium text-ink-800">Recipe serves</label>
            <input id="base_servings" name="base_servings" type="number" min="1" max="60" required
                   value="{{ old('base_servings', $recipe->base_servings ?: 4) }}"
                   class="mt-1.5 block min-h-tap w-24 rounded-xl border border-ink-200 px-3.5 text-base
                          outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
        </div>

        <div>
            <label for="links" class="block text-sm font-medium text-ink-800">Recipe links</label>
            <textarea id="links" name="links" rows="3" placeholder="https://example.com/beef-tacos"
                      class="mt-1.5 w-full rounded-xl border border-ink-200 px-3.5 py-2.5 text-base outline-none
                             focus:border-brand-500 focus:ring-2 focus:ring-brand-200">{{ old('links', request('url') ?: implode("\n", $recipe->recipe_links ?? [])) }}</textarea>
            <p class="mt-1 text-xs text-ink-400">
                One per line. Ingredients are fetched from the first one that works.
            </p>
        </div>

        <div>
            <label for="instructions" class="block text-sm font-medium text-ink-800">Method</label>
            <textarea id="instructions" name="instructions" rows="6"
                      placeholder="Brown the beef in a large pan.&#10;Add the onion and cook until soft."
                      class="mt-1.5 w-full rounded-xl border border-ink-200 px-3.5 py-2.5 text-base outline-none
                             focus:border-brand-500 focus:ring-2 focus:ring-brand-200">{{ old('instructions', implode("\n", $recipe->instructions ?? [])) }}</textarea>
            <p class="mt-1 text-xs text-ink-400">One step per line. Numbering is added for you.</p>
        </div>

        <div>
            <label for="notes" class="block text-sm font-medium text-ink-800">Notes</label>
            <textarea id="notes" name="notes" rows="3"
                      class="mt-1.5 w-full rounded-xl border border-ink-200 px-3.5 py-2.5 text-base outline-none
                             focus:border-brand-500 focus:ring-2 focus:ring-brand-200">{{ old('notes', $recipe->notes) }}</textarea>
        </div>

        <button type="submit"
                class="min-h-tap w-full rounded-xl bg-brand-600 px-5 font-semibold text-white shadow-sm
                       transition hover:bg-brand-700 sm:w-auto sm:px-8">
            {{ $isNew ? 'Create recipe' : 'Save changes' }}
        </button>
    </form>

    @unless ($isNew)
        {{-- Behind a disclosure so deleting is a decision rather than a stray
             tap next to Save. --}}
        <details class="mt-4 rounded-2xl border border-ink-200 bg-white p-5 shadow-sm">
            <summary class="cursor-pointer list-none text-sm font-medium text-ink-600 hover:text-red-600">
                Delete this recipe
            </summary>
            <p class="mt-2 text-sm text-ink-600">
                Removes {{ $recipe->name }} for good, along with its ingredients, its photo,
                and any place it&rsquo;s planned this week. Groceries it added are taken back off
                the list unless they&rsquo;ve already been ticked off.
            </p>
            <form method="POST" action="{{ route('recipes.destroy', $recipe) }}" class="mt-3">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="min-h-tap w-full rounded-xl border border-red-300 bg-white px-5 font-semibold
                               text-red-600 transition hover:bg-red-50 sm:w-auto sm:px-8">
                    Delete {{ $recipe->name }}
                </button>
            </form>
        </details>
    @endunless
@endsection
