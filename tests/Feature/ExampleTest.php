<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_home_page_loads_the_frontend(): void
    {
        $this->get('/')->assertOk()->assertSee('Gather | Find your next good thing');
    }
}
