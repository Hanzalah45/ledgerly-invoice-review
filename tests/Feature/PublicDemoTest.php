<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicDemoTest extends TestCase
{
    use RefreshDatabase;

    private function reviewer(): User
    {
        return User::factory()->reviewer()->create(['email' => config('docai.demo_login_email')]);
    }

    public function test_demo_login_is_not_available_when_the_public_demo_is_off(): void
    {
        $this->reviewer();

        $this->post('/demo-login')->assertNotFound();
        $this->assertGuest();
    }

    public function test_demo_login_signs_in_the_seeded_reviewer(): void
    {
        config(['docai.public_demo' => true]);
        $user = $this->reviewer();

        $this->post('/demo-login')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->isAdmin());
    }

    public function test_demo_login_never_signs_in_an_admin(): void
    {
        config(['docai.public_demo' => true, 'docai.demo_login_email' => 'boss@example.test']);
        User::factory()->admin()->create(['email' => 'boss@example.test']);

        $this->post('/demo-login')->assertStatus(503);
        $this->assertGuest();
    }

    public function test_password_login_is_disabled_on_the_public_demo(): void
    {
        config(['docai.public_demo' => true]);
        User::factory()->reviewer()->create(['email' => 'someone@example.test', 'password' => 'password']);

        $this->post('/login', ['email' => 'someone@example.test', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_page_offers_the_demo_button_and_hides_the_password_form(): void
    {
        config(['docai.public_demo' => true]);

        $this->get('/login')
            ->assertOk()
            ->assertSee('Enter the demo workspace')
            ->assertDontSee('name="password"', false);
    }

    public function test_banner_is_shown_inside_the_app(): void
    {
        config(['docai.public_demo' => true]);

        $this->actingAs(User::factory()->reviewer()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Public demo.');
    }

    public function test_reset_refuses_to_run_unless_the_public_demo_is_on(): void
    {
        $this->artisan('docai:demo-reset')->assertFailed();
    }
}
