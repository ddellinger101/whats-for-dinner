@extends('layouts.app')

@section('title', 'Meal Plan')
@section('heading', 'Meal Plan')

@section('content')
    {{-- Week navigation --}}
    <div class="flex items-center gap-2">
        <a href="{{ route('plan', ['start' => $weekStart->copy()->subWeek()->toDateString()]) }}"
           class="grid size-tap shrink-0 place-items-center rounded-xl border border-ink-200 bg-white text-ink-600
                  transition active:scale-95" aria-label="Previous week">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </a>

        <div class="min-w-0 flex-1 text-center">
            <p class="truncate text-sm font-semibold text-ink-900">
                {{ $weekStart->format('j M') }} &ndash; {{ $weekStart->copy()->addDays(6)->format('j M Y') }}
            </p>
            @if ($settings->diet_mode)
                <p class="text-xs font-medium text-leaf-600">{{ ucfirst($settings->diet_mode) }} mode on</p>
            @endif
        </div>

        <a href="{{ route('plan', ['start' => $weekStart->copy()->addWeek()->toDateString()]) }}"
           class="grid size-tap shrink-0 place-items-center rounded-xl border border-ink-200 bg-white text-ink-600
                  transition active:scale-95" aria-label="Next week">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        </a>
    </div>

    {{-- Spec 5: a scrollable day-by-day card view on phones, opening out into a
         fuller grid on tablets and desktop. --}}
    <div class="mt-4 space-y-3 md:grid md:grid-cols-2 md:gap-3 md:space-y-0 lg:grid-cols-3">
        @foreach ($days as $day)
            @php
                $isToday = $day->isSameDay($today);
                $dayServings = $scheduled[$day->toDateString()];
            @endphp

            <section class="overflow-hidden rounded-2xl border bg-white shadow-sm
                            {{ $isToday ? 'border-brand-400 ring-1 ring-brand-200' : 'border-ink-200' }}">
                <header class="flex items-baseline gap-2 border-b border-ink-100 px-4 py-2.5
                               {{ $isToday ? 'bg-brand-50' : 'bg-ink-50/60' }}">
                    <h2 class="text-sm font-semibold {{ $isToday ? 'text-brand-800' : 'text-ink-900' }}">
                        {{ $day->format('D') }}
                        <span class="font-normal text-ink-600">{{ $day->format('j M') }}</span>
                    </h2>
                    @if ($isToday)
                        <span class="rounded-full bg-brand-600 px-2 py-0.5 text-[11px] font-semibold text-white">Today</span>
                    @endif
                    <span class="ml-auto text-xs text-ink-400">{{ $dayServings }} serving{{ $dayServings === 1 ? '' : 's' }}</span>
                </header>

                <div class="divide-y divide-ink-100">
                    @foreach ($slots as $slot)
                        @php
                            $entry = $entries->get($day->toDateString().'|'.$slot->value);
                            $components = $entry?->components ?? collect();
                        @endphp

                        <div class="px-4 py-2.5">
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] font-semibold uppercase tracking-wide text-ink-400">
                                    {{ $slot->label() }}
                                </span>
                                @if ($entry?->servings_manually_set)
                                    <span class="rounded bg-leaf-500/15 px-1.5 py-0.5 text-[10px] font-semibold text-leaf-600"
                                          title="Servings pinned by hand">{{ $entry->household_size_used }} pinned</span>
                                @endif

                                <a href="{{ route('plan.picker', ['date' => $day->toDateString(), 'slot' => $slot->value]) }}"
                                   class="ml-auto grid size-8 place-items-center rounded-lg text-brand-600
                                          transition hover:bg-brand-50 active:scale-95"
                                   aria-label="Add to {{ $slot->label() }} on {{ $day->format('D j M') }}">
                                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                                </a>
                            </div>

                            @if ($components->isEmpty())
                                <p class="mt-1 text-sm text-ink-400">&mdash;</p>
                            @else
                                <ul class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach ($components->sortByDesc('is_primary') as $component)
                                        <li class="group flex max-w-full items-center gap-1 rounded-lg py-1 pl-2.5 pr-1 text-sm
                                                   {{ $component->is_primary
                                                       ? 'bg-brand-600 font-medium text-white'
                                                       : 'bg-ink-100 text-ink-800' }}">
                                            {{-- The name opens the meal; only
                                                 the × removes it. Two targets
                                                 in one chip, so the tap areas
                                                 stay separate. --}}
                                            <button type="button"
                                                    onclick="document.getElementById('meal-{{ $component->id }}').showModal()"
                                                    class="min-w-0 truncate text-left underline-offset-2 hover:underline"
                                                    aria-label="Show {{ $component->displayName() }}">
                                                {{ $component->displayName() }}
                                            </button>
                                            <form method="POST"
                                                  action="{{ route('plan.component.remove', $component) }}"
                                                  class="shrink-0">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="grid size-6 place-items-center rounded transition
                                                               {{ $component->is_primary
                                                                   ? 'text-brand-100 hover:bg-white/20 hover:text-white'
                                                                   : 'text-ink-400 hover:bg-ink-200 hover:text-ink-800' }}"
                                                        aria-label="Remove {{ $component->displayName() }}">
                                                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none"
                                                         stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                                                        <path d="M18 6 6 18M6 6l12 12"/>
                                                    </svg>
                                                </button>
                                            </form>

                                            @include('plan.detail', [
                                                'component' => $component,
                                                'day' => $day,
                                                'slot' => $slot,
                                            ])
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            {{-- Spec: holidays and guests break the rotation, so any
                                 slot can be pinned to its own count. --}}
                            <details class="mt-1.5">
                                <summary class="cursor-pointer list-none text-xs text-ink-400 hover:text-brand-600">
                                    Set servings
                                </summary>
                                <form method="POST"
                                      action="{{ route('plan.servings', ['date' => $day->toDateString(), 'slot' => $slot->value]) }}"
                                      class="mt-1.5 flex items-center gap-1.5">
                                    @csrf
                                    <input type="number" name="servings" min="1" max="60"
                                           value="{{ $entry->household_size_used ?? $dayServings }}"
                                           class="min-h-9 w-20 rounded-lg border border-ink-200 px-2 text-sm
                                                  outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
                                    <button type="submit"
                                            class="min-h-9 rounded-lg bg-ink-100 px-3 text-sm font-medium text-ink-800
                                                   transition hover:bg-ink-200">Save</button>
                                </form>
                            </details>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endsection
