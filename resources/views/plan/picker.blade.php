@extends('layouts.app')

@section('title', 'Add to ' . $slot->label())
@section('heading', 'Add to ' . $slot->label())

@section('content')
    <div class="flex items-center gap-2">
        <a href="{{ route('plan', ['start' => $day->copy()->startOfWeek(\Illuminate\Support\Carbon::SUNDAY)->toDateString()]) }}"
           class="grid size-tap shrink-0 place-items-center rounded-xl border border-ink-200 bg-white text-ink-600"
           aria-label="Back to the plan">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-ink-900">{{ $day->format('l j M') }}</p>
            <p class="text-xs text-ink-600">{{ $slot->label() }}</p>
        </div>
    </div>

    @if ($errors->any())
        <p class="mt-3 rounded-xl bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $errors->first() }}</p>
    @endif

    @if ($wantsPrimary)
        {{-- Spec 4.2: the ranked suggestion list, only for a primary dinner. --}}
        <div class="mt-4 flex items-baseline gap-2">
            <h2 class="text-sm font-semibold text-ink-900">Suggestions</h2>
            @if ($dietMode)
                <span class="rounded-full bg-leaf-500/15 px-2 py-0.5 text-[11px] font-semibold text-leaf-600">
                    {{ ucfirst($dietMode) }} only
                </span>
            @endif
            <a href="{{ route('plan.picker', ['date' => $day->toDateString(), 'slot' => $slot->value, 'primary' => 0]) }}"
               class="ml-auto text-xs font-medium text-brand-600 hover:text-brand-700">Add a side instead</a>
        </div>

        @if ($suggestions->isEmpty())
            <p class="mt-3 rounded-xl border border-dashed border-ink-200 px-4 py-6 text-center text-sm text-ink-600">
                No recipes match. Try turning off the diet filter, or add a side instead.
            </p>
        @else
            <ul class="mt-2 space-y-2">
                @foreach ($suggestions as $suggestion)
                    @php $recipe = $suggestion->recipe; @endphp
                    <li>
                        <form method="POST"
                              action="{{ route('plan.primary', ['date' => $day->toDateString(), 'slot' => $slot->value]) }}">
                            @csrf
                            <input type="hidden" name="recipe_id" value="{{ $recipe->id }}">
                            <button type="submit"
                                    class="flex w-full items-center gap-3 rounded-xl border bg-white p-3 text-left
                                           shadow-sm transition active:scale-[.99]
                                           {{ $suggestion->isBoosted() ? 'border-leaf-500 ring-1 ring-leaf-500/30' : 'border-ink-200' }}">
                                @if ($recipe->hasImage())
                                    <img src="{{ $recipe->imageUrl() }}" alt=""
                                         class="size-12 shrink-0 rounded-lg object-cover">
                                @else
                                    <span class="grid size-12 shrink-0 place-items-center rounded-lg bg-brand-50
                                                 text-xs font-semibold text-brand-700">
                                        {{ $recipe->protein_type->label()[0] ?? '?' }}
                                    </span>
                                @endif

                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium text-ink-900">{{ $recipe->name }}</span>
                                    <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
                                        <span class="text-ink-400">{{ $recipe->protein_type->label() }}</span>
                                        @if ($recipe->times_made > 0)
                                            <span class="text-ink-400">made {{ $recipe->times_made }}&times;</span>
                                        @endif
                                        @if ($recipe->rating === \App\Enums\Rating::ThumbsUp)
                                            <span class="text-brand-600">&#9733; liked</span>
                                        @elseif ($recipe->rating === \App\Enums\Rating::JustOk)
                                            <span class="text-ink-400">just OK</span>
                                        @endif
                                    </span>
                                    @if ($reason = $suggestion->reason())
                                        <span class="mt-1 block truncate text-xs font-medium
                                                     {{ $suggestion->isBoosted() ? 'text-leaf-600' : 'text-ink-400' }}">
                                            {{ $reason }}
                                        </span>
                                    @endif
                                </span>
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    @else
        {{-- Spec 4.5: lighter search-or-create picker over the simple-item library. --}}
        <form method="GET" action="{{ route('plan.picker', ['date' => $day->toDateString(), 'slot' => $slot->value]) }}"
              class="mt-4">
            <input type="hidden" name="primary" value="0">
            <input type="search" name="q" value="{{ $search }}" placeholder="Search items and recipes&hellip;"
                   class="min-h-tap w-full rounded-xl border border-ink-200 px-4 text-base outline-none
                          focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
        </form>

        <form method="POST"
              action="{{ route('plan.side', ['date' => $day->toDateString(), 'slot' => $slot->value]) }}"
              class="mt-3 flex gap-2">
            @csrf
            <input type="text" name="new_item_name" placeholder="Or type something new&hellip;" maxlength="120"
                   class="min-h-tap min-w-0 flex-1 rounded-xl border border-ink-200 px-4 text-base outline-none
                          focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
            <button type="submit"
                    class="min-h-tap shrink-0 rounded-xl bg-brand-600 px-4 font-semibold text-white
                           transition hover:bg-brand-700">Add</button>
        </form>

        @if ($simpleItems->isNotEmpty())
            <div class="mt-5 flex items-baseline gap-2">
                <h2 class="text-sm font-semibold text-ink-900">Saved items</h2>
                <a href="{{ route('items') }}"
                   class="ml-auto text-xs font-medium text-brand-600 hover:text-brand-700">Manage</a>
            </div>
            <ul class="mt-2 flex flex-wrap gap-2">
                @foreach ($simpleItems as $item)
                    <li>
                        <form method="POST"
                              action="{{ route('plan.side', ['date' => $day->toDateString(), 'slot' => $slot->value]) }}">
                            @csrf
                            <input type="hidden" name="simple_item_id" value="{{ $item->id }}">
                            <button type="submit"
                                    class="min-h-tap rounded-xl border border-ink-200 bg-white px-4 text-sm
                                           font-medium text-ink-800 shadow-sm transition hover:border-brand-400
                                           hover:text-brand-700 active:scale-95">
                                {{ $item->name }}
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($recipes->isNotEmpty())
            <h2 class="mt-5 text-sm font-semibold text-ink-900">Recipes</h2>
            <ul class="mt-2 space-y-2">
                @foreach ($recipes as $recipe)
                    <li>
                        <form method="POST"
                              action="{{ route('plan.side', ['date' => $day->toDateString(), 'slot' => $slot->value]) }}">
                            @csrf
                            <input type="hidden" name="recipe_id" value="{{ $recipe->id }}">
                            <button type="submit"
                                    class="flex w-full items-center rounded-xl border border-ink-200 bg-white p-3
                                           text-left shadow-sm transition active:scale-[.99]">
                                <span class="min-w-0 flex-1 truncate font-medium text-ink-900">{{ $recipe->name }}</span>
                                <span class="ml-2 shrink-0 text-xs text-ink-400">{{ $recipe->protein_type->label() }}</span>
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($slot->usesSuggestionEngine())
            <p class="mt-6 text-center">
                <a href="{{ route('plan.picker', ['date' => $day->toDateString(), 'slot' => $slot->value, 'primary' => 1]) }}"
                   class="text-sm font-medium text-brand-600 hover:text-brand-700">
                    Choose a main dish instead
                </a>
            </p>
        @endif
    @endif
@endsection
