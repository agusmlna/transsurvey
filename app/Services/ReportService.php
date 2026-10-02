<?php

namespace App\Services;

use App\Models\{Answer, SurveyResponse, Invitation, Survey, FollowUp};
use Illuminate\Http\Request;

class ReportService
{
    public function responses(Request $r)
    {
        $category = $r->filled('category') ? (string) $r->input('category') : null;

        $q = SurveyResponse::query()->with([
            'client', 'survey', 'invitation',
            // With a category filter, only that category's answers are loaded.
            'answers' => fn($a) => $category !== null ? $a->where('category', $category) : $a,
        ]);
        if ($category !== null) $q->whereHas('answers', fn($a) => $a->where('category', $category));
        if ($r->filled('client_id')) $q->where('client_id', $r->integer('client_id'));
        if ($r->filled('survey_id')) $q->where('survey_id', $r->integer('survey_id'));
        if ($r->filled('project')) $q->whereHas('client', fn($c) => $c->where('project', $r->input('project')));
        if ($r->filled('from')) $q->where('submitted_at', '>=', $r->date('from')->startOfDay());
        if ($r->filled('to')) $q->where('submitted_at', '<=', $r->date('to')->endOfDay());
        if (in_array($r->input('source'), ['real', 'demo'])) $q->whereHas('invitation', fn($i) => $i->where('is_demo', $r->input('source') === 'demo'));
        return $q;
    }
    /**
     * The stored score covers every rating in a response. With a category filter,
     * recalculate it in memory from that category's ratings only (never saved).
     */
    public function applyCategoryScores($responses, Request $r)
    {
        if (!$r->filled('category')) return $responses;
        foreach ($responses as $response) {
            $ratings = $response->answers->filter(fn($a) => $a->type === 'rating' && $a->value !== null && $a->value !== '');
            $response->score = $ratings->isEmpty() ? null : round($ratings->avg(fn($a) => (int)$a->value), 2);
        }
        return $responses;
    }
    public function categories()
    {
        return Answer::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category');
    }
    public function summary(Request $r): array
    {
        $responses = $this->responses($r)->latest('submitted_at')->get();
        $this->applyCategoryScores($responses, $r);
        $inv = Invitation::query();
        if ($r->filled('client_id')) $inv->where('client_id', $r->integer('client_id'));
        if ($r->filled('survey_id')) $inv->where('survey_id', $r->integer('survey_id'));
        if ($r->filled('project')) $inv->whereHas('client', fn($c) => $c->where('project', $r->input('project')));
        if ($r->filled('from')) $inv->where('created_at', '>=', $r->date('from')->startOfDay());
        if ($r->filled('to')) $inv->where('created_at', '<=', $r->date('to')->endOfDay());
        if (in_array($r->input('source'), ['real', 'demo'])) $inv->where('is_demo', $r->input('source') === 'demo');
        $invCount = (clone $inv)->count();
        $complete = (clone $inv)->whereNotNull('completed_at')->count();
        $ratings = $responses->flatMap->answers->filter(fn($a) => $a->type === 'rating' && $a->value !== null && $a->value !== '');
        $categories = $ratings->groupBy('category')->map(fn($rows) => round($rows->avg(fn($a) => (int)$a->value), 2))->sortDesc();
        $trends = $responses->whereNotNull('score')->groupBy(fn($r) => $r->submitted_at->format('Y-m'))->sortKeys()->map(fn($rows) => round($rows->avg('score'), 2));
        // Count individual rating answers, not response averages or comment sentiment.
        $validRatings = $ratings->filter(fn($answer) => in_array((string)$answer->value, ['1', '2', '3', '4', '5'], true));
        $totalRatings = $validRatings->count();
        $highRatings = $validRatings->filter(fn($answer) => (int)$answer->value >= 4)->count();
        $attentionRatings = $totalRatings - $highRatings;
        $feedback = [
            'total' => $totalRatings,
            'high' => $highRatings,
            'attention' => $attentionRatings,
            'highPercent' => $totalRatings ? round(100 * $highRatings / $totalRatings, 1) : null,
            'attentionPercent' => $totalRatings ? round(100 * $attentionRatings / $totalRatings, 1) : null,
        ];
        return compact('responses', 'categories', 'trends', 'invCount', 'complete', 'feedback') + ['average' => $responses->whereNotNull('score')->avg('score'), 'rate' => $invCount ? round(100 * $complete / $invCount) : 0, 'active' => $this->activeSurveys($r), 'openFollow' => FollowUp::where('status', '!=', 'resolved')->count()];
    }
    public function activeSurveys(Request $r): int
    {
        // Active means open today. The response-date filter does not change this clock.
        $surveys = Survey::query()->where('status', 'active')->whereDate('starts_at', '<=', today())->whereDate('ends_at', '>=', today());
        if ($r->filled('survey_id')) $surveys->whereKey($r->integer('survey_id'));
        if ($r->filled('client_id') || $r->filled('project') || in_array($r->input('source'), ['real', 'demo'], true)) {
            // All assignment filters must match the same invitation; each survey counts once.
            $assignments = Invitation::query()->select('survey_id');
            if ($r->filled('client_id')) $assignments->where('client_id', $r->integer('client_id'));
            if ($r->filled('project')) $assignments->whereHas('client', fn($client) => $client->where('project', $r->input('project')));
            if (in_array($r->input('source'), ['real', 'demo'], true)) $assignments->where('is_demo', $r->input('source') === 'demo');
            $surveys->whereIn('id', $assignments);
        }
        return $surveys->count();
    }
    public function validateFilters(Request $r): void
    {
        $r->validate(['client_id' => 'nullable|integer|exists:clients,id', 'survey_id' => 'nullable|integer|exists:surveys,id', 'category' => 'nullable|string|max:100', 'project' => 'nullable|string|max:255', 'from' => 'nullable|date_format:Y-m-d', 'to' => array_filter(['nullable', 'date_format:Y-m-d', $r->filled('from') ? 'after_or_equal:from' : null]), 'source' => 'nullable|in:real,demo']);
    }
}