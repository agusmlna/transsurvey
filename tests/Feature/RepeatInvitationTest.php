<?php

namespace Tests\Feature;

use App\Models\{Client, Invitation, Survey, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepeatInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeat_option_creates_a_new_link_for_the_same_client_and_survey(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'OnlyTesting123!', 'role' => 'admin']);
        $this->actingAs($admin);
        $survey = Survey::create(['title' => 'Survei 2026', 'status' => 'active', 'starts_at' => today(), 'ends_at' => today()->addMonth(), 'created_by' => $admin->id]);
        $client = Client::create(['name' => 'PT. Dewi', 'contact' => 'Dewi', 'email' => 'dewi@example.test', 'project' => 'Testing', 'active' => true]);
        $payload = ['survey_id' => $survey->id, 'client_ids' => [$client->id]];

        $this->post(route('invitations.store'), $payload)->assertSessionHasNoErrors();
        // Without the option the existing invitation is skipped, as before.
        $this->post(route('invitations.store'), $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, Invitation::count());

        $this->post(route('invitations.store'), $payload + ['repeat' => 1])->assertSessionHasNoErrors();
        $this->post(route('invitations.store'), $payload + ['repeat' => 1])->assertSessionHasNoErrors();
        $invitations = Invitation::orderBy('id')->get();
        $this->assertSame(['', 'ulang-2', 'ulang-3'], $invitations->pluck('ticket_id')->all());
        $this->assertCount(3, $invitations->pluck('token_hash')->unique());

        $this->get(route('invitations.index'))->assertOk();
        $rows = $this->getJson(route('invitations.index', ['_table' => 1, 'draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();
        $this->assertStringContainsString('Tautan ke-3', $rows->json('html'));
        // Each link opens its own survey page.
        foreach ($invitations as $invitation) {
            $this->get($invitation->surveyUrl())->assertOk();
        }
    }
}
