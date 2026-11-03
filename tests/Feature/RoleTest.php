<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SurveyCompletionNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $email = null): User
    {
        return User::create(['name' => 'Role '.$role, 'email' => $email ?? $role.'@example.test', 'password' => 'OnlyTesting123!', 'role' => $role]);
    }

    public function test_superadmin_has_every_admin_page(): void
    {
        $this->actingAs($this->user('superadmin'));
        foreach (['surveys.create', 'clients.index', 'invitations.index', 'bank.index', 'data-cleanup.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('dashboard'))->assertOk()->assertSee('Superadmin')->assertSee(route('data-cleanup.index'), false);
    }

    public function test_operation_and_viewer_cannot_open_admin_pages(): void
    {
        foreach (['operation' => 'Operation', 'viewer' => 'Viewer'] as $role => $label) {
            $this->actingAs($this->user($role));
            $this->get(route('surveys.create'))->assertForbidden();
            $this->get(route('data-cleanup.index'))->assertForbidden();
            $this->get(route('dashboard'))->assertOk()->assertSee($label)->assertDontSee(route('data-cleanup.index'), false);
        }
    }

    public function test_admin_is_unchanged(): void
    {
        $admin = $this->user('admin');
        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($admin->isSuperadmin());
        $this->actingAs($admin)->get(route('surveys.create'))->assertOk();
        $this->get(route('dashboard'))->assertSee('Administrator');
    }

    public function test_create_user_command_accepts_new_roles_only(): void
    {
        foreach (['superadmin', 'operation'] as $role) {
            $this->artisan('suara:user', ['--role' => $role])
                ->expectsQuestion('Nama', 'Akun '.$role)
                ->expectsQuestion('Email', $role.'@example.test')
                ->expectsQuestion('Password (minimal 12 karakter, huruf besar/kecil, angka, simbol)', 'OnlyTesting123!')
                ->expectsQuestion('Ulangi password', 'OnlyTesting123!')
                ->assertSuccessful();
            $this->assertSame($role, User::where('email', $role.'@example.test')->value('role'));
        }
        $this->artisan('suara:user', ['--role' => 'owner'])
            ->expectsQuestion('Nama', 'Akun salah')
            ->expectsQuestion('Email', 'owner@example.test')
            ->expectsQuestion('Password (minimal 12 karakter, huruf besar/kecil, angka, simbol)', 'OnlyTesting123!')
            ->expectsQuestion('Ulangi password', 'OnlyTesting123!')
            ->assertFailed();
    }

    public function test_completion_email_fallback_does_not_include_superadmin(): void
    {
        config(['survey.completion_recipients' => []]);
        $this->user('admin');
        $this->user('superadmin');
        $this->assertSame(['admin@example.test'], array_keys(app(SurveyCompletionNotifier::class)->recipients()));
    }
}
