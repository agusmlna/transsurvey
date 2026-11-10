<?php

namespace Tests\Feature;

use App\Models\{User, Workspace};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CxpLaunchSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cxp.launch_api_key' => 'test-launch-key',
            'cxp.launch_code_ttl' => 120,
            'cxp.external_source' => 'transcxp',
            'cxp.redirect_route' => 'dashboard',
            'cxp.allow_operation_password' => false,
        ]);

        Cache::flush();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'user' => [
                'id' => 'cx-user-1',
                'name' => 'Operator CXP',
                'email' => 'operator@cxp.test',
            ],
            'project' => [
                'code' => 'brand-a',
                'name' => 'Brand A',
            ],
        ], $overrides);
    }

    private function requestSession(array $payload, ?string $apiKey = 'test-launch-key'): TestResponse
    {
        $headers = $apiKey === null ? [] : ['Authorization' => 'Bearer '.$apiKey];

        return $this->postJson('/api/cxp/launch-sessions', $payload, $headers);
    }

    public function test_launch_session_returns_single_use_code_and_logs_in(): void
    {
        $response = $this->requestSession($this->payload());

        $response->assertCreated()
            ->assertJsonStructure(['code', 'url', 'expires_in']);

        $code = (string) $response->json('code');

        $this->assertNotSame('', $code);
        $this->assertStringContainsString('/cxp/launch?code=', (string) $response->json('url'));

        $this->get(route('cxp.launch', ['code' => $code]))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertSame('cx-user-1', session('cxp.context.user.external_id'));
        $this->assertSame('brand-a', session('cxp.context.project.code'));
        $this->assertSame(1, Workspace::where('code', 'brand-a')->count());

        $this->get(route('cxp.launch', ['code' => $code]))
            ->assertStatus(401)
            ->assertSee(__('Kode peluncuran tidak valid atau sudah kedaluwarsa.'));
    }

    public function test_ticket_with_nested_customer_is_carried_into_session(): void
    {
        $payload = $this->payload([
            'ticket' => [
                'id' => 'TCK-9',
                'status' => 'open',
                'channel' => 'chat',
                'customer' => [
                    'id' => 'CUST-9',
                    'name' => 'Sari',
                    'email' => 'sari@example.test',
                    'wa' => '0812-3456-7890',
                ],
            ],
        ]);

        $code = (string) $this->requestSession($payload)->assertCreated()->json('code');

        $this->get(route('cxp.launch', ['code' => $code]))->assertRedirect(route('csat.send.index'));

        $this->assertSame('TCK-9', session('cxp.context.ticket.id'));
        $this->assertSame('Sari', session('cxp.context.customer.name'));
        $this->assertSame('sari@example.test', session('cxp.context.customer.email'));
        $this->assertSame('6281234567890', session('cxp.context.customer.wa'));
    }

    public function test_invalid_customer_whatsapp_is_rejected(): void
    {
        $payload = $this->payload([
            'ticket' => [
                'id' => 'TCK-9',
                'status' => 'open',
                'channel' => 'whatsapp',
                'customer' => [
                    'email' => 'sari@example.test',
                    'wa' => 'bukan-nomor',
                ],
            ],
        ]);

        $this->requestSession($payload)
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_claim');
    }

    public function test_invalid_api_key_is_rejected(): void
    {
        $this->requestSession($this->payload(), 'wrong-key')
            ->assertStatus(401)
            ->assertJsonPath('message', __('API key tidak valid.'));

        $this->assertSame(0, User::count());
    }

    public function test_missing_api_key_is_rejected(): void
    {
        $this->requestSession($this->payload(), null)
            ->assertStatus(401)
            ->assertJsonPath('message', __('API key tidak valid.'));
    }

    public function test_missing_launch_api_configuration_is_rejected(): void
    {
        config(['cxp.launch_api_key' => null]);

        $this->requestSession($this->payload())
            ->assertStatus(500)
            ->assertJsonPath('message', __('Konfigurasi API peluncuran belum lengkap.'));
    }

    public function test_missing_user_or_project_is_rejected_before_code_is_issued(): void
    {
        $payload = $this->payload();
        unset($payload['user']);

        $this->requestSession($payload)
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_claim');
    }

    public function test_ticket_without_customer_is_rejected_before_code_is_issued(): void
    {
        $payload = $this->payload([
            'ticket' => ['id' => 'TCK-1', 'status' => 'open', 'channel' => 'chat'],
        ]);

        $this->requestSession($payload)
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_claim');
    }

    public function test_unknown_or_expired_code_is_rejected(): void
    {
        $this->get(route('cxp.launch', ['code' => str_repeat('a', 64)]))
            ->assertStatus(401)
            ->assertSee(__('Kode peluncuran tidak valid atau sudah kedaluwarsa.'));
    }
}
