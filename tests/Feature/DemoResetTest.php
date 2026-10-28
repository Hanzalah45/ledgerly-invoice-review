<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Not using RefreshDatabase: migrate:fresh cannot run inside its wrapping transaction. */
class DemoResetTest extends TestCase
{
    public function test_reset_rebuilds_and_seeds_the_workspace(): void
    {
        config(['docai.public_demo' => true]);
        Storage::fake('local'); // the command deletes upload directories

        $this->artisan('docai:demo-reset')->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'reviewer@ledgerly.test', 'role' => User::ROLE_REVIEWER]);
        $this->assertGreaterThan(0, Document::count());
    }
}
