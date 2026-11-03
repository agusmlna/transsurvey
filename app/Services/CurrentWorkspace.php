<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;

/**
 * The workspace whose data the signed-in user sees.
 *
 * Scoping is only active inside the authenticated area (ActivateWorkspace middleware).
 * Public survey links, queue jobs and console commands always see every workspace.
 */
class CurrentWorkspace
{
    public const SESSION_KEY = 'workspace_id';
    /** Matches no row: a user that may not see any workspace. */
    public const NONE = 0;
    private const FIXED = '#fixed';

    private bool $active = false;
    private ?string $cacheKey = null;
    private ?int $id = null;
    private ?int $legacyId = null;

    public function activate(): void
    {
        $this->active = true;
        $this->cacheKey = null;
    }

    public function deactivate(): void
    {
        $this->active = false;
        $this->cacheKey = null;
    }

    /** Run with a fixed filter: a streamed download runs after the request and its middleware. */
    public function runAs(?int $id, callable $callback): mixed
    {
        [$active, $key, $current] = [$this->active, $this->cacheKey, $this->id];
        [$this->active, $this->cacheKey, $this->id] = [true, self::FIXED, $id];
        try {
            return $callback();
        } finally {
            [$this->active, $this->cacheKey, $this->id] = [$active, $key, $current];
        }
    }

    /** Workspace filter for queries, or null for no filter (inactive or "all workspaces"). */
    public function filterId(): ?int
    {
        return $this->active ? $this->id() : null;
    }

    /**
     * superadmin: chosen workspace, or null for all; operation: chosen workspace if a member;
     * admin and viewer: the legacy workspace.
     */
    public function id(): ?int
    {
        $user = Auth::user();
        $chosen = (int) session(self::SESSION_KEY);
        $key = $user?->getAuthIdentifier().'|'.$user?->role.'|'.$chosen;
        if ($this->cacheKey === self::FIXED || $this->cacheKey === $key) return $this->id;
        $this->id = match (true) {
            $user === null => null,
            $user->isSuperadmin() => $chosen && Workspace::whereKey($chosen)->exists() ? $chosen : null,
            $user->role === 'operation' => $chosen && $user->workspaces()->whereKey($chosen)->exists() ? $chosen : self::NONE,
            default => $this->legacyId(),
        };
        $this->cacheKey = $key;
        return $this->id;
    }

    /** New records go to the active workspace; without one (public, jobs, "all") to legacy. */
    public function idForNew(): int
    {
        $id = $this->filterId();
        abort_if($id === self::NONE, 403, __('Pilih workspace terlebih dahulu.'));
        return $id ?? $this->legacyId();
    }

    /** Validation rule: the ID must exist in the current workspace (raw SQL rules skip model scopes). */
    public function existsRule(string $table): \Illuminate\Validation\Rules\Exists
    {
        $id = $this->filterId();
        return \Illuminate\Validation\Rule::exists($table, 'id')->when($id !== null, fn ($rule) => $rule->where('workspace_id', $id));
    }

    public function legacyId(): int
    {
        return $this->legacyId ??= Workspace::firstOrCreate(['code' => 'legacy'], ['name' => 'Data lama', 'is_legacy' => true])->id;
    }

    public function label(): string
    {
        $id = $this->filterId();
        return match ($id) {
            null => __('Semua workspace'),
            self::NONE => __('Tanpa workspace'),
            default => Workspace::find($id)?->name ?? __('Tanpa workspace'),
        };
    }
}
