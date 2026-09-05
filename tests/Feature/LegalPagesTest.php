<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Google's OAuth verification will not pass the Calendar scope until it can
 * fetch a privacy policy and terms page on the app's own domain, so these
 * routes staying up is a release requirement, not a nicety.
 */
class LegalPagesTest extends TestCase
{
    public function test_privacy_policy_is_publicly_reachable(): void
    {
        $this->get('/privacy-policy')
            ->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee(config('app.contact_email'));
    }

    /** Verification specifically looks for the Limited Use disclosure. */
    public function test_privacy_policy_carries_the_google_limited_use_disclosure(): void
    {
        $this->get('/privacy-policy')
            ->assertOk()
            ->assertSee('Google API Services User Data Policy')
            ->assertSee('Limited Use')
            ->assertSee('calendar.events');
    }

    public function test_terms_is_publicly_reachable(): void
    {
        $this->get('/terms')
            ->assertOk()
            ->assertSee('Terms of Service');
    }

    /** Google was given the URLs with trailing slashes. */
    public function test_trailing_slash_urls_resolve(): void
    {
        $this->get('/privacy-policy/')->assertOk();
        $this->get('/terms/')->assertOk();
    }

    /** Neither page may sit behind auth, or the crawler cannot read it. */
    public function test_legal_pages_require_no_authentication(): void
    {
        $this->assertGuest();
        $this->get('/privacy-policy')->assertOk();
        $this->get('/terms')->assertOk();
    }
}
