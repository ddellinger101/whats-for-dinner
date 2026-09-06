@extends('layouts.app')

@section('title', 'Grocery List')
@section('heading', 'Grocery List')

@section('content')
    <form method="POST" action="{{ route('grocery.store') }}" class="space-y-2">
        @csrf
        <div class="flex gap-2">
            {{-- Autofill draws on everything ever on the list, plus every known
                 ingredient, so last week's shopping never has to be retyped.

                 Built by hand rather than with <datalist>: Safari on iOS does
                 not render one, and this app is used on a phone in a kitchen,
                 so the element that silently does nothing there is the wrong
                 one to depend on. --}}
            <div class="relative min-w-0 flex-1">
                <input type="text" name="item_name" id="grocery-input" required maxlength="120"
                       autocomplete="off" autocapitalize="words" spellcheck="false"
                       role="combobox" aria-expanded="false" aria-autocomplete="list"
                       aria-controls="grocery-suggest" placeholder="Add an item&hellip;"
                       class="min-h-tap w-full rounded-xl border border-ink-200 bg-white px-4 text-base
                              outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200">

                <ul id="grocery-suggest" role="listbox" hidden
                    class="absolute inset-x-0 top-full z-30 mt-1 max-h-64 overflow-y-auto rounded-xl border
                           border-ink-200 bg-white py-1 shadow-lg"></ul>
            </div>

            <button type="submit"
                    class="min-h-tap shrink-0 rounded-xl bg-brand-600 px-5 font-semibold text-white shadow-sm
                           transition hover:bg-brand-700 active:scale-95">Add</button>
        </div>

        <script type="application/json" id="grocery-suggestion-data">@json($suggestions)</script>

        <details>
            <summary class="cursor-pointer list-none text-xs font-medium text-ink-400 hover:text-brand-600">
                Set the aisle
            </summary>
            <p class="mt-1.5 text-xs text-ink-400">
                Only needed if the app can&rsquo;t work it out. It remembers your choice next time.
            </p>
            <div class="mt-1.5 flex flex-wrap gap-1.5">
                @foreach ($allAisles as $aisle)
                    <label class="min-h-9 cursor-pointer rounded-lg border border-ink-200 bg-white px-3 py-1.5
                                  text-xs font-medium text-ink-700 transition hover:border-brand-400
                                  has-checked:border-brand-600 has-checked:bg-brand-600 has-checked:text-white">
                        <input type="radio" name="aisle" value="{{ $aisle->value }}" class="sr-only">
                        {{ $aisle->label() }}
                    </label>
                @endforeach
            </div>
        </details>
    </form>

    @if ($errors->any())
        <p class="mt-3 rounded-xl bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $errors->first() }}</p>
    @endif

    <div class="mt-4 flex items-baseline gap-2 px-1">
        <span class="text-sm font-semibold text-ink-900">{{ $neededCount }} to buy</span>
        @if ($purchasedCount > 0)
            <span class="text-sm text-ink-400">{{ $purchasedCount }} in the cart</span>
            <form method="POST" action="{{ route('grocery.clear') }}" class="ml-auto">
                @csrf
                <button type="submit" class="text-xs font-medium text-ink-400 hover:text-red-600">
                    Clear bought
                </button>
            </form>
        @endif
    </div>

    @if ($aisles->isEmpty())
        <p class="mt-2 rounded-xl border border-dashed border-ink-200 px-4 py-8 text-center text-sm text-ink-600">
            Nothing on the list. Items appear here as you plan meals.
        </p>
    @endif

    {{-- One section per aisle, in the order a shop is walked. Empty sections
         are dropped by the controller rather than rendered and hidden. --}}
    @foreach ($aisles as $section)
        <section class="mt-4">
            <h2 class="px-1 text-xs font-semibold uppercase tracking-wide text-ink-400">
                {{ $section['aisle']->label() }}
                <span class="font-normal normal-case tracking-normal">
                    &middot; {{ $section['items']->count() }}
                </span>
            </h2>

            {{-- No overflow-hidden: the aisle menu below is absolutely
                 positioned, and an overflow container clips it no matter what
                 z-index it carries. The corners are rounded on the end rows
                 instead, which is what the clipping was for. --}}
            <ul class="mt-1.5 divide-y divide-ink-100 rounded-2xl border border-ink-200 bg-white shadow-sm">
                @foreach ($section['items'] as $item)
                    @php $bought = $item->status === \App\Enums\GroceryItemStatus::Purchased; @endphp
                    <li class="flex items-center gap-2 px-3 py-2 first:rounded-t-2xl last:rounded-b-2xl
                               {{ $bought ? 'bg-ink-50/60' : '' }}">
                        <form method="POST" action="{{ route('grocery.toggle', $item) }}" class="shrink-0">
                            @csrf
                            <button type="submit"
                                    class="grid size-tap place-items-center rounded-lg transition hover:bg-ink-100
                                           {{ $bought ? 'text-brand-600' : 'text-ink-300 hover:text-brand-600' }}"
                                    aria-label="{{ $bought ? 'Put back on the list' : 'Mark as bought' }}: {{ $item->item_name }}">
                                @if ($bought)
                                    <span class="grid size-6 place-items-center rounded-md bg-brand-600 text-white">
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                             stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="m5 12 5 5L20 7"/>
                                        </svg>
                                    </span>
                                @else
                                    <span class="grid size-6 place-items-center rounded-md border-2 border-current"></span>
                                @endif
                            </button>
                        </form>

                        @php
                            $amount = $item->quantity === null
                                ? null
                                : trim(rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.')
                                    .' '.($item->unit ?? ''));
                        @endphp

                        <div class="min-w-0 flex-1">
                            {{-- The name has the whole line to itself. It is
                                 what you are scanning for in a shop, and it was
                                 sharing the row with the amount and three
                                 icons, leaving it a third of the width. --}}
                            <p class="truncate text-sm font-medium
                                      {{ $bought ? 'text-ink-400 line-through' : 'text-ink-900' }}">
                                {{ $item->item_name }}
                            </p>

                            <div class="flex items-baseline gap-x-2 text-xs text-ink-400">
                                {{-- The amount is still the control: the shop
                                     sells what it sells, and changing it here
                                     is the whole point of the line. Demoted to
                                     the second line, not demoted in function. --}}
                                <details class="relative shrink-0">
                                    <summary class="-ml-1 cursor-pointer list-none rounded px-1 py-0.5 font-medium
                                                    transition hover:bg-ink-100
                                                    {{ $bought ? 'text-ink-400' : 'text-ink-600' }}"
                                             aria-label="Change how much {{ $item->item_name }} to buy">
                                        {{ $amount ?? '+ amount' }}
                                    </summary>
                                    <div class="absolute left-0 z-30 mt-1 w-60 rounded-xl border border-ink-200
                                                bg-white p-3 text-left shadow-xl">
                                        <form method="POST" action="{{ route('grocery.quantity', $item) }}">
                                            @csrf
                                            <p class="text-xs text-ink-600">How much are you buying?</p>
                                            <div class="mt-1.5 flex gap-2">
                                                <input type="text" name="quantity" inputmode="decimal"
                                                       value="{{ $item->quantity === null ? '' : rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }}"
                                                       class="min-h-9 w-20 rounded-lg border border-ink-200 px-2 text-sm
                                                              outline-none focus:border-brand-500">
                                                <input type="text" name="unit" value="{{ $item->unit }}"
                                                       placeholder="unit" maxlength="20"
                                                       class="min-h-9 w-20 rounded-lg border border-ink-200 px-2 text-sm
                                                              outline-none focus:border-brand-500">
                                            </div>
                                            @if ($item->planned_quantity !== null)
                                                <p class="mt-1.5 text-xs text-ink-400">
                                                    This week&rsquo;s meals need
                                                    {{ rtrim(rtrim(number_format((float) $item->planned_quantity, 2), '0'), '.') }}{{ $item->unit ? ' '.$item->unit : '' }}.
                                                    Anything over goes to the pantry.
                                                </p>
                                            @endif
                                            <button type="submit"
                                                    class="mt-2 min-h-9 w-full rounded-lg bg-brand-600 px-3 text-sm
                                                           font-semibold text-white transition hover:bg-brand-700">
                                                Save
                                            </button>
                                        </form>
                                    </div>
                                </details>

                                <span class="min-w-0 flex-1 truncate">
                                    @if ($item->isOverBought())
                                        {{-- Named so the extra reads as a deliberate
                                             buy rather than a mistake. --}}
                                        <span class="text-leaf-600">
                                            {{ rtrim(rtrim(number_format((float) $item->planned_quantity, 2), '0'), '.') }} for this week,
                                            rest to the pantry
                                        </span>
                                    @elseif ($reason = $item->reasonLabel())
                                        {{-- One line can be buying for several
                                             meals, so it says how many rather
                                             than naming only the first. --}}
                                        {{ $reason }}
                                    @else
                                        {{ $item->source->label() }}
                                    @endif
                                </span>
                            </div>
                        </div>

                        {{-- One menu rather than three icons. Three tap targets
                             at 44px each, with their gaps, took a third of a
                             phone's width away from the name — and ticking the
                             box is the only thing done at any speed in a shop.
                             The rest are occasional, and an extra tap for them
                             buys back the room to read what you are buying. --}}
                        <details class="relative shrink-0">
                            <summary class="grid size-tap cursor-pointer list-none place-items-center rounded-lg
                                            text-ink-300 transition hover:bg-ink-100 hover:text-brand-600"
                                     aria-label="More for {{ $item->item_name }}">
                                <svg class="size-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <circle cx="12" cy="5" r="1.75"/><circle cx="12" cy="12" r="1.75"/>
                                    <circle cx="12" cy="19" r="1.75"/>
                                </svg>
                            </summary>
                            <div class="absolute right-0 z-30 mt-1 max-h-80 w-52 overflow-y-auto rounded-xl
                                        border border-ink-200 bg-white p-1.5 shadow-xl">
                                @unless ($bought)
                                    @if ($item->ingredient_id)
                                        {{-- Spec 5: flag stock straight from the list. --}}
                                        <form method="POST" action="{{ route('grocery.stocked', $item) }}">
                                            @csrf
                                            <button type="submit"
                                                    class="block w-full rounded-lg px-3 py-2 text-left text-sm
                                                           text-ink-700 transition hover:bg-ink-100">
                                                Already have it
                                            </button>
                                        </form>
                                    @endif

                                    {{-- Re-filing an item also teaches the guesser,
                                         which looks at what this name was last
                                         filed under. --}}
                                    <p class="mt-1 border-t border-ink-100 px-3 pb-1 pt-2 text-[11px] font-semibold
                                              uppercase tracking-wide text-ink-400">Aisle</p>
                                    @foreach ($allAisles as $aisle)
                                        <form method="POST" action="{{ route('grocery.aisle', $item) }}">
                                            @csrf
                                            <input type="hidden" name="aisle" value="{{ $aisle->value }}">
                                            <button type="submit"
                                                    class="block w-full rounded-lg px-3 py-2 text-left text-sm transition
                                                           {{ ($item->aisle ?? \App\Enums\GroceryAisle::Other) === $aisle
                                                               ? 'bg-brand-50 font-medium text-brand-700'
                                                               : 'text-ink-700 hover:bg-ink-100' }}">
                                                {{ $aisle->label() }}
                                            </button>
                                        </form>
                                    @endforeach
                                @endunless

                                <form method="POST" action="{{ route('grocery.destroy', $item) }}"
                                      class="{{ $bought ? '' : 'mt-1 border-t border-ink-100 pt-1' }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="block w-full rounded-lg px-3 py-2 text-left text-sm text-ink-700
                                                   transition hover:bg-red-50 hover:text-red-600">
                                        Remove from list
                                    </button>
                                </form>
                            </div>
                        </details>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
@endsection

@push('scripts')
<script>
(() => {
    const input = document.getElementById('grocery-input');
    const list = document.getElementById('grocery-suggest');
    const dataEl = document.getElementById('grocery-suggestion-data');
    if (!input || !list || !dataEl) return;

    let names = [];
    try { names = JSON.parse(dataEl.textContent) || []; } catch { return; }

    let active = -1;
    let shown = [];

    const close = () => {
        list.hidden = true;
        list.innerHTML = '';
        input.setAttribute('aria-expanded', 'false');
        active = -1;
        shown = [];
    };

    const choose = (value) => {
        input.value = value;
        close();
        input.focus();
    };

    const highlight = () => {
        [...list.children].forEach((li, i) => {
            li.classList.toggle('bg-brand-50', i === active);
            li.classList.toggle('text-brand-700', i === active);
        });
    };

    const open = (query) => {
        const q = query.trim().toLowerCase();
        if (q.length < 1) return close();

        // Names that start with what was typed come first: on a phone you type
        // two letters and expect the obvious match at the top, not an
        // alphabetical accident that happens to contain them.
        const starts = [], contains = [];
        for (const name of names) {
            const lower = name.toLowerCase();
            if (lower === q) continue;
            if (lower.startsWith(q)) starts.push(name);
            else if (lower.includes(q)) contains.push(name);
            if (starts.length >= 8) break;
        }

        shown = [...starts, ...contains].slice(0, 8);
        if (!shown.length) return close();

        list.innerHTML = '';
        shown.forEach((name) => {
            const li = document.createElement('li');
            li.role = 'option';
            li.textContent = name;
            li.className = 'cursor-pointer px-4 py-2.5 text-base text-ink-800';
            // pointerdown, not click: blur would close the list first.
            li.addEventListener('pointerdown', (e) => { e.preventDefault(); choose(name); });
            list.appendChild(li);
        });

        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        active = -1;
    };

    input.addEventListener('input', () => open(input.value));
    input.addEventListener('focus', () => { if (input.value) open(input.value); });
    input.addEventListener('blur', () => setTimeout(close, 120));

    input.addEventListener('keydown', (e) => {
        if (list.hidden) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            active = e.key === 'ArrowDown'
                ? (active + 1) % shown.length
                : (active <= 0 ? shown.length - 1 : active - 1);
            highlight();
        } else if (e.key === 'Enter' && active >= 0) {
            e.preventDefault();
            choose(shown[active]);
        } else if (e.key === 'Escape') {
            close();
        }
    });
})();
</script>
@endpush
