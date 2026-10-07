<?php

namespace Tests\Feature;

use App\Jobs\{SendSurveyCompletionEmail, SendSurveyEmail};
use App\Mail\{SurveyCompletedMail, SurveyInvitationMail};
use App\Models\{AdminSurveyNotification, Client, Invitation, Survey, User};
use App\Services\{ResponseService, SurveyCompletionNotifier};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Mail, Queue};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SurveyAdminNotificationTest extends TestCase
{
    use DatabaseMigrations;

    private $originalQueue;

    protected function setUp(): void
    {
        parent::setUp();
        config(['survey.email_enabled' => true, 'mail.default' => 'smtp']);
        Mail::fake();
        $this->originalQueue = Queue::getFacadeRoot();
        Queue::fake();
    }

    private function admin(string $email = 'admin@example.test', string $role = 'admin'): User
    {
        return User::create(['name' => 'Admin Test', 'email' => $email, 'password' => 'OnlyTesting123!', 'role' => $role]);
    }

    private function invitation(): Invitation
    {
        $admin = User::first() ?? $this->admin();
        $client = Client::create(['name' => 'Klien Uji', 'contact' => 'PIC Baru', 'email' => 'new@example.test', 'project' => 'IT', 'active' => true]);
        $survey = Survey::create(['title' => 'Survei Uji', 'status' => 'active', 'starts_at' => today()->subDay(), 'ends_at' => today()->addWeek(), 'created_by' => $admin->id]);
        $survey->questions()->create(['text' => 'Bagaimana layanan kami?', 'category' => 'Layanan', 'type' => 'rating', 'required' => true, 'position' => 1]);
        $token = Str::random(64);
        return Invitation::create(['survey_id' => $survey->id, 'client_id' => $client->id, 'token' => $token, 'token_hash' => hash('sha256', $token), 'recipient_name' => 'PIC Tercantum', 'recipient_email' => 'listed@example.test']);
    }

    private function submit(Invitation $invitation, bool $draft = false)
    {
        return app(ResponseService::class)->save($invitation, ['answers' => [$invitation->survey->questions->first()->id => ['value' => '5']]], $draft);
    }

    public function test_final_submission_queues_each_admin_separately_and_not_viewer(): void
    {
        $a = $this->admin(); $b = $this->admin('second@example.test');
        $this->admin('viewer@example.test', 'viewer');
        $inv = $this->invitation();
        $response = $this->submit($inv);
        $this->assertDatabaseCount('admin_survey_notifications', 2);
        Queue::assertPushed(SendSurveyCompletionEmail::class, 2);
        foreach (AdminSurveyNotification::all() as $notification) {
            $job = new SendSurveyCompletionEmail($notification->id);
            $job->handle(); $job->handle();
        }
        Mail::assertSent(SurveyCompletedMail::class, 2);
        foreach ([$a, $b] as $admin) {
            Mail::assertSent(SurveyCompletedMail::class, fn ($mail) => $mail->hasTo($admin->email) && count($mail->to) === 1 && count($mail->cc) === 0 && count($mail->bcc) === 0);
        }
        $html = (new SurveyCompletedMail($response->load(['invitation', 'client', 'survey']), 'Admin Test'))->render();
        $this->assertStringContainsString('PIC Tercantum', $html);
        $this->assertStringContainsString('listed@example.test', $html);
        $this->assertStringContainsString(route('responses.show', $response), $html);
        $this->assertStringNotContainsString($inv->token, $html);
        $this->get(route('responses.show', $response))->assertRedirect(route('login'));
    }

    public function test_draft_and_invalid_submission_do_not_notify(): void
    {
        $inv = $this->invitation();
        $this->submit($inv, true);
        try {
            app(ResponseService::class)->save($inv, ['answers' => [$inv->survey->questions->first()->id => ['value' => '1']]]);
            $this->fail('Low score requires a comment.');
        } catch (ValidationException) {}
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('admin_survey_notifications', 0);
        Queue::assertNothingPushed(); Mail::assertNothingSent();
    }

    public function test_resubmission_does_not_create_another_notification(): void
    {
        $inv = $this->invitation(); $this->submit($inv);
        try { $this->submit($inv); $this->fail('Resubmission must fail.'); }
        catch (ValidationException) {}
        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertDatabaseCount('admin_survey_notifications', 1);
        Queue::assertPushed(SendSurveyCompletionEmail::class, 1);
    }

    public function test_outer_rollback_removes_response_and_notifications_without_dispatch(): void
    {
        $inv = $this->invitation();
        DB::beginTransaction();
        $this->submit($inv);
        Queue::assertNothingPushed();
        DB::rollBack();
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('admin_survey_notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_disabled_email_keeps_response_and_can_queue_later(): void
    {
        $inv = $this->invitation(); config(['survey.email_enabled' => false]);
        $this->submit($inv);
        $this->assertNotNull($inv->fresh()->completed_at);
        Queue::assertNothingPushed();
        $this->artisan('surveys:retry-admin-notifications')->assertFailed();
        config(['survey.email_enabled' => true]);
        $this->artisan('surveys:retry-admin-notifications')->assertSuccessful();
        Queue::assertPushed(SendSurveyCompletionEmail::class, 1);
    }

    public function test_failed_smtp_does_not_undo_response_and_successful_retry_is_not_resent(): void
    {
        $inv = $this->invitation(); $this->submit($inv);
        $notification = AdminSurveyNotification::firstOrFail();
        $mailFake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $job = new SendSurveyCompletionEmail($notification->id);
        try { $job->handle(); $this->fail('SMTP should fail.'); }
        catch (\RuntimeException $e) { $job->failed($e); }
        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertNotNull($inv->fresh()->completed_at);
        $this->assertSame('failed', $notification->fresh()->status);
        Mail::swap($mailFake);
        $job->handle(); $job->handle();
        Mail::assertSent(SurveyCompletedMail::class, 1);
        $this->assertSame('sent', $notification->fresh()->status);
    }

    public function test_demo_data_does_not_notify(): void
    {
        $inv = $this->invitation(); $inv->update(['is_demo' => true]);
        $this->submit($inv);
        $this->assertDatabaseCount('admin_survey_notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_role_or_email_change_before_delivery_skips_recipient(): void
    {
        $a = $this->admin(); $b = $this->admin('second@example.test');
        $inv = $this->invitation(); $this->submit($inv);
        $a->update(['role' => 'viewer']); $b->update(['email' => 'changed@example.test']);
        foreach (AdminSurveyNotification::all() as $n) { (new SendSurveyCompletionEmail($n->id))->handle(); }
        Mail::assertNothingSent();
        $this->assertSame(2, AdminSurveyNotification::where('status', 'skipped')->count());
    }

    public function test_reminder_sends_to_invitation_pic_and_completed_invitation_is_blocked(): void
    {
        $inv = $this->invitation(); $inv->update(['sent_at' => now()->subDays(2)]);
        $this->actingAs(User::first())->get(route('invitations.index'))->assertOk()->assertSee('Kirim reminder ke PIC');
        $this->post(route('invitations.remind', $inv))->assertSessionHasNoErrors();
        $delivery = $inv->deliveries()->firstOrFail();
        $this->assertSame('reminder', $delivery->kind);
        (new SendSurveyEmail($delivery->id))->handle();
        Mail::assertSent(SurveyInvitationMail::class, fn ($mail) => $mail->hasTo('listed@example.test') && ! $mail->hasTo('new@example.test') && $mail->reminder);
        $this->assertSame(1, $inv->fresh()->reminder_count);
        $this->post(route('invitations.remind', $inv))->assertSessionHasErrors('mail');
        $inv->update(['completed_at' => now()]);
        $this->post(route('invitations.remind', $inv))->assertSessionHasErrors('mail');
    }

    public function test_reminder_requires_initial_email_and_admin_role(): void
    {
        $inv = $this->invitation();
        $this->actingAs(User::first())->post(route('invitations.remind', $inv))->assertSessionHasErrors('mail');
        $viewer = $this->admin('viewer@example.test', 'viewer');
        $this->actingAs($viewer)->post(route('invitations.remind', $inv))->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_database_queue_is_used_even_when_default_is_sync(): void
    {
        Queue::swap($this->originalQueue);
        config(['queue.default' => 'sync']);
        $inv = $this->invitation();
        $this->submit($inv);
        $this->assertDatabaseCount('jobs', 1);
        Mail::assertNothingSent();
    }

    public function test_queue_failure_keeps_saved_response_and_recoverable_notification(): void
    {
        $inv = $this->invitation();
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('Queue unavailable'));
        $this->submit($inv);
        $this->assertDatabaseCount('survey_responses', 1);
        $notification = AdminSurveyNotification::firstOrFail();
        $this->assertSame('pending', $notification->status);
        $this->assertNull($notification->queued_at);
        Queue::swap($this->originalQueue);
        $this->assertTrue(app(SurveyCompletionNotifier::class)->dispatch($notification->id));
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_queued_reminder_is_skipped_when_pic_finishes_before_worker_runs(): void
    {
        $inv = $this->invitation(); $inv->update(['sent_at' => now()->subDays(2)]);
        $this->actingAs(User::first())->post(route('invitations.remind', $inv))->assertSessionHasNoErrors();
        $delivery = $inv->deliveries()->firstOrFail();
        $this->submit($inv);
        (new SendSurveyEmail($delivery->id))->handle();
        $this->assertSame('skipped', $delivery->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_reminder_maximum_closed_survey_and_resending_initial_email_are_rejected(): void
    {
        $inv = $this->invitation(); $inv->update(['sent_at' => now()->subDays(2), 'reminder_count' => config('survey.max_reminders')]);
        $this->actingAs(User::first())->post(route('invitations.remind', $inv))->assertSessionHasErrors('mail');
        $this->post(route('invitations.send', $inv))->assertSessionHasErrors('mail');
        $inv->update(['reminder_count' => 0]); $inv->survey->update(['status' => 'closed']);
        $this->post(route('invitations.remind', $inv))->assertSessionHasErrors('mail');
        Queue::assertNothingPushed();
    }

    private function withFileDatabase(callable $test): void
    {
        $path = tempnam(sys_get_temp_dir(), 'survey-lock-test-');
        unlink($path);
        $original = DB::getDefaultConnection();
        DB::statement('VACUUM INTO ?', [$path]);
        config(['database.connections.notification_lock_test' => [
            'driver' => 'sqlite', 'database' => $path, 'prefix' => '',
            'foreign_key_constraints' => true, 'busy_timeout' => 0,
            'journal_mode' => 'WAL',
        ]]);
        DB::setDefaultConnection('notification_lock_test');
        $other = new \PDO('sqlite:'.$path);
        $other->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $other->exec('PRAGMA busy_timeout=0');
        try { $test($other); }
        finally {
            if ($other->inTransaction()) { $other->rollBack(); }
            $other = null;
            DB::purge('notification_lock_test');
            DB::setDefaultConnection($original);
            foreach ([$path, $path.'-wal', $path.'-shm'] as $file) { if (is_file($file)) { unlink($file); } }
        }
    }

    public function test_another_connection_can_write_during_smtp_and_duplicate_worker_does_not_send(): void
    {
        $inv = $this->invitation(); $this->submit($inv);
        $id = AdminSurveyNotification::firstOrFail()->id;
        $this->withFileDatabase(function (&$other) use ($id) {
            $pending = \Mockery::mock(\Illuminate\Mail\PendingMail::class);
            Mail::shouldReceive('to')->once()->andReturn($pending);
            $pending->shouldReceive('send')->once()->andReturnUsing(function () use (&$other, $id) {
                $this->assertSame(0, DB::transactionLevel(), 'SMTP must run outside database transactions.');
                $other->exec("INSERT INTO cache (key, value, expiration) VALUES ('concurrent-write', 'ok', 999999999)");
                (new SendSurveyCompletionEmail($id))->handle();
            });
            (new SendSurveyCompletionEmail($id))->handle();
            $this->assertSame('sent', AdminSurveyNotification::findOrFail($id)->status);
            $this->assertSame('ok', DB::table('cache')->where('key', 'concurrent-write')->value('value'));
        });
    }

    public function test_lock_after_smtp_does_not_resend_email_on_queue_retry(): void
    {
        $inv = $this->invitation(); $this->submit($inv);
        $id = AdminSurveyNotification::firstOrFail()->id;
        $this->withFileDatabase(function (&$other) use ($id) {
            $pending = \Mockery::mock(\Illuminate\Mail\PendingMail::class);
            Mail::shouldReceive('to')->once()->andReturn($pending);
            $pending->shouldReceive('send')->once()->andReturnUsing(function () use (&$other) {
                $other->beginTransaction();
                $other->exec("INSERT INTO cache (key, value, expiration) VALUES ('held-lock', 'ok', 999999999)");
            });
            $job = new SendSurveyCompletionEmail($id);
            try { $job->handle(); $this->fail('The competing writer must prevent the final status update.'); }
            catch (\Illuminate\Database\QueryException $exception) {
                $this->assertStringContainsString('locked', $exception->getMessage());
                $other->rollBack();
                $job->failed($exception);
            }
            $this->assertSame('sending', AdminSurveyNotification::findOrFail($id)->status);
            $job->handle();
            $this->assertFalse(app(SurveyCompletionNotifier::class)->dispatch($id));
            $this->assertDatabaseCount('survey_responses', 1);
        });
    }
}
