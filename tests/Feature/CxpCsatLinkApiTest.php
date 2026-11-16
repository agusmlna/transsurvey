<?php

namespace Tests\Feature;

use App\Models\{Client, EmailDelivery, Invitation, Survey, User, Workspace};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Queue};
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CxpCsatLinkApiTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cxp.launch_api_key' => 'test-link-key',
            'cxp.external_source' => 'transcxp',
        ]);

        Queue::fake();
        Cache::flush();

        $this->workspace = Workspace::create([
            'name' => 'DIPOOPL',
            'code' => '6',
            'settings' => ['csat' => ['expiry_unit' => 'days', 'expiry_value' => 7]],
        ]);
        $this->workspace->default_csat_survey_id = $this->template($this->workspace)->id;
        $this->workspace->save();
    }

    private function template(Workspace $workspace): Survey
    {
        $user = User::create([
            'name' => 'Operator',
            'email' => 'operator'.uniqid().'@example.test',
            'password' => 'OnlyTesting123!',
            'role' => 'operation',
        ]);

        $template = new Survey([
            'title' => 'Survei Kepuasan Layanan',
            'description' => 'Bantu kami meningkatkan layanan.',
            'type' => 'csat',
            'status' => 'active',
            'starts_at' => today(),
            'ends_at' => null,
            'is_template' => true,
            'version' => 1,
            'created_by' => $user->id,
        ]);
        $template->workspace_id = $workspace->id;
        $template->save();

        $template->questions()->create([
            'text' => 'Seberapa puas Anda dengan penanganan tiket ini?',
            'category' => 'Kepuasan',
            'type' => 'rating',
            'required' => true,
            'position' => 1,
        ]);

        return $template;
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'user' => [
                'id' => 'agent-1',
                'name' => 'Agent A',
                'email' => 'agent@cxp.test',
            ],
            'project' => [
                'code' => '6',
                'name' => 'DIPOOPL',
            ],
            'ticket' => [
                'id' => '075750',
                'status' => 'Close',
                'channel' => 'chat',
                'customer' => [
                    'name' => 'Sari',
                    'email' => 'sari@example.test',
                    'wa' => '081234567890',
                ],
            ],
        ], $overrides);
    }

    private function requestLink(array $payload, ?string $apiKey = 'test-link-key'): TestResponse
    {
        $headers = $apiKey === null ? [] : ['Authorization' => 'Bearer '.$apiKey];

        return $this->postJson('/api/cxp/csat-links', $payload, $headers);
    }

    public function test_link_is_issued_without_sending_email(): void
    {
        $response = $this->requestLink($this->payload());

        $response->assertCreated()
            ->assertJsonPath('project', '6')
            ->assertJsonPath('ticket_id', '075750')
            ->assertJsonPath('created', true)
            ->assertJsonStructure(['project', 'ticket_id', 'survey_id', 'url', 'expires_at', 'created']);

        $this->assertStringContainsString('/s/', (string) $response->json('url'));
        $this->assertNotNull($response->json('expires_at'));

        $invitation = Invitation::withoutGlobalScopes()->sole();
        $this->assertSame('sari@example.test', $invitation->recipient_email);
        $this->assertSame('6281234567890', $invitation->client->wa);
        $this->assertNull($invitation->sent_at);
        $this->assertSame($response->json('url'), $invitation->surveyUrl());

        // Jalur manual TransCXP tidak boleh mengantrekan email.
        $this->assertSame(0, EmailDelivery::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_same_ticket_reuses_the_same_link(): void
    {
        $first = $this->requestLink($this->payload())->assertCreated();
        $second = $this->requestLink($this->payload())
            ->assertOk()
            ->assertJsonPath('created', false);

        $this->assertSame($first->json('url'), $second->json('url'));
        $this->assertSame(1, Invitation::withoutGlobalScopes()->count());
    }

    public function test_expired_link_is_rotated(): void
    {
        $first = $this->requestLink($this->payload())->assertCreated();

        $invitation = Invitation::withoutGlobalScopes()->sole();
        $invitation->forceFill(['expires_at' => now()->subMinute()])->save();

        $second = $this->requestLink($this->payload())
            ->assertOk()
            ->assertJsonPath('created', false);

        $this->assertNotSame($first->json('url'), $second->json('url'));
        $this->assertTrue($invitation->fresh()->expires_at->isFuture());
    }

    public function test_same_customer_can_have_a_link_per_ticket(): void
    {
        $first = $this->requestLink($this->payload())->assertCreated();

        $second = $this->requestLink($this->payload([
            'ticket' => ['id' => '075751'],
        ]))->assertCreated();

        $this->assertNotSame($first->json('url'), $second->json('url'));

        $invitations = Invitation::withoutGlobalScopes()->get();
        $this->assertCount(2, $invitations);
        $this->assertCount(1, $invitations->pluck('client_id')->unique());
    }

    public function test_ticket_scope_is_per_workspace(): void
    {
        $other = Workspace::create(['name' => 'Brand B', 'code' => '7']);
        $other->default_csat_survey_id = $this->template($other)->id;
        $other->save();

        $this->requestLink($this->payload([
            'project' => ['code' => '7', 'name' => 'Brand B'],
        ]))->assertCreated();

        $response = $this->requestLink($this->payload())->assertCreated();

        $this->assertSame(1, Invitation::withoutGlobalScopes()->where('workspace_id', $other->id)->count());
        $this->assertSame(1, Invitation::withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->count());

        $invitation = Invitation::withoutGlobalScopes()
            ->where('workspace_id', $this->workspace->id)
            ->sole();
        $this->assertSame($response->json('url'), $invitation->surveyUrl());
    }

    public function test_completed_ticket_cannot_get_a_link(): void
    {
        $this->requestLink($this->payload())->assertCreated();

        Invitation::withoutGlobalScopes()->sole()->forceFill([
            'completed_at' => now(),
        ])->save();

        $this->requestLink($this->payload())
            ->assertStatus(409)
            ->assertJsonPath('reason', 'csat_completed');
    }

    public function test_unknown_project_is_rejected_without_creating_workspace(): void
    {
        $before = Workspace::count();

        $this->requestLink($this->payload([
            'project' => ['code' => '999999', 'name' => 'Tidak Ada'],
        ]))
            ->assertNotFound()
            ->assertJsonPath('reason', 'workspace_not_found');

        $this->assertSame($before, Workspace::count());
    }

    public function test_workspace_without_default_template_is_rejected(): void
    {
        $empty = Workspace::create(['name' => 'Tanpa Template', 'code' => '8']);

        $this->requestLink($this->payload([
            'project' => ['code' => '8', 'name' => $empty->name],
        ]))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'template_not_ready');
    }

    public function test_ticket_and_customer_must_be_complete(): void
    {
        $payload = $this->payload();
        unset($payload['ticket']['customer']);

        $this->requestLink($payload)
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_claim');

        $this->assertSame(0, Invitation::withoutGlobalScopes()->count());
    }

    public function test_invalid_customer_whatsapp_is_rejected(): void
    {
        $this->requestLink($this->payload([
            'ticket' => ['customer' => ['wa' => 'bukan-nomor']],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_claim');
    }

    public function test_missing_or_wrong_api_key_is_rejected(): void
    {
        $this->requestLink($this->payload(), null)
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'bad_api_key');

        $this->requestLink($this->payload(), 'wrong-key')
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'bad_api_key');

        $this->assertSame(0, Invitation::withoutGlobalScopes()->count());
    }

    public function test_issued_link_opens_the_csat_survey(): void
    {
        $url = (string) $this->requestLink($this->payload())->assertCreated()->json('url');

        $this->get($url)
            ->assertOk()
            ->assertSee('Survei Kepuasan Layanan');
    }

    public function test_link_is_issued_without_email_using_whatsapp(): void
    {
        $response = $this->requestLink($this->payload([
            'ticket' => ['customer' => ['email' => null]],
        ]));

        $response->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonStructure(['url', 'expires_at']);

        $invitation = Invitation::withoutGlobalScopes()->sole();
        $this->assertNull($invitation->recipient_email);
        $this->assertSame('Sari', $invitation->recipient_name);
        $this->assertSame('6281234567890', $invitation->client->wa);
        $this->assertNull($invitation->client->email);

        // Jalur manual tetap tidak mengirim email walau customer punya nomor.
        $this->assertSame(0, EmailDelivery::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_whatsapp_only_ticket_reuses_link_and_client(): void
    {
        $payload = $this->payload([
            'ticket' => ['customer' => ['email' => null]],
        ]);

        $first = $this->requestLink($payload)->assertCreated();
        $second = $this->requestLink($payload)
            ->assertOk()
            ->assertJsonPath('created', false);

        $this->assertSame($first->json('url'), $second->json('url'));
        $this->assertSame(1, Invitation::withoutGlobalScopes()->count());
        $this->assertSame(1, Client::withoutGlobalScopes()->count());
    }

    public function test_link_is_issued_with_name_only_customer(): void
    {
        $this->requestLink($this->payload([
            'ticket' => ['customer' => ['email' => null, 'wa' => null]],
        ]))->assertCreated();

        $invitation = Invitation::withoutGlobalScopes()->sole();
        $this->assertNull($invitation->recipient_email);
        $this->assertSame('Sari', $invitation->recipient_name);
        $this->assertNull($invitation->client->email);
        $this->assertNull($invitation->client->wa);
    }

    public function test_ticket_without_any_customer_identity_is_rejected(): void
    {
        $this->requestLink($this->payload([
            'ticket' => ['customer' => ['email' => null, 'wa' => null, 'name' => null]],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_claim');

        $this->assertSame(0, Invitation::withoutGlobalScopes()->count());
        $this->assertSame(0, Client::withoutGlobalScopes()->count());
    }

    public function test_two_tickets_with_the_same_whatsapp_number_share_one_client(): void
    {
        $this->requestLink($this->payload([
            'ticket' => ['id' => 'WA-1', 'customer' => ['email' => null]],
        ]))->assertCreated();

        $this->requestLink($this->payload([
            'ticket' => ['id' => 'WA-2', 'customer' => ['email' => null]],
        ]))->assertCreated();

        $this->assertSame(2, Invitation::withoutGlobalScopes()->count());
        $this->assertSame(1, Client::withoutGlobalScopes()->count());
    }
}
