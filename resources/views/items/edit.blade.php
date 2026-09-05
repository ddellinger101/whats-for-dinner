@extends('layouts.app')

@section('title', $item->name)
@section('heading', $justAdded ? 'What goes in it?' : 'Edit item')

@section('content')
    <div class="rounded-2xl border border-ink-200 bg-white p-5 shadow-sm">
        <h1 class="text-xl font-semibold tracking-tight text-ink-900">{{ $item->name }}</h1>

        @if ($justAdded)
            <p class="mt-1 text-sm text-ink-600">
                Added to the plan. If this is made of several things, list them and
                they&rsquo;ll go on the grocery list every time you use it.
            </p>
        @else
            <p class="mt-1 text-sm text-ink-600">
                What this adds to the grocery list whenever it&rsquo;s planned.
            </p>
        @endif

        @if ($errors->any())
            <p class="mt-3 rounded-xl bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $errors->first() }}</p>
        @endif

        {{-- The save button lives outside this element so it can sit beside the
             skip button, which posts elsewhere; forms cannot nest, so they are
             associated by id instead. --}}
        <form id="item-form" method="POST" action="{{ route('items.update', $item) }}" class="mt-4">
            @csrf

            <label for="breakdown" class="block text-sm font-medium text-ink-800">Grocery items</label>
            <textarea id="breakdown" name="breakdown" rows="5" autofocus
                      placeholder="turkey&#10;bread&#10;cheese&#10;mayo"
                      class="mt-1.5 w-full rounded-xl border border-ink-200 px-3.5 py-2.5 text-base outline-none
                             focus:border-brand-500 focus:ring-2 focus:ring-brand-200">{{ implode("\n", $item->grocery_breakdown ?? []) }}</textarea>
            <p class="mt-1 text-xs text-ink-400">
                One per line, or separated by commas. Leave it empty for something like
                &ldquo;Grapes&rdquo; that goes on the list under its own name.
            </p>

            <fieldset class="mt-4">
                <legend class="text-sm font-medium text-ink-800">Usually shows up at</legend>
                <div class="mt-1.5 flex flex-wrap gap-2">
                    @foreach ($hints as $hint)
                        <label class="min-h-tap cursor-pointer rounded-xl border px-4 py-2.5 text-sm font-medium transition
                                      {{ $item->meal_type_hint === $hint
                                          ? 'border-brand-600 bg-brand-600 text-white'
                                          : 'border-ink-200 bg-white text-ink-800 hover:border-brand-400' }}">
                            <input type="radio" name="meal_type_hint" value="{{ $hint->value }}" class="sr-only"
                                   @checked($item->meal_type_hint === $hint)>
                            {{ $hint->label() }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
        </form>

        <div class="mt-5 flex flex-wrap items-stretch gap-2">
            <button type="submit" form="item-form"
                    class="min-h-tap flex-1 rounded-xl bg-brand-600 px-5 font-semibold text-white shadow-sm
                           transition hover:bg-brand-700 sm:flex-none">
                Save
            </button>

            @if ($justAdded)
                {{-- Spec 4.5: skipping is allowed, and the raw name becomes the
                     grocery line. Recorded so the prompt does not reappear. --}}
                <form method="POST" action="{{ route('items.skip', $item) }}">
                    @csrf
                    <button type="submit"
                            class="min-h-tap rounded-xl border border-ink-200 bg-white px-5 font-medium
                                   text-ink-700 transition hover:border-ink-300">
                        Skip for now
                    </button>
                </form>
            @else
                <a href="{{ route('items') }}"
                   class="inline-flex min-h-tap items-center rounded-xl border border-ink-200 bg-white px-5
                          font-medium text-ink-700 transition hover:border-ink-300">
                    Cancel
                </a>
            @endif
        </div>
    </div>
@endsection
