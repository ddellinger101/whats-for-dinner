@extends('layouts.app')

@section('title', 'Settings')
@section('heading', 'Settings')

@section('content')
    @if ($errors->any())
        <p class="rounded-xl bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $errors->first() }}</p>
    @endif

    {{-- ------------------------------------------------ Google Calendar --}}
    <section class="rounded-2xl border border-ink-200 bg-white p-5 shadow-sm">
        <h2 class="text-base font-semibold text-ink-900">Google Calendar</h2>
        <p class="mt-1 text-sm text-ink-600">
            Meals you plan are added to a calendar of your choosing. One-way &mdash;
            nothing is ever read back out of Google.
        </p>

        @if (! $googleConfigured)
            <p class="mt-3 rounded-xl bg-ink-100 px-4 py-3 text-sm text-ink-600">
                Google credentials aren&rsquo;t configured on this server yet.
            </p>
        @elseif (! $credential->isConnected())
            <a href="{{ route('google.connect') }}"
               class="mt-4 inline-flex min-h-tap items-center gap-2 rounded-xl bg-brand-600 px-5 font-semibold
                      text-white shadow-sm transition hover:bg-brand-700">
                Connect Google Calendar
            </a>
            <p class="mt-2 text-xs text-ink-400">
                You&rsquo;ll see a &ldquo;Google hasn&rsquo;t verified this app&rdquo; screen &mdash;
                choose Advanced, then continue. That&rsquo;s expected for a private app.
            </p>
        @else
            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1
                             font-medium text-brand-700">
                    <span class="size-2 rounded-full bg-brand-600"></span>
                    Connected{{ $credential->google_email ? ' as '.$credential->google_email : '' }}
                </span>
                @if ($credential->last_synced_at)
                    <span class="text-ink-400">last sent {{ $credential->last_synced_at->diffForHumans() }}</span>
                @endif
            </div>

            @if ($credential->last_error)
                {{-- Shown here on purpose. The household's previous app died
                     silently for a week with its errors going only to a log. --}}
                <p class="mt-3 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">
                    {{ $credential->last_error }}
                </p>
            @endif

            @if ($calendarError)
                <p class="mt-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    Couldn&rsquo;t read your calendar list: {{ $calendarError }}
                </p>
            @endif

            <h3 class="mt-5 text-sm font-medium text-ink-800">Which calendar?</h3>

            @if ($calendars->isEmpty())
                <p class="mt-1.5 text-sm text-ink-600">No writable calendars came back from Google.</p>
            @else
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($calendars as $calendar)
                        <form method="POST" action="{{ route('google.calendar') }}">
                            @csrf
                            <input type="hidden" name="calendar_id" value="{{ $calendar['id'] }}">
                            <input type="hidden" name="calendar_name" value="{{ $calendar['name'] }}">
                            <button type="submit"
                                    class="min-h-tap rounded-xl border px-4 text-sm font-medium transition
                                           {{ $credential->calendar_id === $calendar['id']
                                               ? 'border-brand-600 bg-brand-600 text-white'
                                               : 'border-ink-200 bg-white text-ink-800 hover:border-brand-400' }}">
                                {{ $calendar['name'] }}
                                @if ($calendar['primary'])
                                    <span class="opacity-70">(main)</span>
                                @endif
                            </button>
                        </form>
                    @endforeach
                </div>
            @endif

            <div class="mt-5 flex flex-wrap gap-2 border-t border-ink-100 pt-4">
                @if ($credential->isReady())
                    <form method="POST" action="{{ route('google.backfill') }}">
                        @csrf
                        <button type="submit"
                                class="min-h-tap rounded-xl border border-ink-200 bg-white px-4 text-sm
                                       font-medium text-ink-800 transition hover:border-brand-400">
                            Send this week&rsquo;s meals
                        </button>
                    </form>
                @endif
                <form method="POST" action="{{ route('google.disconnect') }}">
                    @csrf
                    <button type="submit"
                            class="min-h-tap rounded-xl border border-ink-200 bg-white px-4 text-sm font-medium
                                   text-ink-700 transition hover:border-red-300 hover:text-red-600">
                        Disconnect
                    </button>
                </form>
            </div>
        @endif
    </section>

    {{-- ---------------------------------------------------- household --}}
    <form method="POST" action="{{ route('settings.update') }}"
          class="mt-4 space-y-5 rounded-2xl border border-ink-200 bg-white p-5 shadow-sm">
        @csrf
        <h2 class="text-base font-semibold text-ink-900">Household</h2>

        <label class="flex min-h-tap cursor-pointer items-center gap-3">
            <input type="hidden" name="kids_this_weekend" value="0">
            <input type="checkbox" name="kids_this_weekend" value="1" class="peer sr-only"
                   @checked($settings->kids_this_weekend)>
            <span class="relative h-6 w-11 shrink-0 rounded-full bg-ink-200 transition peer-checked:bg-brand-600
                         after:absolute after:left-0.5 after:top-0.5 after:size-5 after:rounded-full after:bg-white
                         after:transition peer-checked:after:translate-x-5"></span>
            <span class="text-sm font-medium text-ink-800">Kids here this weekend</span>
        </label>

        <div>
            <label for="shopping_day" class="block text-sm font-medium text-ink-800">Shopping day</label>
            <p class="text-xs text-ink-400">Use-by dates are counted from this day.</p>
            <select id="shopping_day" name="shopping_day_of_week"
                    class="mt-1.5 min-h-tap w-full rounded-xl border border-ink-200 bg-white px-3 text-base
                           outline-none focus:border-brand-500 sm:w-56">
                @foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $i => $day)
                    <option value="{{ $i }}" @selected($settings->shopping_day_of_week === $i)>{{ $day }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="diet_mode" class="block text-sm font-medium text-ink-800">Diet filter</label>
            <p class="text-xs text-ink-400">Hides off-diet recipes from dinner suggestions.</p>
            <select id="diet_mode" name="diet_mode"
                    class="mt-1.5 min-h-tap w-full rounded-xl border border-ink-200 bg-white px-3 text-base
                           outline-none focus:border-brand-500 sm:w-56">
                <option value="" @selected(! $settings->diet_mode)>Off</option>
                <option value="keto" @selected($settings->diet_mode === 'keto')>Keto only</option>
            </select>
        </div>

        <div>
            <label for="timezone" class="block text-sm font-medium text-ink-800">Time zone</label>
            <p class="text-xs text-ink-400">When calendar events land.</p>
            <select id="timezone" name="timezone"
                    class="mt-1.5 min-h-tap w-full rounded-xl border border-ink-200 bg-white px-3 text-base
                           outline-none focus:border-brand-500 sm:w-72">
                @foreach ($timezones as $tz)
                    <option value="{{ $tz }}" @selected($settings->timezone === $tz)>{{ str_replace('_', ' ', $tz) }}</option>
                @endforeach
            </select>
        </div>

        <button type="submit"
                class="min-h-tap w-full rounded-xl bg-brand-600 px-5 font-semibold text-white shadow-sm
                       transition hover:bg-brand-700 sm:w-auto sm:px-8">
            Save
        </button>
    </form>

    {{-- ------------------------------------------------ weekly servings --}}
    <form method="POST" action="{{ route('settings.schedule') }}"
          class="mt-4 rounded-2xl border border-ink-200 bg-white p-5 shadow-sm">
        @csrf
        <h2 class="text-base font-semibold text-ink-900">Weekly servings</h2>
        <p class="mt-1 text-sm text-ink-600">
            How many people each day usually feeds. Any single meal can still be pinned
            to its own number.
        </p>

        <div class="mt-3 space-y-2">
            @foreach ($schedule as $day)
                @php
                    $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                @endphp
                <div class="flex flex-wrap items-center gap-2 border-b border-ink-100 pb-2 last:border-0">
                    <span class="w-24 shrink-0 text-sm font-medium text-ink-800">{{ $names[$day->day_of_week] }}</span>

                    <label class="flex items-center gap-1.5 text-xs text-ink-600">
                        with kids
                        <input type="number" min="1" max="60"
                               name="days[{{ $day->day_of_week }}][servings_with_kids]"
                               value="{{ $day->servings_with_kids }}"
                               class="min-h-9 w-16 rounded-lg border border-ink-200 px-2 text-sm outline-none
                                      focus:border-brand-500">
                    </label>

                    <label class="flex items-center gap-1.5 text-xs text-ink-600">
                        without
                        <input type="number" min="1" max="60"
                               name="days[{{ $day->day_of_week }}][servings_without_kids]"
                               value="{{ $day->servings_without_kids }}"
                               class="min-h-9 w-16 rounded-lg border border-ink-200 px-2 text-sm outline-none
                                      focus:border-brand-500">
                    </label>

                    <label class="ml-auto flex cursor-pointer items-center gap-1.5 text-xs text-ink-600">
                        <input type="hidden" name="days[{{ $day->day_of_week }}][varies_by_custody]" value="0">
                        <input type="checkbox" name="days[{{ $day->day_of_week }}][varies_by_custody]" value="1"
                               class="size-4 rounded border-ink-300" @checked($day->varies_by_custody)>
                        custody day
                    </label>
                </div>
            @endforeach
        </div>

        <button type="submit"
                class="mt-4 min-h-tap w-full rounded-xl bg-brand-600 px-5 font-semibold text-white shadow-sm
                       transition hover:bg-brand-700 sm:w-auto sm:px-8">
            Save servings
        </button>
    </form>

    <p class="mt-6 pb-2 text-center text-xs text-ink-400">
        <a href="{{ route('legal.privacy') }}" class="hover:text-brand-600">Privacy</a>
        <span class="px-1.5">&middot;</span>
        <a href="{{ route('legal.terms') }}" class="hover:text-brand-600">Terms</a>
    </p>
@endsection
