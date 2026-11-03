<?php

namespace App\Models\Concerns;

use App\Models\Scopes\WorkspaceScope;
use App\Models\Workspace;
use App\Services\CurrentWorkspace;

/**
 * Rows are filtered to the current workspace. workspace_id is never mass assignable:
 * it comes from the parent record (see inheritedWorkspaceId) or the active workspace.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);
        static::creating(function ($model) {
            $model->workspace_id ??= $model->inheritedWorkspaceId() ?? app(CurrentWorkspace::class)->idForNew();
        });
    }

    protected function inheritedWorkspaceId(): ?int
    {
        return null;
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
