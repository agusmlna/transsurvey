<?php

namespace App\Models\Scopes;

use App\Services\CurrentWorkspace;
use Illuminate\Database\Eloquent\{Builder, Model, Scope};

class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $id = app(CurrentWorkspace::class)->filterId();
        if ($id !== null) {
            $builder->where($model->qualifyColumn('workspace_id'), $id);
        }
    }
}
