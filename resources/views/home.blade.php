@extends('layouts.app')

@section('title', 'Home')
@section('heading', "What's For Dinner")

@section('content')
    <p class="px-1 text-sm text-ink-600">{{ $today->format('l, j F') }}</p>

    {{-- Spec 5: two large, tap-friendly buttons, sized for a phone held in one
         hand in a kitchen. --}}
    <div class="mt-3 space-y-3">
        <a href="{{ route('tonight') }}"
           class="group block overflow-hidden rounded-2xl bg-linear-to-br from-brand-500 to-brand-700 p-5
                  text-white shadow-sm transition active:scale-[.99]">
            <div class="flex items-start gap-4">
                <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-white/15">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 11h18M5 11a7 7 0 0 1 14 0M4 15h16M6 19h12"/>
                    </svg>
                </span>
                <span class="min-w-0">
                    <span class="block text-xl font-semibold tracking-tight">What&rsquo;s For Dinner</span>
                    <span class="mt-0.5 block truncate text-sm text-brand-100">
                        @if ($tonight?->primaryComponent())
                            Tonight: {{ $tonight->primaryComponent()->displayName() }}
                        @elseif ($tonight && $tonight->components->isNotEmpty())
                            Tonight: {{ $tonight->components->map->displayName()->join(', ') }}
                        @else
                            Nothing planned for tonight yet
                        @endif
                    </span>
                </span>
            </div>
        </a>

        <a href="{{ route('plan') }}"
           class="group block overflow-hidden rounded-2xl border border-ink-200 bg-white p-5 shadow-sm
                  transition active:scale-[.99]">
            <div class="flex items-start gap-4">
                <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-700">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/>
                    </svg>
                </span>
                <span class="min-w-0">
                    <span class="block text-xl font-semibold tracking-tight text-ink-900">Meal Plan</span>
                    <span class="mt-0.5 block text-sm text-ink-600">
                        {{ $plannedDinners }} of 7 dinners planned this week
                    </span>
                </span>
            </div>
        </a>
    </div>

    <div class="mt-3 grid grid-cols-3 gap-3">
        <a href="{{ route('grocery') }}"
           class="rounded-2xl border border-ink-200 bg-white p-4 shadow-sm transition active:scale-[.99]">
            <span class="block text-2xl font-semibold tracking-tight text-ink-900">{{ $groceryCount }}</span>
            <span class="mt-0.5 block text-sm text-ink-600">to buy</span>
        </a>

        {{-- Highlighted only when there is something to act on: a permanently
             coloured tile stops being a signal. --}}
        <a href="{{ route('pantry') }}"
           class="rounded-2xl border p-4 shadow-sm transition active:scale-[.99]
                  {{ $atRiskCount > 0 ? 'border-leaf-500/60 bg-leaf-500/10' : 'border-ink-200 bg-white' }}">
            <span class="block text-2xl font-semibold tracking-tight
                         {{ $atRiskCount > 0 ? 'text-leaf-600' : 'text-ink-900' }}">{{ $atRiskCount }}</span>
            <span class="mt-0.5 block text-sm text-ink-600">to use up</span>
        </a>

        <a href="{{ route('recipes') }}"
           class="rounded-2xl border border-ink-200 bg-white p-4 shadow-sm transition active:scale-[.99]">
            <span class="block text-2xl font-semibold tracking-tight text-ink-900">
                {{ \App\Models\Recipe::count() }}
            </span>
            <span class="mt-0.5 block text-sm text-ink-600">recipes</span>
        </a>
    </div>
@endsection
