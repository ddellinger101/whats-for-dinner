@extends('layouts.app')

@section('title', 'Saved Items')
@section('heading', 'Saved Items')

@section('content')
    <p class="px-1 text-sm text-ink-600">
        Lunch and side items you can add to any slot without re-typing them.
    </p>

    @if ($items->isEmpty())
        <p class="mt-4 rounded-xl border border-dashed border-ink-200 px-4 py-8 text-center text-sm text-ink-600">
            Nothing saved yet. Anything you type into a meal slot is kept here.
        </p>
    @endif

    @foreach ($items as $group => $groupItems)
        <h2 class="mt-5 px-1 text-sm font-semibold text-ink-900">{{ $group }}</h2>
        <ul class="mt-2 divide-y divide-ink-100 overflow-hidden rounded-2xl border border-ink-200 bg-white shadow-sm">
            @foreach ($groupItems as $item)
                <li class="flex items-center gap-3 px-3 py-2">
                    <a href="{{ route('items.edit', $item) }}" class="min-w-0 flex-1 py-1.5">
                        <span class="block truncate text-sm font-medium text-ink-900">{{ $item->name }}</span>
                        <span class="mt-0.5 block truncate text-xs text-ink-400">
                            @if ($item->isCompound())
                                {{ implode(', ', $item->grocery_breakdown) }}
                            @else
                                goes on the list under its own name
                            @endif
                        </span>
                    </a>

                    <a href="{{ route('items.edit', $item) }}"
                       class="grid size-tap shrink-0 place-items-center rounded-lg text-ink-300
                              transition hover:bg-ink-100 hover:text-brand-600"
                       aria-label="Edit {{ $item->name }}">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                        </svg>
                    </a>

                    <form method="POST" action="{{ route('items.destroy', $item) }}" class="shrink-0">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="grid size-tap place-items-center rounded-lg text-ink-300
                                       transition hover:bg-ink-100 hover:text-red-600"
                                aria-label="Delete {{ $item->name }}">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endforeach
@endsection
