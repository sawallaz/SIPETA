<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Root path redirects to the admin panel (/admin).
     *
     * SIPETA is a single-operator admin app (Laravel + Filament) with no
     * public landing page, so GET / must 302 to /admin.
     */
    public function test_root_redirects_to_admin(): void
    {
        $this->get('/')
            ->assertRedirect('/admin');
    }
}
