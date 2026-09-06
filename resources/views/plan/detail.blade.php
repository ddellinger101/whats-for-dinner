{{-- A planned meal, opened from the plan grid.

     A native <dialog> rather than a positioned div: showModal() puts it in the
     top layer, so it is not clipped by the day card's overflow-hidden — the
     trap that made the grocery aisle menu and the pantry edit menu invisible.

     Quantities are scaled to the servings this slot is actually planned for,
     not the recipe's own yield, since that is the number the cook needs. --}}
@php
    $recipe = $component->recipe;
    $item = $component->simpleItem;
    $multiplier = $recipe?->servingMultiplierFor($component->servings_needed) ?? 1;
@endphp

{{-- A click on the backdrop reports the dialog itself as the target, which is
     how tapping outside closes it. Escape is handled by the browser. --}}
<dialog id="meal-{{ $component->id }}"
        onclick="if (event.target === this) this.close()"
        class="m-auto w-[min(34rem,calc(100vw-1.5rem))] rounded-2xl border border-ink-200 bg-white p-0
               text-ink-900 shadow-xl backdrop:bg-ink-900/50">
    <div class="max-h-[85vh] overflow-y-auto overscroll-contain">
        @if ($recipe?->hasImage())
            <img src="{{ $recipe->imageUrl() }}" alt="" loading="lazy"
                 class="h-40 w-full rounded-t-2xl object-cover sm:h-48">
        @endif

        <div class="p-5">
            <div class="flex items-start gap-3">
                <div class="min-w-0 flex-1">
                    <h2 class="text-lg font-semibold tracking-tight">{{ $component->displayName() }}</h2>
                    <p class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-ink-600">
                        <span>{{ $slot->label() }} &middot; {{ $day->format('D j M') }}</span>
                        <span>{{ $component->servings_needed }} serving{{ $component->servings_needed === 1 ? '' : 's' }}</span>
                        @if ($recipe)
                            <span>{{ $recipe->protein_type->label() }}</span>
                        @endif
                    </p>
                </div>

                <form method="dialog" class="shrink-0">
                    <button type="submit"
                            class="grid size-9 place-items-center rounded-lg text-ink-400 transition
                                   hover:bg-ink-100 hover:text-ink-800"
                            aria-label="Close">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </form>
            </div>

            @if ($recipe && $recipe->category_tags->isNotEmpty())
                <div class="mt-3 flex flex-wrap gap-1.5">
                    @foreach ($recipe->category_tags as $tag)
                        <span class="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-medium text-brand-700">
                            {{ $tag->label() }}
                        </span>
                    @endforeach
                </div>
            @endif

            @if ($recipe)
                @if ($recipe->ingredients->isNotEmpty())
                    <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-ink-400">
                        Ingredients
                        <span class="font-normal normal-case tracking-normal">
                            &middot; for {{ $component->servings_needed }}
                        </span>
                    </h3>
                    <ul class="mt-1.5 divide-y divide-ink-100 border-y border-ink-100">
                        @foreach ($recipe->ingredients as $ingredient)
                            @php
                                $perServing = $ingredient->pivot->quantity_per_serving;
                                $total = $perServing === null
                                    ? null
                                    : trim(rtrim(rtrim(number_format(
                                        $perServing * $recipe->base_servings * $multiplier, 2), '0'), '.')
                                        .' '.($ingredient->pivot->unit ?? $ingredient->default_unit));
                            @endphp
                            <li class="flex items-baseline gap-3 py-1.5 text-sm">
                                <span class="w-24 shrink-0 font-medium">{{ $total ?? '—' }}</span>
                                <span class="min-w-0 flex-1 text-ink-800">
                                    {{ $ingredient->name }}
                                    @if ($ingredient->betterFresh())
                                        <span class="whitespace-nowrap text-xs text-leaf-600">&#10022; better fresh</span>
                                    @endif
                                </span>
                                @if ($ingredient->isStaple())
                                    <span class="shrink-0 text-[11px] text-ink-400">spice rack</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-4 rounded-xl bg-ink-50 px-4 py-3 text-sm text-ink-600">
                        No ingredients saved for this one yet.
                    </p>
                @endif

                @if ($recipe->hasInstructions())
                    <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-ink-400">Method</h3>
                    <ol class="mt-1.5 space-y-2">
                        @foreach ($recipe->instructions as $index => $step)
                            <li class="flex gap-3 text-sm text-ink-800">
                                <span class="grid size-5 shrink-0 place-items-center rounded-full bg-ink-100
                                             text-[11px] font-semibold text-ink-600">{{ $index + 1 }}</span>
                                <span class="min-w-0 flex-1">{{ $step }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif

                <a href="{{ route('recipes.show', $recipe) }}"
                   class="mt-5 flex min-h-tap items-center justify-center rounded-xl border border-ink-200
                          bg-white px-4 text-sm font-medium text-ink-800 transition hover:border-brand-400">
                    Open the full recipe
                </a>
            @else
                {{-- A simple item is a thing you buy, not a thing you cook, so
                     the most useful answer is what it puts on the list. --}}
                <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-ink-400">On the grocery list</h3>
                <ul class="mt-1.5 divide-y divide-ink-100 border-y border-ink-100">
                    @foreach ($item?->groceryLines() ?? [] as $line)
                        <li class="py-1.5 text-sm text-ink-800">{{ $line }}</li>
                    @endforeach
                </ul>
                <p class="mt-3 text-sm text-ink-600">
                    No recipe &mdash; this one is bought rather than cooked.
                </p>
            @endif
        </div>
    </div>
</dialog>
