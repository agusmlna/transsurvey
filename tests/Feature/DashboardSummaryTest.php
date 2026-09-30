<?php

namespace Tests\Feature;

use App\Models\{Client, Invitation, Survey, SurveyResponse, User};
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function survey(array $attributes = []): Survey
    {
        $user = User::first() ?? User::create([
            'name' => 'Dashboard QA', 'email' => 'dashboard@example.test',
            'password' => 'TestOnly!12345', 'role' => 'admin',
        ]);
        return Survey::create(array_merge([
            'title' => 'Dashboard QA', 'status' => 'active',
            'starts_at' => today(), 'ends_at' => today(), 'created_by' => $user->id,
        ], $attributes));
    }

    private function invite(Survey $survey, string $project = 'IT', bool $demo = false, ?Client $client = null): Invitation
    {
        $client ??= Client::create([
            'name' => 'Client QA', 'contact' => 'PIC QA', 'email' => 'pic@example.test',
            'project' => $project, 'active' => true, 'is_demo' => $demo,
        ]);
        $token = Str::random(64);
        return Invitation::create([
            'survey_id' => $survey->id, 'client_id' => $client->id,
            'token' => $token, 'token_hash' => hash('sha256', $token),
            'recipient_name' => 'PIC QA', 'recipient_email' => 'pic@example.test', 'is_demo' => $demo,
        ]);
    }

    private function respond(Invitation $invitation, array $values, ?string $submitted = null): void
    {
        $invitation->update(['completed_at' => now()]);
        $response = SurveyResponse::create([
            'invitation_id' => $invitation->id, 'survey_id' => $invitation->survey_id,
            'client_id' => $invitation->client_id, 'submitted_at' => $submitted ?? now(), 'score' => 4,
        ]);
        foreach ($values as $position => $item) {
            [$type, $value] = is_array($item) ? $item : ['rating', $item];
            $question = $invitation->survey->questions()->create([
                'text' => 'QA question', 'category' => 'Service', 'type' => $type,
                'required' => false, 'position' => $position + 1,
            ]);
            $response->answers()->create([
                'question_id' => $question->id, 'question_text' => 'QA question',
                'category' => 'Service', 'type' => $type, 'value' => $value,
                'comment' => $type === 'rating' && in_array((string) $value, ['1', '2', '3'], true) ? 'Needs improvement' : null,
            ]);
        }
    }

    private function summary(array $filters = []): array
    {
        return app(ReportService::class)->summary(Request::create('/', 'GET', $filters));
    }

    public function test_active_surveys_respect_selection_and_count_surveys_once(): void
    {
        $a = $this->survey();
        $one = $this->invite($a);
        $this->invite($a);
        $b = $this->survey();
        $this->invite($b, 'HR', true);
        $this->assertSame(2, $this->summary()['active']);
        $this->assertSame(1, $this->summary(['survey_id' => $a->id])['active']);
        $this->assertSame(1, $this->summary(['client_id' => $one->client_id, 'project' => 'IT', 'source' => 'real'])['active']);
        $this->assertSame(0, $this->summary(['client_id' => $one->client_id, 'project' => 'HR'])['active']);
        $this->assertSame(1, $this->summary(['source' => 'demo'])['active']);
        $this->assertSame(1, $this->summary(['source' => 'real'])['active']);
        $this->assertSame(0, $this->summary(['project' => 'Missing'])['active']);
    }

    public function test_assignment_filters_must_match_the_same_invitation(): void
    {
        $survey = $this->survey();
        $one = $this->invite($survey, 'IT', false);
        $this->invite($survey, 'IT', true);
        $this->assertSame(0, $this->summary(['client_id' => $one->client_id, 'source' => 'demo'])['active']);
    }

    public function test_active_status_uses_today_not_response_filter_dates(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(12, 0));
        $today = $this->survey();
        $this->invite($today);
        foreach ([
            ['status' => 'draft'], ['status' => 'closed'],
            ['starts_at' => today()->addDay(), 'ends_at' => today()->addWeek()],
            ['starts_at' => today()->subWeek(), 'ends_at' => today()->subDay()],
        ] as $attributes) {
            $inactive = $this->survey($attributes);
            $this->assertSame(0, $this->summary(['survey_id' => $inactive->id])['active']);
        }
        $this->assertSame(1, $this->summary(['from' => '2000-01-01', 'to' => '2000-01-02', 'source' => 'real'])['active']);
        $this->travelBack();
    }

    public function test_feedback_counts_individual_valid_ratings_even_without_comments(): void
    {
        $invitation = $this->invite($this->survey());
        $this->respond($invitation, ['1', '2', '3', '4', '5', ['text', '5'], null, '', '0', '6']);
        $draft = $this->invite($invitation->survey);
        $draft->update(['draft_answers' => ['fake' => ['value' => '1']]]);
        $summary = $this->summary();
        $this->assertSame(['total' => 5, 'high' => 2, 'attention' => 3, 'highPercent' => 40.0, 'attentionPercent' => 60.0], $summary['feedback']);
        $this->assertCount(1, $summary['responses']);
        $this->assertEquals(4, $summary['average']);
        $this->assertEquals(50, $summary['rate']);
    }

    public function test_feedback_follows_all_response_filters_and_handles_no_data(): void
    {
        $a = $this->survey();
        $first = $this->invite($a);
        $this->respond($first, ['5'], '2026-09-15 12:00:00');
        $this->respond($this->invite($a, 'HR', true), ['1', '2'], '2026-09-16 12:00:00');
        $this->respond($this->invite($this->survey()), ['3'], '2026-09-15 12:00:00');
        foreach ([
            ['client_id' => $first->client_id],
            ['survey_id' => $a->id, 'project' => 'IT'],
            ['survey_id' => $a->id, 'source' => 'real'],
            ['survey_id' => $a->id, 'from' => '2026-09-15', 'to' => '2026-09-15'],
        ] as $filters) {
            $this->assertSame(1, $this->summary($filters)['feedback']['total']);
            $this->assertSame(1, $this->summary($filters)['feedback']['high']);
        }
        $empty = $this->summary(['from' => '2000-01-01', 'to' => '2000-01-02'])['feedback'];
        $this->assertSame(0, $empty['total']);
        $this->assertNull($empty['highPercent']);
        $this->assertNull($empty['attentionPercent']);
    }

    public function test_dashboard_renders_summary_and_keeps_filter_in_details_link(): void
    {
        $invitation = $this->invite($this->survey());
        $this->respond($invitation, ['5', '2']);
        $this->actingAs(User::first())->get('/?survey_id='.$invitation->survey_id.'&source=real')
            ->assertOk()->assertSee('Ringkasan penilaian')->assertSee('Penilaian tinggi')
            ->assertSee('Perlu perhatian')->assertSee('Total 2 jawaban rating')
            ->assertSee(route('responses.index', ['survey_id' => $invitation->survey_id, 'source' => 'real']));
        $this->get('/?from=2000-01-01&to=2000-01-02')->assertOk()
            ->assertSee('Belum ada jawaban rating pada filter ini.');
    }
}
