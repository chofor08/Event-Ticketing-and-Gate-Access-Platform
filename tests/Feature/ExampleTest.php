<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_does_not_expose_a_web_frontend(): void
    {
        $this->get('/')->assertNotFound();
    }
}
