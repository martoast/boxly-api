<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Browser-called API prefixes must be listed in config/cors.php `paths`, otherwise the
 * browser blocks the response (status 0 in the app) even though curl works. This shipped
 * once: /in-person/* was missing and every customer call of the new booking flow failed.
 */
class CorsPathsTest extends TestCase
{
    private function allowed(string $uri): bool
    {
        foreach (config('cors.paths', []) as $pattern) {
            if (Str::is($pattern, $uri)) {
                return true;
            }
        }

        return false;
    }

    public function test_every_in_person_route_is_covered_by_cors_paths(): void
    {
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_contains($uri, 'in-person')) {
                continue;
            }
            $checked++;
            $this->assertTrue($this->allowed($uri), "Route /{$uri} is not covered by config/cors.php paths");
        }
        $this->assertGreaterThan(10, $checked);
    }

    public function test_preflight_for_in_person_customer_routes_is_allowed(): void
    {
        foreach (['in-person/availability', 'in-person/reservations', 'in-person/reservations/RV-26-0001'] as $path) {
            $this->assertTrue($this->allowed($path), "/{$path} must be in cors.paths");
        }
    }
}
