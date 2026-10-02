<?php

namespace Tests\Feature;

use App\Models\{Client, Invitation, Survey, SurveyResponse, User};
use App\Services\{ReportPresentation, ReportService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::create(['name' => 'Report QA', 'email' => 'report@example.test', 'password' => 'TestOnly!12345', 'role' => 'admin']);
        $survey = Survey::create(['title' => 'Testing survey', 'status' => 'active', 'starts_at' => today(), 'ends_at' => today()->addMonth(), 'created_by' => $user->id]);
        $client = Client::create(['name' => 'Testing 3', 'contact' => 'QA', 'email' => 'pic@example.test', 'project' => 'Outbond', 'active' => true]);
        $this->response($client, $survey);
        $other = Client::create(['name' => 'Excluded client', 'contact' => 'QA', 'email' => 'other@example.test', 'project' => 'Other', 'active' => true]);
        $this->response($other, $survey, true);
        $this->actingAs($user);
        return ['client_id' => $client->id, 'survey_id' => $survey->id, 'project' => 'Outbond', 'source' => 'real', 'from' => today()->format('Y-m-d'), 'to' => today()->format('Y-m-d')];
    }

    private function response(Client $client, Survey $survey, bool $demo = false): SurveyResponse
    {
        $token = Str::random(64);
        $invitation = Invitation::create(['survey_id' => $survey->id, 'client_id' => $client->id, 'token' => $token, 'token_hash' => hash('sha256', $token), 'recipient_name' => 'QA', 'recipient_email' => $client->email, 'is_demo' => $demo, 'completed_at' => now()]);
        $response = SurveyResponse::create(['survey_id' => $survey->id, 'client_id' => $client->id, 'invitation_id' => $invitation->id, 'submitted_at' => now(), 'score' => 4]);
        foreach (['Seberapa puas Anda terhadap kualitas layanan kami?', 'bagus gaa?'] as $position => $text) {
            $question = $survey->questions()->create(['text' => $text, 'category' => 'Kualitas layanan', 'type' => 'rating', 'required' => true, 'position' => $position + 1]);
            $response->answers()->create(['question_id' => $question->id, 'question_text' => $text, 'category' => 'Kualitas layanan', 'type' => 'rating', 'value' => '4']);
        }
        return $response;
    }

    public function test_downloads_require_login(): void
    {
        foreach (['reports.pdf', 'reports.xlsx', 'reports.csv'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_report_and_download_links_keep_the_applied_filters(): void
    {
        $filters = $this->fixture();
        $response = $this->get(route('reports.index', $filters))->assertOk();
        $response->assertSee('Testing 3')->assertDontSee('Excluded client</strong>', false);
        $response->assertSee('Kategori Kualitas layanan memperoleh skor 4,00.');
        $response->assertDontSee('kategori terendah');
        foreach (['reports.pdf', 'reports.xlsx', 'reports.csv'] as $route) {
            $response->assertSee(e(route($route, $filters)), false);
        }
        $data = app(ReportPresentation::class)->data(Request::create('/', 'GET', $filters), app(ReportService::class));
        $this->assertCount(1, $data['responses']);
        $this->assertEquals(4, $data['average']);
        $this->assertSame('Operasional', $data['reportFilters']['Sumber data']);
        $this->assertEquals(100, $data['rate']);
    }

    public function test_xlsx_is_a_real_workbook_with_typed_values_and_two_answer_rows(): void
    {
        $filters = $this->fixture();
        $response = $this->get(route('reports.xlsx', $filters))->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $book = IOFactory::load($path);
            $this->assertSame(['Ringkasan', 'Detail Jawaban'], $book->getSheetNames());
            $sheet = $book->getSheetByName('Detail Jawaban');
            $this->assertSame('Testing 3', $sheet->getCell('B15')->getValue());
            $this->assertSame($sheet->getCell('A15')->getValue(), $sheet->getCell('A16')->getValue());
            $this->assertSame('', (string) $sheet->getCell('A17')->getValue());
            $this->assertEquals(4, $sheet->getCell('F15')->getValue());
            $this->assertEquals(0.8, $sheet->getCell('G15')->getValue());
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('E15')->getDataType());
            $this->assertSame('dd/mm/yyyy hh:mm', $sheet->getCell('E15')->getStyle()->getNumberFormat()->getFormatCode());
            $this->assertSame('A14:L16', $sheet->getAutoFilter()->getRange());
            $this->assertSame('C15', $sheet->getFreezePane());
            $book->disconnectWorksheets();
        } finally { @unlink($path); }
    }

    public function test_excel_keeps_formula_like_user_text_as_text(): void
    {
        $filters = $this->fixture();
        $row = SurveyResponse::where('client_id', $filters['client_id'])->firstOrFail();
        $row->client->update(['name' => '=1+1']);
        $row->answers()->first()->update(['comment' => '=HYPERLINK("https://example.test","unsafe")']);
        $response = $this->get(route('reports.xlsx', $filters))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $book = IOFactory::load($path);
            $detail = $book->getSheetByName('Detail Jawaban');
            $this->assertSame('=1+1', $detail->getCell('B15')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $detail->getCell('B15')->getDataType());
            $this->assertSame(DataType::TYPE_STRING, $detail->getCell('L15')->getDataType());
            $this->assertStringStartsWith('=HYPERLINK', $detail->getCell('L15')->getValue());
            $book->disconnectWorksheets();
        } finally { @unlink($path); }
    }

    public function test_pdf_download_has_pdf_bytes_and_escaped_template_content(): void
    {
        $filters = $this->fixture();
        $response = $this->get(route('reports.pdf', $filters))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        Client::findOrFail($filters['client_id'])->update(['name' => '<script>alert(1)</script>']);
        $data = app(ReportPresentation::class)->data(Request::create('/', 'GET', $filters), app(ReportService::class));
        $html = view('reports.pdf', $data)->render();
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('Operasional', $html);
    }

    public function test_empty_results_are_explicit_and_pdf_and_excel_still_download(): void
    {
        $filters = $this->fixture();
        $filters['from'] = '2000-01-01'; $filters['to'] = '2000-01-02';
        $this->get(route('reports.index', $filters))->assertOk()->assertSee('0 respons tercatat pada filter yang dipilih.');
        $this->get(route('reports.pdf', $filters))->assertOk();
        $response = $this->get(route('reports.xlsx', $filters))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $book = IOFactory::load($path);
            $this->assertSame('Belum ada respons yang sesuai dengan filter laporan ini.', $book->getSheetByName('Detail Jawaban')->getCell('A15')->getValue());
            $this->assertSame('-', $book->getSheetByName('Ringkasan')->getCell('B19')->getValue());
            $book->disconnectWorksheets();
        } finally { @unlink($path); }
    }

    public function test_all_downloads_validate_date_order(): void
    {
        $filters = $this->fixture();
        $filters['from'] = '2026-10-10'; $filters['to'] = '2026-10-01';
        foreach (['reports.pdf', 'reports.xlsx', 'reports.csv'] as $route) {
            $this->getJson(route($route, $filters))->assertUnprocessable()->assertJsonValidationErrors('to');
        }
    }
}
