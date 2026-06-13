<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The admin panel loads a Vite-compiled theme (viteTheme); without a
        // built manifest that 500s every panel-rendering test. Tests assert
        // behaviour, not styling, so skip Vite globally — the compiled assets
        // are verified in the browser at each checkpoint, not in Pest.
        $this->withoutVite();
    }
}
