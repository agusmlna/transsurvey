<?php

namespace Tests\Feature;

use App\Jobs\SendSurveyCompletionEmail;
use App\Models\{AdminSurveyNotification, BankQuestion, Client, FollowUp, Invitation, Survey, SurveyResponse, User, Workspace};
use App\Services\{CurrentWorkspace, ResponseService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Mail, Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'OnlyTesting123!', 'role' => 'admin']);
    }

    private function user(string $role, ?Workspace $workspace = null): User
    {
        $user = User::create(['name' => ucfirst($role), 'email' => $role.Str::random(4).'@example.test', 'password' => 'OnlyTesting123!', 'role' => $role]);
        if ($workspace) $user->workspaces()->attach($workspace);
        return $user;
    }

    /** Creates a survey, client, invitation and a low-score response inside one workspace. */
    private function data(?Workspace $workspace, string $label): array
    {
        $id = $workspace?->id ?? app(CurrentWorkspace::class)->legacyId();
        $survey = new Survey(['title' => 'Survei '.$label, 'status' => 'active', 'starts_at' => today()->subDay(), 'ends_at' => today()->addWeek(), 'created_by' => $this->admin->id]);
        $survey->workspace_id = $id;
        $survey->save();
        $question = $survey->questions()->create(['text' => 'Puas?', 'category' => 'Kategori '.$label, 'type' => 'rating', 'required' => true, 'position' => 1]);
        $client = new Client(['name' => 'Klien '.$label, 'contact' => 'PIC '.$label, 'email' => Str::lower($label).'@example.test', 'project' => 'Proyek '.$label, 'active' => true]);
        $client->workspace_id = $id;
        $client->save();
        $token = Str::random(64);
        $invitation = Invitation::create(['survey_id' => $survey->id, 'client_id' => $client->id, 'token' => $token, 'token_hash' => hash('sha256', $token), 'recipient_name' => 'PIC '.$label, 'recipient_email' => $client->email]);
        $response = app(ResponseService::class)->save($invitation, ['answers' => [$question->id => ['value' => '2', 'comment' => 'Kurang '.$label]]]);
        return compact('survey', 'client', 'invitation', 'response', 'token');
    }

    public function test_migration_puts_existing_rows_in_the_legacy_workspace(): void
    {
        $legacy = Workspace::where('is_legacy', true)->sole();
        $this->assertSame('legacy', $legacy->code);
        $old = $this->data(null, 'Lama');
        foreach (['survey', 'client', 'invitation', 'response'] as $key) {
            $this->assertSame($legacy->id, $old[$key]->fresh()->workspace_id, $key);
        }
        $this->assertSame('', $old['invitation']->fresh()->ticket_id);
    }

    public function test_operation_only_sees_its_workspace_everywhere(): void
    {
        $a = Workspace::create(['name' => 'Brand A', 'code' => 'brand-a']);
        $b = Workspace::create(['name' => 'Brand B', 'code' => 'brand-b']);
        $dataA = $this->data($a, 'Alpha');
        $dataB = $this->data($b, 'Beta');
        $old = $this->data(null, 'Lama');

        $this->actingAs($this->user('operation', $a))->withSession([CurrentWorkspace::SESSION_KEY => $a->id]);
        $this->get(route('dashboard'))->assertOk()->assertSee('Brand A')->assertSee('Klien Alpha')->assertDontSee('Klien Beta')->assertDontSee('Klien Lama');
        $this->get(route('responses.show', $dataA['response']))->assertOk();
        $this->get(route('responses.show', $dataB['response']))->assertNotFound();
        $this->get(route('responses.show', $old['response']))->assertNotFound();
        $this->get(route('reports.index'))->assertOk()->assertSee('Klien Alpha')->assertDontSee('Klien Beta')->assertDontSee('Kategori Beta');
        $this->get(route('reports.index', ['client_id' => $dataB['client']->id]))->assertSessionHasErrors('client_id');
        $csv = $this->get(route('reports.csv'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Klien Alpha', $csv);
        $this->assertStringNotContainsString('Klien Beta', $csv);
        $this->assertStringNotContainsString('Klien Lama', $csv);
        $this->get(route('followups.index'))->assertOk();
        $rows = $this->getJson(route('responses.index', ['_table' => 1, 'draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();
        $this->assertSame(1, $rows->json('recordsTotal'));
        $rows = $this->getJson(route('followups.index', ['_table' => 1, 'draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();
        $this->assertSame(1, $rows->json('recordsTotal'));
        $this->assertStringContainsString('Klien Alpha', $rows->json('html'));
    }

    public function test_operation_without_a_workspace_sees_nothing(): void
    {
        $this->data(null, 'Lama');
        $this->actingAs($this->user('operation'));
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Klien Lama');
        $rows = $this->getJson(route('responses.index', ['_table' => 1, 'draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();
        $this->assertSame(0, $rows->json('recordsTotal'));
        // Another workspace chosen in the session does not help a non-member.
        $other = Workspace::create(['name' => 'Brand X', 'code' => 'brand-x']);
        $this->data($other, 'Xray');
        $this->withSession([CurrentWorkspace::SESSION_KEY => $other->id])->get(route('dashboard'))->assertDontSee('Klien Xray');
    }

    public function test_admin_only_sees_legacy_data_and_creates_into_legacy(): void
    {
        $a = Workspace::create(['name' => 'Brand A', 'code' => 'brand-a']);
        $dataA = $this->data($a, 'Alpha');
        $old = $this->data(null, 'Lama');
        $this->actingAs($this->admin)->withSession([CurrentWorkspace::SESSION_KEY => $a->id]);
        $this->get(route('clients.index'))->assertOk()->assertSee('Klien Lama')->assertDontSee('Klien Alpha');
        $this->get(route('surveys.edit', $dataA['survey']))->assertNotFound();
        $this->get(route('surveys.edit', $old['survey']))->assertOk();
        $this->get(route('bank.index'))->assertOk();
        $this->post(route('clients.store'), ['name' => 'Baru', 'contact' => 'PIC', 'email' => 'baru@example.test', 'project' => 'P', 'active' => 1, 'workspace_id' => $a->id])->assertRedirect();
        $this->assertSame(app(CurrentWorkspace::class)->legacyId(), Client::withoutGlobalScopes()->where('name', 'Baru')->value('workspace_id'));
        $this->post(route('invitations.store'), ['survey_id' => $dataA['survey']->id, 'client_ids' => [$old['client']->id]])->assertSessionHasErrors('survey_id');
    }

    public function test_superadmin_switches_between_workspaces_and_all(): void
    {
        $a = Workspace::create(['name' => 'Brand A', 'code' => 'brand-a']);
        $this->data($a, 'Alpha');
        $this->data(null, 'Lama');
        $this->actingAs($this->user('superadmin'));
        $this->get(route('clients.index'))->assertSee('Klien Alpha')->assertSee('Klien Lama')->assertSee('Semua workspace');
        $this->post(route('workspace.switch'), ['workspace_id' => $a->id])->assertRedirect(route('dashboard'));
        $this->get(route('clients.index'))->assertSee('Klien Alpha')->assertDontSee('Klien Lama');
        $this->post(route('clients.store'), ['name' => 'Baru A', 'contact' => 'PIC', 'email' => 'a@example.test', 'project' => 'P', 'active' => 1])->assertRedirect();
        $this->assertSame($a->id, Client::withoutGlobalScopes()->where('name', 'Baru A')->value('workspace_id'));
        $this->post(route('workspace.switch'), ['workspace_id' => ''])->assertRedirect();
        $this->get(route('clients.index'))->assertSee('Klien Lama')->assertSee('Klien Alpha');
        $this->actingAs($this->admin)->post(route('workspace.switch'), ['workspace_id' => $a->id])->assertForbidden();
    }

    public function test_data_cleanup_cannot_delete_another_workspace(): void
    {
        $a = Workspace::create(['name' => 'Brand A', 'code' => 'brand-a']);
        $dataA = $this->data($a, 'Alpha');
        $this->actingAs($this->admin)
            ->post(route('data-cleanup.preview'), ['type' => 'invitations', 'ids' => [$dataA['invitation']->id], 'reason' => 'Uji isolasi data'])
            ->assertSessionHasErrors('cleanup');
        $this->assertNotNull(Invitation::withoutGlobalScopes()->find($dataA['invitation']->id));
    }

    public function test_public_link_and_jobs_work_across_workspaces(): void
    {
        $a = Workspace::create(['name' => 'Brand A', 'code' => 'brand-a']);
        $dataA = $this->data($a, 'Alpha');
        $this->assertSame($a->id, $dataA['response']->workspace_id);
        $this->assertSame(1, FollowUp::withoutGlobalScopes()->where('survey_response_id', $dataA['response']->id)->count());
        // The thank-you page loads the invitation by token without any login.
        $this->get(route('survey.public', $dataA['token']))->assertOk()->assertSee(__('Terima kasih'), false);
        // Completion email is for legacy client surveys only.
        $this->assertSame(0, AdminSurveyNotification::where('survey_response_id', $dataA['response']->id)->count());
    }

    public function test_new_workspace_gets_default_bank_and_client_surveys_keep_one_invitation_per_client(): void
    {
        $a = Workspace::create(['name' => 'Brand A', 'code' => 'brand-a']);
        $this->assertGreaterThan(0, BankQuestion::withoutGlobalScopes()->where('workspace_id', $a->id)->count());
        $old = $this->data(null, 'Lama');
        $this->expectException(\Illuminate\Database\QueryException::class);
        Invitation::create(['survey_id' => $old['survey']->id, 'client_id' => $old['client']->id, 'token' => Str::random(64), 'token_hash' => Str::random(64), 'recipient_name' => 'X', 'recipient_email' => 'x@example.test']);
    }

    public function test_one_customer_can_have_an_invitation_per_ticket(): void
    {
        $old = $this->data(null, 'Lama');
        foreach (['T-1', 'T-2'] as $ticket) {
            Invitation::create(['survey_id' => $old['survey']->id, 'client_id' => $old['client']->id, 'ticket_id' => $ticket, 'token' => Str::random(64), 'token_hash' => Str::random(64), 'recipient_name' => 'X', 'recipient_email' => 'x@example.test']);
        }
        $this->assertSame(3, Invitation::where('client_id', $old['client']->id)->count());
    }
}
