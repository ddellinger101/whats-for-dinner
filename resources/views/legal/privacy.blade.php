@extends('layouts.public', ['updated' => $updated])

@section('title', 'Privacy Policy')

@section('content')
    <p>
        What&rsquo;s For Dinner is a private meal-planning application used by a single
        household. It is not offered to the public and has no users other than the
        household members who run it.
    </p>

    <h2 class="pt-2 text-xl font-semibold text-ink-900">What this app stores</h2>
    <p>
        Meal plans, recipes, ingredients, grocery lists, recipe ratings, and any
        photographs added to a recipe. This information is entered by the household
        and is kept on a private server controlled by the operator. It is not sold,
        rented, shared with third parties, or used for advertising.
    </p>

    <h2 class="pt-2 text-xl font-semibold text-ink-900">Google account data</h2>
    <p>
        With permission, this app connects to Google Calendar using the
        <code class="rounded bg-ink-100 px-1.5 py-0.5 text-sm">calendar.events</code>
        scope. It uses that access for one purpose only: to create and update
        meal events on a calendar chosen by the household. The connection is
        one-way. The app does not read a calendar back into itself, and it does
        not access Gmail, Drive, contacts, or any other Google service.
    </p>
    <p>
        Only an access token and refresh token are stored, so the app can write
        events without asking to sign in each time. No Google profile information
        is retained beyond what is needed to identify the connected calendar.
    </p>

    <h2 class="pt-2 text-xl font-semibold text-ink-900">Limited Use</h2>
    <p>
        This application&rsquo;s use of information received from Google APIs adheres
        to the
        <a class="font-medium text-brand-600 underline underline-offset-2 hover:text-brand-700"
           href="https://developers.google.com/terms/api-services-user-data-policy"
           rel="noopener noreferrer" target="_blank">Google API Services User Data Policy</a>,
        including the Limited Use requirements. Google user data is never used for
        advertising, is never sold, and is never transferred to others except as
        required for the meal-calendar feature described above, to comply with
        applicable law, or as part of a merger or acquisition.
    </p>

    <h2 class="pt-2 text-xl font-semibold text-ink-900">Removing access and data</h2>
    <p>
        Google access can be revoked at any time from
        <a class="font-medium text-brand-600 underline underline-offset-2 hover:text-brand-700"
           href="https://myaccount.google.com/permissions"
           rel="noopener noreferrer" target="_blank">your Google Account permissions page</a>,
        which immediately and permanently ends this app&rsquo;s ability to write to
        your calendar. To have stored application data deleted, contact the address
        below.
    </p>

    <h2 class="pt-2 text-xl font-semibold text-ink-900">Contact</h2>
    <p>
        Questions about this policy can be sent to
        <a class="font-medium text-brand-600 underline underline-offset-2 hover:text-brand-700"
           href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
    </p>
@endsection
