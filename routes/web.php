<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Public legal pages. Google's OAuth verification requires a reachable privacy
 * policy and terms page on the same domain as the app before it will verify the
 * Calendar scope. Laravel normalises the trailing slash, so /terms and /terms/
 * both land here.
 */
Route::view('/privacy-policy', 'legal.privacy', [
    'updated' => config('app.legal_updated'),
    'contactEmail' => config('app.contact_email'),
])->name('legal.privacy');

Route::view('/terms', 'legal.terms', [
    'updated' => config('app.legal_updated'),
    'contactEmail' => config('app.contact_email'),
])->name('legal.terms');
