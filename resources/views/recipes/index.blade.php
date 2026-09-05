@extends('layouts.app')

@section('title', 'Recipes')
@section('heading', 'Recipes')

@section('header-actions')
    <a href="{{ route('recipes.create') }}"
       class="grid size-tap place-items-center rounded-lg text-brand-100 transition hover:bg-white/10 hover:text-white"
       aria-label="Add a recipe" title="Add a recipe">
        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    </a>
@endsection

@section('content')
    <form method="GET" action="{{ route('recipes') }}">
        <input type="search" name="q" value="{{ $search }}" placeholder="Search recipes&hellip;"
               class="min-h-tap w-full rounded-xl border border-ink-200 bg-white px-4 text-base outline-none
                      focus:border-brand-500 focus:ring-2 focus:ring-brand-200">

        {{-- Filters keep the current search, so narrowing never loses it. --}}
        <div class="-mx-4 mt-3 overflow-x-auto px-4 pb-1">
            <div class="flex w-max gap-2">
                <a href="{{ route('recipes', ['q' => $search ?: null]) }}"
                   class="min-h-9 rounded-full border px-3.5 py-1.5 text-sm font-medium transition
                          {{ ! $protein && ! $tag ? 'border-brand-600 bg-brand-600 text-white' : 'border-ink-200 bg-white text-ink-600' }}">
                    All
                </a>
                @foreach ($proteins as $option)
                    <a href="{{ route('recipes', ['protein' => $option->value, 'q' => $search ?: null]) }}"
                       class="min-h-9 whitespace-nowrap rounded-full border px-3.5 py-1.5 text-sm font-medium transition
                              {{ $protein === $option->value ? 'border-brand-600 bg-brand-600 text-white' : 'border-ink-200 bg-white text-ink-600' }}">
                        {{ $option->label() }}
                    </a>
                @endforeach
            </div>
        </div>

        <div class="-mx-4 mt-2 overflow-x-auto px-4 pb-1">
            <div class="flex w-max gap-2">
                @foreach ($tags as $option)
                    <a href="{{ route('recipes', ['tag' => $option->value, 'q' => $search ?: null]) }}"
                       class="min-h-9 whitespace-nowrap rounded-full border px-3.5 py-1.5 text-sm font-medium transition
                              {{ $tag === $option->value ? 'border-leaf-600 bg-leaf-600 text-white' : 'border-ink-200 bg-white text-ink-600' }}">
                        {{ $option->label() }}
                    </a>
                @endforeach
            </div>
        </div>
    </form>

    <p class="mt-4 px-1 text-xs text-ink-400">{{ $recipes->total() }} recipes</p>

    <ul class="mt-2 space-y-2">
        @foreach ($recipes as $recipe)
            @php $blocked = ! $recipe->isSelectable(); @endphp
            <li>
                <a href="{{ route('recipes.show', $recipe) }}"
                   class="flex items-center gap-3 rounded-xl border border-ink-200 bg-white p-3 shadow-sm
                          transition active:scale-[.99] {{ $blocked ? 'opacity-55' : '' }}">
                    @if ($recipe->hasImage())
                        <img src="{{ $recipe->imageUrl() }}" alt="" class="size-12 shrink-0 rounded-lg object-cover">
                    @else
                        <span class="grid size-12 shrink-0 place-items-center rounded-lg bg-brand-50 text-xs
                                     font-semibold text-brand-700">
                            {{ Str::substr($recipe->protein_type->label(), 0, 1) }}
                        </span>
                    @endif

                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium text-ink-900">{{ $recipe->name }}</span>
                        <span class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-ink-400">
                            <span>{{ $recipe->protein_type->label() }}</span>
                            @if ($recipe->times_made > 0)
                                <span>made {{ $recipe->times_made }}&times;</span>
                            @endif
                            @if ($recipe->is_keto)
                                <span class="text-leaf-600">keto</span>
                            @endif
                            @if ($recipe->ingredients_status === \App\Enums\IngredientsStatus::NotYetAdded)
                                <span>no ingredients yet</span>
                            @endif
                        </span>
                    </span>

                    @if ($blocked)
                        {{-- Spec 5: thumbs-down stays visible for history, greyed out. --}}
                        <span class="shrink-0 rounded-full bg-ink-100 px-2 py-0.5 text-[11px] font-semibold text-ink-600">
                            hidden
                        </span>
                    @elseif ($recipe->rating === \App\Enums\Rating::ThumbsUp)
                        <span class="shrink-0 text-brand-600" aria-label="Liked">&#9733;</span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>

    @if ($recipes->isEmpty())
        <p class="mt-2 rounded-xl border border-dashed border-ink-200 px-4 py-8 text-center text-sm text-ink-600">
            Nothing matches those filters.
        </p>
    @endif

    <div class="mt-4">{{ $recipes->links() }}</div>
@endsection
