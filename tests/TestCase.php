<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        }

        // Tests rebuild the users schema per-class; never reuse a cached
        // column check from a previous test class.
        \App\Http\Middleware\EnsureEmailIsVerifiedForApi::flushColumnCache();
    }
}
