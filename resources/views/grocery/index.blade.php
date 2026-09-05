@extends('layouts.app')

@section('title', 'Grocery List')
@section('heading', 'Grocery List')

@section('content')
    <form method="POST" action="{{ route('grocery.store') }}" class="space-y-2">
        @csrf
        <div class="flex gap-2">
            {{-- Autofill draws on everything ever on the list, plus every known
                 ingredient, so last week's shopping never has to be retyped. --}}
            <input type="text" name="item_name" required maxlength="120" list="grocery-suggestions"
                   autocomplete="off" placeholder="Add an item&hellip;"
                   class="min-h-tap min-w-0 flex-1 rounded-xl border border-ink-200 bg-white px-4 text-base
                          outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
            <button type="submit"
                    class="min-h-tap shrink-0 rounded-xl bg-brand-600 px-5 font-semibold text-white shadow-sm
                           transition hover:bg-brand-700 active:scale-95">Add</button>
        </div>

        <datalist id="grocery-suggestions">
            @foreach ($suggestions as $suggestion)
                <option value="{{ $suggestion }}"></option>
            @endforeach
        </datalist>

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

            <ul class="mt-1.5 divide-y divide-ink-100 overflow-hidden rounded-2xl border border-ink-200 bg-white shadow-sm">
                @foreach ($section['items'] as $item)
                    @php $bought = $item->status === \App\Enums\GroceryItemStatus::Purchased; @endphp
                    <li class="flex items-center gap-2 px-3 py-2 {{ $bought ? 'bg-ink-50/60' : '' }}">
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

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium
                                      {{ $bought ? 'text-ink-400 line-through' : 'text-ink-900' }}">
                                @if ($item->quantity !== null)
                                    <span class="{{ $bought ? '' : 'text-ink-600' }}">
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

                        @unless ($bought)
                            {{-- Re-filing an item also teaches the guesser, which
                                 looks at what this name was last filed under. --}}
                            <details class="relative shrink-0">
                                <summary class="grid size-tap cursor-pointer list-none place-items-center rounded-lg
                                                text-ink-300 transition hover:bg-ink-100 hover:text-brand-600"
                                         aria-label="Change aisle for {{ $item->item_name }}">
                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 6h18M7 12h10M10 18h4"/>
                                    </svg>
                                </summary>
                                <div class="absolute right-0 z-20 mt-1 w-40 rounded-xl border border-ink-200
                                            bg-white p-1.5 shadow-lg">
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
                                </div>
                            </details>

                            @if ($item->ingredient_id)
                                {{-- Spec 5: flag stock straight from the list. --}}
                                <form method="POST" action="{{ route('grocery.stocked', $item) }}" class="shrink-0">
                                    @csrf
                                    <button type="submit"
                                            class="grid size-tap place-items-center rounded-lg text-ink-300 transition
                                                   hover:bg-ink-100 hover:text-leaf-600"
                                            aria-label="I already have {{ $item->item_name }}" title="Already have it">
                                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M5 8h14l-1 12H6L5 8zM9 8V6a3 3 0 0 1 6 0v2"/>
                                        </svg>
                                    </button>
                                </form>
                            @endif
                        @endunless

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
        </section>
    @endforeach
@endsection
