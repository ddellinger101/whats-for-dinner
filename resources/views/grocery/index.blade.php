@extends('layouts.app')

@section('title', 'Grocery List')
@section('heading', 'Grocery List')

@section('content')
    <form method="POST" action="{{ route('grocery.store') }}" class="flex gap-2">
        @csrf
        <input type="text" name="item_name" required maxlength="120" placeholder="Add an item&hellip;"
               class="min-h-tap min-w-0 flex-1 rounded-xl border border-ink-200 bg-white px-4 text-base
                      outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
        <button type="submit"
                class="min-h-tap shrink-0 rounded-xl bg-brand-600 px-5 font-semibold text-white shadow-sm
                       transition hover:bg-brand-700 active:scale-95">Add</button>
    </form>

    @if ($errors->any())
        <p class="mt-3 rounded-xl bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $errors->first() }}</p>
    @endif

    <div class="mt-4 flex items-baseline gap-2">
        <h2 class="text-sm font-semibold text-ink-900">To buy</h2>
        <span class="text-sm text-ink-400">{{ $needed->count() }}</span>
    </div>

    @if ($needed->isEmpty())
        <p class="mt-2 rounded-xl border border-dashed border-ink-200 px-4 py-8 text-center text-sm text-ink-600">
            Nothing on the list. Items appear here as you plan meals.
        </p>
    @else
        <ul class="mt-2 divide-y divide-ink-100 overflow-hidden rounded-2xl border border-ink-200 bg-white shadow-sm">
            @foreach ($needed as $item)
                <li class="flex items-center gap-3 px-3 py-2">
                    <form method="POST" action="{{ route('grocery.toggle', $item) }}" class="shrink-0">
                        @csrf
                        <button type="submit"
                                class="grid size-tap place-items-center rounded-lg text-ink-300
                                       transition hover:bg-ink-100 hover:text-brand-600"
                                aria-label="Mark {{ $item->item_name }} as purchased">
                            <span class="grid size-6 place-items-center rounded-md border-2 border-current"></span>
                        </button>
                    </form>

                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-ink-900">
                            @if ($item->quantity !== null)
                                <span class="text-ink-600">
                                    {{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }}{{ $item->unit ? ' '.$item->unit : '' }}
                                </span>
                            @endif
                            {{ $item->item_name }}
                        </p>
                        <p class="truncate text-xs text-ink-400">
                            @if ($item->sourceComponent)
                                for {{ $item->sourceComponent->displayName() }}
                            @else
                                {{ $item->source->label() }}
                            @endif
                        </p>
                    </div>

                    @if ($item->ingredient_id)
                        {{-- Spec 5: flag stock straight from the list. --}}
                        <form method="POST" action="{{ route('grocery.stocked', $item) }}" class="shrink-0">
                            @csrf
                            <button type="submit"
                                    class="grid size-tap place-items-center rounded-lg text-ink-300 transition
                                           hover:bg-ink-100 hover:text-leaf-600"
                                    aria-label="I already have {{ $item->item_name }}"
                                    title="Already have it">
                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M5 8h14l-1 12H6L5 8zM9 8V6a3 3 0 0 1 6 0v2"/>
                                </svg>
                            </button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('grocery.destroy', $item) }}" class="shrink-0">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="grid size-tap place-items-center rounded-lg text-ink-300 transition
                                       hover:bg-ink-100 hover:text-red-600"
                                aria-label="Remove {{ $item->item_name }}">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($purchased->isNotEmpty())
        <div class="mt-6 flex items-baseline gap-2">
            <h2 class="text-sm font-semibold text-ink-900">In the cart</h2>
            <span class="text-sm text-ink-400">{{ $purchased->count() }}</span>
            <form method="POST" action="{{ route('grocery.clear') }}" class="ml-auto">
                @csrf
                <button type="submit" class="text-xs font-medium text-ink-400 hover:text-red-600">Clear</button>
            </form>
        </div>

        <ul class="mt-2 divide-y divide-ink-100 overflow-hidden rounded-2xl border border-ink-200 bg-white/60">
            @foreach ($purchased as $item)
                <li class="flex items-center gap-3 px-3 py-2">
                    <form method="POST" action="{{ route('grocery.toggle', $item) }}" class="shrink-0">
                        @csrf
                        <button type="submit"
                                class="grid size-tap place-items-center rounded-lg text-brand-600 transition hover:bg-ink-100"
                                aria-label="Put {{ $item->item_name }} back on the list">
                            <span class="grid size-6 place-items-center rounded-md bg-brand-600 text-white">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m5 12 5 5L20 7"/>
                                </svg>
                            </span>
                        </button>
                    </form>
                    <p class="min-w-0 flex-1 truncate text-sm text-ink-400 line-through">{{ $item->item_name }}</p>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
