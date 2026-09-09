@extends('layouts.app')

@section('title', "What's For ".$slot->label())
@section('heading', "What's For ".$slot->label())

@section('content')
    {{-- Any day, not only today. Forgetting to mark a dinner made is something
         you notice the next morning, and until this there was no way back to
         it — the ingredients had to be taken out of the pantry by hand. --}}
    <div class="flex items-center gap-2">
        <a href="{{ route('tonight', ['date' => $previousDay->toDateString(), 'slot' => $slot->value]) }}"
           class="grid size-tap shrink-0 place-items-center rounded-xl border border-ink-200 bg-white text-ink-600
                  transition active:scale-95" aria-label="The day before">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </a>

        <div class="min-w-0 flex-1 text-center">
            <p class="truncate text-sm font-semibold text-ink-900">
                {{ $isToday ? 'Today' : $date->format('l, j F') }}
            </p>
            <p class="text-xs text-ink-400">
                {{ $isToday ? $date->format('l, j F') : '' }} cooking for {{ $servingsForTonight }}
            </p>
        </div>

        <a href="{{ route('tonight', ['date' => $nextDay->toDateString(), 'slot' => $slot->value]) }}"
           class="grid size-tap shrink-0 place-items-center rounded-xl border border-ink-200 bg-white text-ink-600
                  transition active:scale-95" aria-label="The day after">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        </a>
    </div>

    {{-- The three slots of whichever day is showing. --}}
    <div class="mt-2 flex gap-1.5">
        @foreach (\App\Enums\MealSlot::ordered() as $option)
            <a href="{{ route('tonight', ['date' => $date->toDateString(), 'slot' => $option->value]) }}"
               class="min-h-9 flex-1 rounded-lg px-3 py-1.5 text-center text-sm font-medium transition
                      {{ $option === $slot
                          ? 'bg-brand-600 text-white'
                          : 'border border-ink-200 bg-white text-ink-600 hover:border-brand-400' }}">
                {{ $option->label() }}
            </a>
        @endforeach
    </div>

    @unless ($isToday)
        <p class="mt-2 rounded-xl bg-ink-100 px-4 py-2.5 text-sm text-ink-700">
            Showing {{ $date->format('l, j F') }}. Marking a meal made from here takes its
            ingredients out of the pantry just the same.
        </p>
    @endunless

    @if (! $recipe && $extras->isEmpty())
        <div class="mt-4 rounded-2xl border border-dashed border-ink-200 bg-white px-5 py-10 text-center">
            <p class="text-base font-medium text-ink-900">Nothing planned yet</p>
            <p class="mt-1 text-sm text-ink-600">Pick something and it will appear here.</p>
            <a href="{{ route('plan.picker', ['date' => $date->toDateString(), 'slot' => $slot->value]) }}"
               class="mt-4 inline-flex min-h-tap items-center rounded-xl bg-brand-600 px-5 font-semibold text-white
                      shadow-sm transition hover:bg-brand-700">
                Choose {{ mb_strtolower($slot->label()) }}
            </a>
        </div>
    @else
        @if ($recipe)
            <article class="mt-3 overflow-hidden rounded-2xl border border-ink-200 bg-white shadow-sm">
                @if ($recipe->hasImage())
                    <img src="{{ $recipe->imageUrl() }}" alt="" class="h-44 w-full object-cover sm:h-56">
                @endif

                <div class="p-5">
                    <h2 class="text-2xl font-semibold tracking-tight text-ink-900">{{ $recipe->name }}</h2>

                    <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-ink-600">
                        <span>{{ $recipe->protein_type->label() }}</span>
                        <span>Serves {{ $recipe->base_servings * $multiplier }}</span>
                        @if ($multiplier > 1)
                            <span class="rounded bg-brand-50 px-1.5 py-0.5 text-xs font-medium text-brand-700">
                                scaled {{ $multiplier }}&times;
                            </span>
                        @endif
                    </p>

                    @foreach ($recipe->recipe_links ?? [] as $link)
                        <a href="{{ $link }}" target="_blank" rel="noopener noreferrer"
                           class="mt-3 inline-flex min-h-tap items-center gap-1.5 text-sm font-medium
                                  text-brand-600 hover:text-brand-700">
                            View recipe
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3"/>
                            </svg>
                        </a>
                    @endforeach

                    @if ($scaled->isNotEmpty())
                        <h3 class="mt-5 text-sm font-semibold text-ink-900">Ingredients</h3>
                        <ul class="mt-2 divide-y divide-ink-100 border-y border-ink-100">
                            @foreach ($scaled as $ingredient)
                                @php
                                    // Built as one string so the amount and unit
                                    // never break across lines mid-measurement.
                                    $amount = $ingredient['quantity'] === null
                                        ? null
                                        : trim(rtrim(rtrim(number_format($ingredient['quantity'], 2), '0'), '.')
                                            .' '.$ingredient['unit']);
                                @endphp
                                <li class="flex items-baseline gap-3 py-2.5 text-sm">
                                    <span class="w-24 shrink-0 font-medium text-ink-900">{{ $amount ?? '—' }}</span>
                                    <span class="min-w-0 flex-1 text-ink-800">{{ $ingredient['name'] }}</span>
                                    @if ($ingredient['fromStock'])
                                        {{-- Spec 4.6: kept off the grocery list, still shown here. --}}
                                        <span class="shrink-0 rounded bg-ink-100 px-1.5 py-0.5 text-[11px]
                                                     font-medium text-ink-600">from freezer</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @elseif ($recipe->ingredients_status === \App\Enums\IngredientsStatus::NotYetAdded)
                        <p class="mt-5 rounded-xl bg-ink-100 px-4 py-3 text-sm text-ink-600">
                            Ingredients haven&rsquo;t been added for this one yet.
                        </p>
                    @endif

                    {{-- Spec 5 asks for "instructions/link" here. The link alone
                         meant leaving the app to cook, which is most of what
                         this screen exists to avoid. --}}
                    @if ($recipe->hasInstructions())
                        <h3 class="mt-5 text-sm font-semibold text-ink-900">Method</h3>
                        <ol class="mt-2 space-y-3">
                            @foreach ($recipe->instructions as $index => $step)
                                <li class="flex gap-3">
                                    <span class="grid size-7 shrink-0 place-items-center rounded-full bg-brand-600
                                                 text-xs font-semibold text-white">{{ $index + 1 }}</span>
                                    <span class="min-w-0 flex-1 pt-0.5 text-base/7 text-ink-800">{{ $step }}</span>
                                </li>
                            @endforeach
                        </ol>
                    @elseif (($recipe->recipe_links ?? []) !== [])
                        <p class="mt-5 rounded-xl bg-ink-100 px-4 py-3 text-sm text-ink-600">
                            No method saved for this one &mdash; open the recipe link above, or
                            <a href="{{ route('recipes.show', $recipe) }}"
                               class="font-medium text-brand-600 underline underline-offset-2">add it once</a>
                            so it&rsquo;s here next time.
                        </p>
                    @endif

                    @if (filled($recipe->notes))
                        <div class="mt-5 rounded-xl bg-brand-50 px-4 py-3">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-brand-700">Notes</h3>
                            <p class="mt-1 text-sm text-ink-800">{{ $recipe->notes }}</p>
                        </div>
                    @endif

                    {{-- Spec 5: quick-access rating control, for after the fact. --}}
                    <div class="mt-5 border-t border-ink-100 pt-4">
                        <h3 class="text-sm font-semibold text-ink-900">How was it?</h3>
                        <div class="mt-2 flex flex-wrap gap-2">
                            {{-- Pairs, not an enum-keyed map: PHP array keys can
                                 only be int or string, never an enum instance. --}}
                            @foreach ([
                                [\App\Enums\Rating::ThumbsUp, 'Loved it'],
                                [\App\Enums\Rating::JustOk, 'Just OK'],
                                [\App\Enums\Rating::ThumbsDown, 'No thanks'],
                            ] as [$rating, $label])
                                <form method="POST" action="{{ route('recipes.rate', $recipe) }}">
                                    @csrf
                                    <input type="hidden" name="rating" value="{{ $rating->value }}">
                                    <button type="submit"
                                            class="min-h-tap rounded-xl border px-4 text-sm font-medium transition
                                                   {{ $recipe->rating === $rating
                                                       ? 'border-brand-600 bg-brand-600 text-white'
                                                       : 'border-ink-200 bg-white text-ink-800 hover:border-brand-400' }}">
                                        {{ $label }}
                                    </button>
                                </form>
                            @endforeach

                            <form method="POST" action="{{ route('recipes.cooked', $recipe) }}">
                                @csrf
                                {{-- The day being shown, not today, so marking
                                     last night's dinner this morning records
                                     last night. --}}
                                <input type="hidden" name="cooked_on" value="{{ $date->toDateString() }}">
                                <button type="submit"
                                        class="min-h-tap rounded-xl border border-ink-200 bg-white px-4 text-sm
                                               font-medium text-ink-800 transition hover:border-brand-400">
                                    {{ $isToday ? 'Mark as made' : 'Mark as made on '.$date->format('j M') }}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </article>
        @endif

        @if ($extras->isNotEmpty())
            <section class="mt-3 rounded-2xl border border-ink-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-ink-900">{{ $recipe ? 'With' : 'On the table' }}</h2>
                <ul class="mt-2 flex flex-wrap gap-2">
                    @foreach ($extras as $extra)
                        <li class="rounded-lg bg-ink-100 px-3 py-1.5 text-sm text-ink-800">
                            {{ $extra->displayName() }}
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endif
@endsection
