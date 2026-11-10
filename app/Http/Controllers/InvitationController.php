<?php

namespace App\Http\Controllers;

use App\Services\DataTableService;

use App\Models\{Invitation, Survey, Client};
use App\Services\DeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\{Str};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvitationController
{
    public function index(Request $r, DataTableService $tables)
    {
        // CSAT invitations appear on the dedicated Kirim CSAT page, not the client invitation list.
        $q = Invitation::with(['survey', 'client', 'deliveries'])
            ->whereHas('survey', fn ($query) => $query->where('type', 'client'))
            ->latest();
        if ($r->input('status') === 'completed') $q->whereNotNull('completed_at');
        if ($r->input('status') === 'pending') $q->whereNull('completed_at');
        if ($r->input('status') === 'draft')     $q->whereNull('completed_at')->whereNotNull('started_at'); 
        if ($r->boolean('_table')) return $tables->respond($r, $q, 'invitations');
        return view('invitations.index', ['invitations' => $q->paginate(20)->withQueryString(), 'surveys' => Survey::where('type', 'client')->where('status', 'active')->get(), 'clients' => Client::where('active', true)->orderBy('name')->get()]);
    }
    public function store(Request $r)
    {
        $workspace = app(\App\Services\CurrentWorkspace::class);
        $v = $r->validate(['survey_id' => ['required', $workspace->existsRule('surveys')], 'client_ids' => 'required|array|min:1|max:100', 'client_ids.*' => ['required', 'integer', 'distinct', $workspace->existsRule('clients')], 'repeat' => 'nullable|boolean']);
        $repeat = $r->boolean('repeat');
        [$count, $repeated] = DB::transaction(function () use ($v, $repeat) {
            $survey = Survey::where('type', 'client')->lockForUpdate()->findOrFail($v['survey_id']);
            if ($survey->status !== 'active' || $survey->ends_at->endOfDay()->isPast()) throw ValidationException::withMessages(['survey_id' => __('Pilih survei aktif yang belum berakhir.')]);
            $created = 0;
            $repeated = 0;
            foreach ($v['client_ids'] as $id) {
                // The survey row lock above serializes this check per survey.
                $existing = Invitation::where('survey_id', $survey->id)->where('client_id', $id)->count();
                if ($existing > 0 && !$repeat) continue;
                $c = Client::where('active', true)->findOrFail($id);
                $token = Str::random(64);
                // A repeat link (e.g. admin re-entering a manual/Google Form answer) gets its own
                // ticket_id, so the unique (survey, client, ticket) index allows it.
                $ticket = $existing > 0 ? $this->repeatTicket($survey->id, $c->id, $existing) : '';
                if ($ticket !== '') $repeated++;
                Invitation::create(['survey_id' => $survey->id, 'client_id' => $c->id, 'ticket_id' => $ticket, 'token' => $token, 'token_hash' => hash('sha256', $token), 'recipient_name' => $c->contact, 'recipient_email' => $c->email, 'is_demo' => $survey->is_demo || $c->is_demo]);
                $created++;
            }
            return [$created, $repeated];
        });
        if ($repeat) {
            return back()->with('success', __(':count undangan baru dibuat, :repeated di antaranya tautan ulang untuk klien yang sudah pernah diundang. Salin tautan atau klik Kirim email setelah memeriksa penerima.', ['count' => $count, 'repeated' => $repeated]));
        }
        return back()->with('success', __(':count undangan baru dibuat; undangan yang sudah ada dilewati. Salin tautan atau klik Kirim email setelah memeriksa penerima.', ['count' => $count]));
    }

    public const REPEAT_PREFIX = 'ulang-';

    private function repeatTicket(int $surveyId, int $clientId, int $existing): string
    {
        $n = $existing + 1;
        while (Invitation::where('survey_id', $surveyId)->where('client_id', $clientId)->where('ticket_id', self::REPEAT_PREFIX . $n)->exists()) $n++;
        return self::REPEAT_PREFIX . $n;
    }
    public function send(Invitation $invitation, DeliveryService $delivery)
    {
        if (!$invitation->survey->isOpen()) throw ValidationException::withMessages(['mail' => __('Email hanya dapat dikirim dalam periode survei aktif.')]);
        if ($invitation->sent_at) throw ValidationException::withMessages(['mail' => __('Undangan pertama sudah dikirim. Gunakan tombol Kirim reminder ke PIC.')]);
        $delivery->enqueue($invitation, 'invitation');
        return back()->with('success', __('Email masuk antrean. Pantau status pengiriman di daftar undangan.'));
    }
    public function sendTemplate(Invitation $invitation, string $kind, DeliveryService $delivery)
    {
        if (!in_array($kind, ['not_started', 'save_draft'], true)) {
            abort(404);
        }

        if (!$invitation->survey->isOpen()) {
            throw ValidationException::withMessages(['mail' => __('Email hanya dapat dikirim dalam periode survei aktif.')]);
        }

        $delivery->enqueue($invitation, $kind);

        $label = $kind === 'not_started' ? __('Not Started') : __('Save Draft');
        return back()->with('success', __('Email :template masuk antrean. Pantau status pengiriman di daftar undangan.', ['template' => $label]));
    }

    public function remind(Invitation $invitation, DeliveryService $delivery)
    {
        if (!$invitation->sent_at) throw ValidationException::withMessages(['mail' => __('Kirim email undangan pertama terlebih dahulu.')]);
        if (!$invitation->survey->isOpen()) throw ValidationException::withMessages(['mail' => __('Reminder hanya dapat dikirim dalam periode survei aktif.')]);
        $delivery->enqueue($invitation, 'reminder');
        return back()->with('success', __('Reminder masuk antrean untuk PIC yang tercantum pada undangan.'));
    }
}
