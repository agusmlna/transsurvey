<?php

namespace Tests\Feature;

use App\Models\{Client, Invitation, Survey, User, Workspace};
use App\Services\{CurrentWorkspace, ResponseService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CsatTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private User $operation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::create(['name' => 'Brand A', 'code' => 'brand-a']);
        $this->operation = $this->user('operation', $this->workspace);
    }

    private function user(string $role, ?Workspace $workspace = null): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role . Str::random(4) . '@example.test',
            'password' => 'OnlyTesting123!',
            'role' => $role,
        ]);

        if ($workspace) {
            $user->workspaces()->attach($workspace);
        }

        return $user;
    }

    private function asOperation(?Workspace $workspace = null): self
    {
        return $this->actingAs($this->operation)
            ->withSession([CurrentWorkspace::SESSION_KEY => ($workspace ?? $this->workspace)->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'title' => 'CSAT Brand A',
            'description' => 'Bantu kami meningkatkan layanan.',
            'status' => 'active',
            'questions' => [
                [
                    'text' => 'Seberapa puas Anda dengan layanan kami?',
                    'category' => 'Kepuasan',
                    'type' => 'rating',
                    'required' => '1',
                    'options' => '',
                ],
                [
                    'text' => 'Apakah masalah Anda sudah diselesaikan?',
                    'category' => 'Penyelesaian',
                    'type' => 'choice',
                    'required' => '1',
                    'options' => "Ya\nTidak",
                ],
            ],
        ], $overrides);
    }

    private function template(array $attributes = []): Survey
    {
        $workspaceId = $attributes['workspace_id'] ?? $this->workspace->id;
        unset($attributes['workspace_id']);

        $survey = new Survey(array_merge([
            'title' => 'Template CSAT',
            'type' => 'csat',
            'status' => 'active',
            'starts_at' => today(),
            'ends_at' => null,
            'is_template' => true,
            'version' => 1,
            'created_by' => $this->operation->id,
        ], $attributes));
        $survey->workspace_id = $workspaceId;
        $survey->save();

        return $survey;
    }

    public function test_operation_creates_csat_template_in_its_workspace(): void
    {
        $response = $this->asOperation()->post(route('csat.templates.store'), $this->payload());

        $template = Survey::where('type', 'csat')->sole();
        $response->assertRedirect(route('csat.templates.edit', $template));

        $this->assertSame($this->workspace->id, $template->workspace_id);
        $this->assertTrue($template->is_template);
        $this->assertSame(today()->toDateString(), $template->starts_at->toDateString());
        $this->assertNull($template->ends_at);
        $this->assertSame(2, $template->questions()->count());
        $this->assertSame($template->id, $this->workspace->fresh()->default_csat_survey_id);
    }

    public function test_operation_can_update_and_duplicate_a_template(): void
    {
        $template = $this->template();
        $template->questions()->create([
            'text' => 'Seberapa puas Anda?',
            'category' => 'Kepuasan',
            'type' => 'rating',
            'required' => true,
            'position' => 1,
        ]);

        $this->asOperation()
            ->put(route('csat.templates.update', $template), $this->payload([
                'version' => 1,
                'title' => 'CSAT Diperbarui',
            ]))
            ->assertRedirect();

        $this->assertSame('CSAT Diperbarui', $template->fresh()->title);
        $this->assertSame(2, $template->fresh()->questions()->count());

        $this->asOperation()
            ->post(route('csat.templates.duplicate', $template))
            ->assertRedirect();

        $copy = Survey::where('title', 'like', 'CSAT Diperbarui (salinan)%')->sole();
        $this->assertSame('draft', $copy->status);
        $this->assertSame($this->workspace->id, $copy->workspace_id);
        $this->assertSame(2, $copy->questions()->count());
    }

    public function test_stale_version_is_rejected(): void
    {
        $template = $this->template(['version' => 2]);

        $this->asOperation()
            ->put(route('csat.templates.update', $template), $this->payload(['version' => 1]))
            ->assertSessionHasErrors('survey');

        $this->assertSame(2, $template->fresh()->version);
    }

    public function test_only_operation_and_superadmin_can_open_csat_templates(): void
    {
        $admin = $this->user('admin');
        $viewer = $this->user('viewer', $this->workspace);

        $this->actingAs($admin)->withSession([CurrentWorkspace::SESSION_KEY => $this->workspace->id])
            ->get(route('csat.templates.index'))->assertForbidden();

        $this->actingAs($viewer)->withSession([CurrentWorkspace::SESSION_KEY => $this->workspace->id])
            ->get(route('csat.templates.index'))->assertForbidden();
    }

    public function test_operation_cannot_open_another_workspace_template(): void
    {
        $other = Workspace::create(['name' => 'Brand B', 'code' => 'brand-b']);
        $otherTemplate = $this->template([
            'workspace_id' => $other->id,
            'title' => 'Rahasia Brand B',
        ]);

        $this->asOperation()
            ->get(route('csat.templates.edit', $otherTemplate))
            ->assertNotFound();

        $this->asOperation()
            ->get(route('csat.templates.index'))
            ->assertOk()
            ->assertDontSee('Rahasia Brand B');
    }

    public function test_superadmin_must_choose_a_workspace(): void
    {
        $superadmin = $this->user('superadmin');

        $this->actingAs($superadmin)
            ->get(route('csat.templates.index'))
            ->assertForbidden();

        $this->actingAs($superadmin)
            ->withSession([CurrentWorkspace::SESSION_KEY => $this->workspace->id])
            ->get(route('csat.templates.index'))
            ->assertOk()
            ->assertSee('Brand A');
    }

    public function test_set_default_updates_the_workspace(): void
    {
        $first = $this->template(['title' => 'Pertama']);
        $second = $this->template(['title' => 'Kedua']);

        $this->workspace->default_csat_survey_id = $first->id;
        $this->workspace->save();

        $this->asOperation()
            ->post(route('csat.templates.default', $second))
            ->assertRedirect();

        $this->assertSame($second->id, $this->workspace->fresh()->default_csat_survey_id);
    }

    public function test_old_survey_pages_do_not_list_csat_templates(): void
    {
        $this->template(['title' => 'Rahasia CSAT']);
        $superadmin = $this->user('superadmin');

        $this->actingAs($superadmin)
            ->withSession([CurrentWorkspace::SESSION_KEY => $this->workspace->id]);

        foreach (['surveys.index', 'dashboard', 'reports.index', 'responses.index'] as $route) {
            $this->get(route($route))->assertOk()->assertDontSee('Rahasia CSAT');
        }
    }

    public function test_operation_can_open_the_template_editor_and_settings_pages(): void
    {
        $this->asOperation()
            ->get(route('csat.templates.create'))
            ->assertOk()
            ->assertSee('Buat template CSAT')
            ->assertSee('Skala 1–5')
            ->assertDontSee('Tanggal selesai');

        $template = $this->template();
        $this->asOperation()
            ->get(route('csat.templates.edit', $template))
            ->assertOk()
            ->assertSee('Edit template CSAT');

        $this->asOperation()
            ->get(route('csat.settings.edit'))
            ->assertOk()
            ->assertSee('Masa berlaku tautan')
            ->assertSee('Tanpa batas waktu');
    }

    public function test_expiry_settings_support_minutes_hours_days_and_unlimited(): void
    {
        $this->workspace->settings = ['other_setting' => 'dipertahankan'];
        $this->workspace->save();

        foreach (['minutes' => 30, 'hours' => 24, 'days' => 7] as $unit => $value) {
            $this->asOperation()
                ->put(route('csat.settings.update'), [
                    'expiry_unit' => $unit,
                    'expiry_value' => $value,
                ])
                ->assertRedirect();

            $settings = $this->workspace->fresh()->settings;
            $this->assertSame($unit, $settings['csat']['expiry_unit']);
            $this->assertSame($value, $settings['csat']['expiry_value']);
            $this->assertSame('dipertahankan', $settings['other_setting']);
        }

        $this->asOperation()
            ->put(route('csat.settings.update'), ['expiry_unit' => 'unlimited'])
            ->assertRedirect();

        $settings = $this->workspace->fresh()->settings;
        $this->assertSame('unlimited', $settings['csat']['expiry_unit']);
        $this->assertNull($settings['csat']['expiry_value']);
    }

    public function test_invalid_expiry_unit_is_rejected(): void
    {
        $this->asOperation()
            ->put(route('csat.settings.update'), [
                'expiry_unit' => 'weeks',
                'expiry_value' => 2,
            ])
            ->assertSessionHasErrors('expiry_unit');
    }

    public function test_csat_preview_does_not_render_stars(): void
    {
        $template = $this->template();
        $template->questions()->create([
            'text' => 'Seberapa puas Anda?',
            'category' => 'Kepuasan',
            'type' => 'rating',
            'required' => true,
            'position' => 1,
        ]);

        $this->asOperation()
            ->get(route('csat.templates.preview', $template))
            ->assertOk()
            ->assertSee('Seberapa puas Anda?')
            ->assertSee('Sangat puas')
            ->assertDontSee('ts-rating-star');
    }

    public function test_low_rating_comment_is_optional_for_csat(): void
    {
        $template = $this->template();
        $question = $template->questions()->create([
            'text' => 'Seberapa puas Anda?',
            'category' => 'Kepuasan',
            'type' => 'rating',
            'required' => true,
            'position' => 1,
        ]);

        $client = new Client([
            'name' => 'Klien CSAT',
            'contact' => 'PIC',
            'email' => 'csat@example.test',
            'project' => 'Proyek CSAT',
            'active' => true,
        ]);
        $client->workspace_id = $this->workspace->id;
        $client->save();

        $token = Str::random(64);
        $invitation = Invitation::create([
            'survey_id' => $template->id,
            'client_id' => $client->id,
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'recipient_name' => 'PIC',
            'recipient_email' => $client->email,
        ]);

        $response = app(ResponseService::class)->save($invitation, [
            'answers' => [
                $question->id => ['value' => '2', 'comment' => ''],
            ],
        ]);

        $this->assertNotNull($response);
        $this->assertSame(2.0, (float) $response->score);
    }
}
