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
            'cxp.require_iss_aud' => false,
            'cxp.api_token_id' => null,
            'cxp.replay_fallback' => false,
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

    /**
     * @return array{0: \OpenSSLAsymmetricKey, 1: string}
     */
    private function es256KeyPair(): array
    {
        // PHP 8.3 + OpenSSL 3 menolak keygen EC di bawah 384 bit, walau
        // curva yang dipakai tetap prime256v1 (P-256).
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
            'private_key_bits' => 384,
        ]);

        if ($key === false) {
            $this->markTestSkipped('OpenSSL tidak dapat membuat kunci EC di lingkungan ini.');
        }

        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);

        return [$key, $details['key']];
    }

    private function signEs256(array $payload, \OpenSSLAsymmetricKey $privateKey): string
    {
        $b64 = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $json = fn (array $value): string => $b64(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $unsigned = $json(['alg' => 'ES256', 'typ' => 'JWT']).'.'.$json($payload);

        $this->assertTrue(openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256));

        return $unsigned.'.'.$b64($this->derToRaw($signature));
    }

    private function derToRaw(string $der, int $size = 32): string
    {
        $pos = 0;
        $this->assertSame(0x30, ord($der[$pos++]));
        $length = ord($der[$pos++]);
        if ($length & 0x80) {
            $count = $length & 0x7f;
            $length = 0;
            while ($count-- > 0) {
                $length = ($length << 8) | ord($der[$pos++]);
            }
        }

        $readInteger = function () use ($der, &$pos): string {
            $this->assertSame(0x02, ord($der[$pos++]));
            $length = ord($der[$pos++]);
            if ($length & 0x80) {
                $count = $length & 0x7f;
                $length = 0;
                while ($count-- > 0) {
                    $length = ($length << 8) | ord($der[$pos++]);
                }
            }

            $value = substr($der, $pos, $length);
            $pos += $length;

            return ltrim($value, "\x00");
        };

        return str_pad($readInteger(), $size, "\x00", STR_PAD_LEFT)
            .str_pad($readInteger(), $size, "\x00", STR_PAD_LEFT);
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

    public function test_es256_token_with_configured_public_key_is_accepted(): void
    {
        [$privateKey, $publicKey] = $this->es256KeyPair();

        config([
            'cxp.algorithms' => ['ES256'],
            'cxp.sso_secret' => null,
            'cxp.sso_public_key' => $publicKey,
        ]);

        $this->get(route('cxp.launch', ['sso' => $this->signEs256($this->payload(), $privateKey)]))
            ->assertRedirect(route('dashboard'));

        $this->assertSame(1, User::where('external_source', 'transcxp')->count());
    }

    public function test_es256_token_with_wrong_signature_is_rejected(): void
    {
        [$privateKey] = $this->es256KeyPair();
        [, $otherPublicKey] = $this->es256KeyPair();

        config([
            'cxp.algorithms' => ['ES256'],
            'cxp.sso_secret' => null,
            'cxp.sso_public_key' => $otherPublicKey,
        ]);

        $this->get(route('cxp.launch', ['sso' => $this->signEs256($this->payload(), $privateKey)]))
            ->assertStatus(401)
            ->assertSee(__('Tanda tangan token SSO tidak valid.'));

        $this->assertSame(0, User::count());
    }

    public function test_token_in_sso_query_parameter_is_accepted(): void
    {
        $this->get(route('cxp.launch', ['sso' => $this->sign($this->payload())]))
            ->assertRedirect(route('dashboard'));
    }

    public function test_token_without_iss_and_aud_is_accepted_when_not_required(): void
    {
        $payload = $this->payload();
        unset($payload['iss'], $payload['aud']);

        config(['cxp.require_iss_aud' => false]);

        $this->get(route('cxp.launch', ['token' => $this->sign($payload)]))
            ->assertRedirect(route('dashboard'));
    }

    public function test_iss_and_aud_are_required_when_configured(): void
    {
        $payload = $this->payload();
        unset($payload['iss'], $payload['aud']);

        config(['cxp.require_iss_aud' => true]);

        $this->get(route('cxp.launch', ['token' => $this->sign($payload)]))
            ->assertStatus(401)
            ->assertSee(__('Token SSO tidak lengkap.'));
    }

    public function test_missing_jti_can_fall_back_to_token_hash_replay_guard(): void
    {
        $payload = $this->payload();
        unset($payload['jti']);

        config(['cxp.replay_fallback' => true]);

        $token = $this->sign($payload);

        $this->get(route('cxp.launch', ['token' => $token]))->assertRedirect(route('dashboard'));
        $this->get(route('cxp.launch', ['token' => $token]))
            ->assertStatus(401)
            ->assertSee(__('Token SSO sudah pernah digunakan. Silakan buka ulang dari TransCXP.'));
    }

    public function test_api_token_id_is_validated_when_configured(): void
    {
        config(['cxp.api_token_id' => '7']);

        $payload = $this->payload(['apiTokenId' => 8]);

        $this->get(route('cxp.launch', ['token' => $this->sign($payload)]))
            ->assertStatus(401)
            ->assertSee(__('Token SSO bukan untuk TransSurvey.'));
    }

    public function test_top_level_email_is_copied_to_user_email(): void
    {
        $payload = $this->payload();
        unset($payload['user']['email']);
        $payload['email'] = 'operator@cxp.test';

        $this->get(route('cxp.launch', ['token' => $this->sign($payload)]))
            ->assertRedirect(route('dashboard'));

        $this->assertSame(
            'operator@cxp.test',
            User::where('external_source', 'transcxp')->sole()->email
        );
    }

    public function test_missing_token_is_rejected(): void
    {
        $this->get(route('cxp.launch'))->assertStatus(401)->assertSee(__('Token SSO tidak ditemukan.'));
    }
}
