@extends('layouts.public', ['updated' => $updated])

@section('title', 'Terms of Service')

@section('content')
    <p>
        What&rsquo;s For Dinner is a private application built for one household&rsquo;s own
        use. These terms cover the people who have been given an account on it.
    </p>

    <h2 class="pt-2 text-xl font-semibold text-ink-900">Use of the app</h2>
    <p>
        Accounts are created by the operator and are not open to the public. Please
        do not share account credentials, and do not use the app to store anything
        it was not built for.
    </p>

    <h2 class="pt-2 text-xl font-semibold text-ink-900">Recipe content</h2>
    <p>
        Recipes, links, and images saved in this app may originate from third-party
        websites. They are kept for the household&rsquo;s personal reference only and are
        not republished. Copyright in that material remains with whoever created it.
    </p>

    <h2 class="pt-2 text-xl font-semibold text-ink-900">No warranty</h2>
    <p>
        The app is provided as-is, with no guarantee that it will be available,
        accurate, or free of errors. Meal plans, grocery lists, and expiration
        estimates are conveniences, not authoritative advice &mdash; in particular,
        the &ldquo;use it up&rdquo; dates are estimates and should never override your own
        judgement about whether food is still good.
    </p>

    <h2 class="pt-2 text-xl font-semibold text-ink-900">Changes</h2>
    <p>
        These terms may change as the app changes. The date at the top of this page
        reflects the most recent revision.
    </p>

    <h2 class="pt-2 text-xl font-semibold text-ink-900">Contact</h2>
    <p>
        Questions can be sent to
        <a class="font-medium text-brand-600 underline underline-offset-2 hover:text-brand-700"
           href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
    </p>
@endsection
