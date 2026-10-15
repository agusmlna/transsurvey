<?php

namespace App\Http\Controllers;

use App\Models\{Client, Invitation, Survey, SurveyResponse};
use App\Services\DataDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DataDeletionController
{
    public function index(Request $request)
    {
        $request->validate([
            'type' => ['sometimes', Rule::in(DataDeletionService::TYPES)],
            'q' => 'nullable|string|max:200', 'source' => 'nullable|in:all,demo,regular',
            'per_page' => 'nullable|integer|in:5,10,25,50,100',
        ]);
        $type = $request->input('type', 'invitations');
        $term = trim((string) $request->input('q'));
        $query = match ($type) {
            'clients' => Client::withCount('invitations'),
            'surveys' => Survey::withCount(['invitations', 'responses']),
            'responses' => SurveyResponse::with(['client', 'survey', 'invitation']),
            default => Invitation::with(['client', 'survey', 'response']),
        };
        if ($term !== '') {
            $query->where(function ($q) use ($type, $term) {
                if ($type === 'clients') {
                    $q->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%')->orWhere('project', 'like', '%'.$term.'%');
                } elseif ($type === 'surveys') {
                    $q->where('title', 'like', '%'.$term.'%');
                } else {
                    $q->whereHas('client', fn ($c) => $c->where('name', 'like', '%'.$term.'%'))
                        ->orWhereHas('survey', fn ($s) => $s->where('title', 'like', '%'.$term.'%'));
                }
            });
        }
        if (in_array($request->input('source'), ['demo', 'regular'], true)) {
            $demo = $request->input('source') === 'demo';
            if ($type === 'responses') $query->whereHas('invitation', fn ($q) => $q->where('is_demo', $demo));
            else $query->where('is_demo', $demo);
        }
        return view('data-cleanup.index', [
            'type' => $type, 'rows' => $query->orderByDesc('id')->paginate($request->integer('per_page', 5))->withQueryString(),
        ]);
    }

    public function preview(Request $request, DataDeletionService $service)
    {
        $v = $request->validate([
            'type' => ['required', Rule::in(DataDeletionService::TYPES)],
            'ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|integer|min:1|distinct',
            'reason' => 'required|string|min:5|max:500',
        ]);
        $state = DB::transaction(fn () => $service->inspect($v['type'], $v['ids']));
        $preview = array_merge($state, [
            'type' => $v['type'], 'reason' => $v['reason'], 'user_id' => $request->user()->id,
            'expires_at' => now()->addMinutes(10)->timestamp, 'token' => Str::random(64),
        ]);
        // One outstanding preview per session. No selected IDs are trusted on confirmation.
        $request->session()->put('data_cleanup_preview', $preview);
        return redirect()->route('data-cleanup.show', $preview['token']);
    }

    public function show(Request $request, string $token)
    {
        $preview = $this->owned($request, $token);
        if ($preview['expires_at'] < now()->timestamp) {
            return redirect()->route('data-cleanup.index')->withErrors(['cleanup' => __('data_cleanup.expired')]);
        }
        return response()->view('data-cleanup.preview', compact('preview'))->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, string $token, DataDeletionService $service)
    {
        $request->validate(['confirmation' => 'required|in:HAPUS', 'acknowledge' => 'accepted']);
        $preview = $this->owned($request, $token);
        $service->delete($preview, $request->user());
        $request->session()->forget('data_cleanup_preview');
        return redirect()->route('data-cleanup.index', ['type' => $preview['type']])->with('success', __('data_cleanup.success'));
    }

    public function history()
    {
        return view('data-cleanup.history', ['audits' => DB::table('data_deletion_audits')->orderByDesc('id')->paginate(10)]);
    }

    private function owned(Request $request, string $token): array
    {
        $preview = $request->session()->get('data_cleanup_preview');
        abort_unless(is_array($preview) && $preview['user_id'] === $request->user()->id
            && hash_equals($preview['token'], $token), 404);
        return $preview;
    }
}
