@php
    $days = $flag->daysLeft($today);
    $expired = $flag->hasExpired($today);
@endphp

<li class="px-3 py-2">
    <div class="flex items-center gap-1">
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-ink-900">{{ $flag->ingredient->name }}</p>
            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-ink-400">
                <span>{{ $flag->amountLabel() }}</span>
                @if ($days !== null)
                    <span class="{{ $expired ? 'font-medium text-red-600' : ($urgent ? 'font-medium text-leaf-600' : '') }}">
                        @if ($expired)
                            {{ abs($days) }} {{ Str::plural('day', abs($days)) }} past its date
                        @elseif ($days === 0)
                            use today
                        @else
                            {{ $days }} {{ Str::plural('day', $days) }} left
                        @endif
                    </span>
                @endif
                @if (filled($flag->note))
                    <span class="truncate">{{ $flag->note }}</span>
                @endif
            </p>
        </div>

        {{-- The point of knowing what is going off is doing something about it,
             so the way to act on it sits next to the item rather than being
             left as an exercise. --}}
        <a href="{{ route('recipes', ['ingredient' => $flag->ingredient_id]) }}"
           class="grid size-tap shrink-0 place-items-center rounded-lg transition hover:bg-ink-100
                  {{ $urgent ? 'text-leaf-600 hover:text-leaf-600' : 'text-ink-300 hover:text-brand-600' }}"
           aria-label="Find recipes using {{ $flag->ingredient->name }}"
           title="Find recipes using this">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>
            </svg>
        </a>

        <details class="relative shrink-0">
            <summary class="grid size-tap cursor-pointer list-none place-items-center rounded-lg text-ink-300
                            transition hover:bg-ink-100 hover:text-brand-600"
                     aria-label="Edit {{ $flag->ingredient->name }}">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                </svg>
            </summary>
            <div class="absolute right-0 z-20 mt-1 w-64 rounded-xl border border-ink-200 bg-white p-3 shadow-lg">
                <form method="POST" action="{{ route('pantry.update', $flag) }}" class="space-y-2">
                    @csrf
                    <div class="flex gap-2">
                        <input type="text" name="quantity" inputmode="decimal"
                               value="{{ $flag->quantity === null ? '' : rtrim(rtrim(number_format((float) $flag->quantity, 2), '0'), '.') }}"
                               placeholder="amount"
                               class="min-h-9 w-20 rounded-lg border border-ink-200 px-2 text-sm outline-none
                                      focus:border-brand-500">
                        <input type="text" name="unit" value="{{ $flag->unit }}" placeholder="unit" maxlength="20"
                               class="min-h-9 w-20 rounded-lg border border-ink-200 px-2 text-sm outline-none
                                      focus:border-brand-500">
                    </div>
                    <label class="block text-xs text-ink-600">
                        Use by
                        <input type="date" name="expires_on"
                               value="{{ $flag->expires_on?->toDateString() }}"
                               class="mt-1 block min-h-9 w-full rounded-lg border border-ink-200 px-2 text-sm
                                      outline-none focus:border-brand-500">
                    </label>
                    <button type="submit"
                            class="min-h-9 w-full rounded-lg bg-brand-600 px-3 text-sm font-semibold text-white
                                   transition hover:bg-brand-700">Save</button>
                </form>

                <form method="POST" action="{{ route('pantry.gone', $flag) }}" class="mt-2">
                    @csrf
                    <button type="submit"
                            class="min-h-9 w-full rounded-lg border border-ink-200 px-3 text-sm font-medium
                                   text-ink-700 transition hover:border-red-300 hover:text-red-600">
                        It&rsquo;s gone
                    </button>
                </form>
            </div>
        </details>
        {{-- There was a third icon here, an x that did exactly what "It's gone"
             above does. Two 44px targets and their gaps for one action, on the
             screen where the names are longest. --}}
    </div>
</li>
