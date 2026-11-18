<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may ever reach the real Nationalize.io API (the free quota is tiny
        // and names are personal data). Any request not covered by Http::fake() fails.
        Http::preventStrayRequests();
    }
}
