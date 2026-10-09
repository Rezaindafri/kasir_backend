<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardAuthTest extends TestCase
{
    public function test_owner_dashboard_requires_authentication(): void
    {
        $response = $this->getJson('/api/dashboard/owner');

        $response->assertStatus(401)
            ->assertJsonStructure([
                'message',
            ]);
    }
}
