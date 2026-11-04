<?php

namespace Tests\Feature;

use App\Models\{User, Workspace};
use App\Services\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CxpSsoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cxp.sso_secret' => 'test-cxp-sso-secret',
            'cxp.sso_public_key' => null,
            'cxp.sso_public_key_path' => null,
            'cxp.algorithms' => ['HS256'],
            'cxp.issuer' => 'transcxp',
            'cxp.audience' => 'transsurvey',
            'cxp.ttl' => 300,
            'cxp.leeway' => 60,
            'cxp.external_source' => 'transcxp',
            'cxp.redirect_route' => 'dashboard',
            'cxp.allow_operation_password' => false,
        ]);

        Cache::flush();
    }

    private function payload(array $overrides = []): array
    {
        $now = time();

        return array_replace_recursive([
            'iss' => 'transcxp',
            'aud' => 'transsurvey',
            'iat' => $now,
            'exp' => $now + 300,
            'jti' => (string) Str::uuid(),
            'user' => [
                'id' => 'cx-user-1',
                'name' => 'Operator CXP',
                'email' => 'operator@cxp.test',
            ],
            'project' => [
                'code' => 'brand-a',
                'name' => 'Brand A',
            ],
            'ticket' => [
                'id' => 'TCK-1',
                'status' => 'open',
                'channel' => 'chat',
            ],
            'customer' => [
                'id' => 'CUST-1',
                'name' => 'Budi',
                'email' => 'budi@example.test',
            ],
        ], $overrides);
    }

    private function sign(array $payload, ?string $secret = null, string $algorithm = 'HS256'): string
    {
        $header = ['alg' => $algorithm, 'typ' => 'JWT'];
        $b64 = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $json = fn (array $value): string => $b64(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $unsigned = $json($header).'.'.$json($payload);
        $secret ??= (string) config('cxp.sso_secret');

        return $unsigned.'.'.$b64(hash_hmac('sha256', $unsigned, $secret, true));
    }

    private function launch(array $payload, ?string $secret = null): TestResponse
    {
        return $this->get(route('cxp.launch', ['token' => $this->sign($payload, $secret)]));
    }

    public function test_valid_token_creates_workspace_and_operation_user_and_logs_in(): void
    {
        $response = $this->launch($this->payload());

        $response->assertRedirect(route('dashboard'))->assertSessionHas('success');

        $user = User::where('external_source', 'transcxp')->where('external_id', 'cx-user-1')->sole();
        $workspace = Workspace::where('code', 'brand-a')->sole();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('operation', $user->role);
        $this->assertTrue($user->workspaces()->whereKey($workspace->id)->exists());
        $this->assertSame($workspace->id, session(CurrentWorkspace::SESSION_KEY));
        $this->assertSame('TCK-1', session('cxp.context.ticket.id'));
        $this->assertSame('Budi', session('cxp.context.customer.name'));
        $this->assertSame('budi@example.test', session('cxp.context.customer.email'));
    }

    public function test_launch_session_can_open_dashboard_for_the_project_workspace(): void
    {
        $this->launch($this->payload())->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Brand A');
    }

    public function test_rs256_token_with_configured_public_key_is_accepted(): void
    {
        $privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($privateKey === false) {
            $this->markTestSkipped('OpenSSL tidak dapat membuat kunci uji di lingkungan ini.');
        }

        $details = openssl_pkey_get_details($privateKey);
        $this->assertIsArray($details);

        config([
            'cxp.algorithms' => ['RS256'],
            'cxp.sso_public_key' => $details['key'],
        ]);

        $b64 = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $json = fn (array $value): string => $b64(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $unsigned = $json(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$json($this->payload());

        $this->assertTrue(openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256));

        $this->get(route('cxp.launch', ['token' => $unsigned.'.'.$b64($signature)]))
            ->assertRedirect(route('dashboard'));

        $this->assertSame(1, User::where('external_source', 'transcxp')->count());
    }

    public function test_same_user_and_project_reuses_existing_workspace_and_account(): void
    {
        $this->launch($this->payload())->assertRedirect(route('dashboard'));
        $this->launch($this->payload())->assertRedirect(route('dashboard'));

        $this->assertSame(1, User::where('external_source', 'transcxp')->count());
        $this->assertSame(1, Workspace::where('code', 'brand-a')->count());
    }

    public function test_user_can_be_attached_to_another_project_workspace(): void
    {
        $this->launch($this->payload())->assertRedirect(route('dashboard'));
        $this->launch($this->payload([
            'project' => ['code' => 'brand-b', 'name' => 'Brand B'],
        ]))->assertRedirect(route('dashboard'));

        $user = User::where('external_source', 'transcxp')->where('external_id', 'cx-user-1')->sole();
        $workspace = Workspace::where('code', 'brand-b')->sole();

        $this->assertSame(2, $user->workspaces()->count());
        $this->assertSame($workspace->id, session(CurrentWorkspace::SESSION_KEY));
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->launch($this->payload([
            'iat' => time() - 600,
            'exp' => time() - 300,
        ]))->assertStatus(401)->assertSee(__('Token SSO sudah kedaluwarsa. Silakan buka ulang dari TransCXP.'));

        $this->assertSame(0, User::count());
    }

    public function test_bad_signature_is_rejected(): void
    {
        $this->launch($this->payload(), 'wrong-secret')
            ->assertStatus(401)
            ->assertSee(__('Tanda tangan token SSO tidak valid.'));

        $this->assertSame(0, User::count());
    }

    public function test_algorithm_none_is_rejected(): void
    {
        $token = $this->sign($this->payload(), null, 'none');

        $this->get(route('cxp.launch', ['token' => $token]))
            ->assertStatus(401)
            ->assertSee(__('Algoritma token SSO tidak diizinkan.'));

        $this->assertSame(0, User::count());
    }

    public function test_wrong_issuer_or_audience_is_rejected(): void
    {
        $this->launch($this->payload(['iss' => 'evil']))->assertStatus(401);
        $this->launch($this->payload(['aud' => 'other-app']))->assertStatus(401);

        $this->assertSame(0, User::count());
    }

    public function test_missing_required_claim_is_rejected(): void
    {
        $payload = $this->payload();
        unset($payload['jti']);

        $this->get(route('cxp.launch', ['token' => $this->sign($payload)]))
            ->assertStatus(401)
            ->assertSee(__('Token SSO tidak lengkap.'));

        $this->assertSame(0, User::count());
    }

    public function test_replayed_jti_is_rejected(): void
    {
        $token = $this->sign($this->payload());

        $this->get(route('cxp.launch', ['token' => $token]))->assertRedirect(route('dashboard'));
        $this->get(route('cxp.launch', ['token' => $token]))
            ->assertStatus(401)
            ->assertSee(__('Token SSO sudah pernah digunakan. Silakan buka ulang dari TransCXP.'));

        $this->assertSame(1, User::where('external_source', 'transcxp')->count());
    }

    public function test_email_conflict_with_admin_account_is_rejected_without_linking(): void
    {
        User::create([
            'name' => 'Admin Lama',
            'email' => 'operator@cxp.test',
            'password' => 'TestOnly!12345',
            'role' => 'admin',
        ]);

        $this->launch($this->payload())
            ->assertStatus(409)
            ->assertSee('sudah dipakai akun lain');

        $admin = User::where('email', 'operator@cxp.test')->sole();
        $this->assertSame('admin', $admin->role);
        $this->assertNull($admin->external_id);
        $this->assertSame(0, Workspace::where('code', 'brand-a')->count());
    }

    public function test_existing_external_account_with_non_operation_role_is_rejected(): void
    {
        User::create([
            'name' => 'Admin CXP',
            'email' => 'operator@cxp.test',
            'password' => 'TestOnly!12345',
            'role' => 'admin',
            'external_source' => 'transcxp',
            'external_id' => 'cx-user-1',
        ]);

        $this->launch($this->payload())
            ->assertStatus(403)
            ->assertSee(__('Akun SSO ini bukan akun operation.'));
    }

    public function test_operation_account_cannot_use_password_login(): void
    {
        User::create([
            'name' => 'Operator',
            'email' => 'operator@example.test',
            'password' => 'TestOnly!12345',
            'role' => 'operation',
        ]);

        $this->post('/login', [
            'email' => 'operator@example.test',
            'password' => 'TestOnly!12345',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_password_login_still_works_for_admin(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => 'TestOnly!12345',
            'role' => 'admin',
        ]);

        $this->post('/login', [
            'email' => $admin->email,
            'password' => 'TestOnly!12345',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_missing_token_is_rejected(): void
    {
        $this->get(route('cxp.launch'))->assertStatus(401)->assertSee(__('Token SSO tidak ditemukan.'));
    }
}
