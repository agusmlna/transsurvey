<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\CurrentWorkspace;
use Illuminate\Http\Request;

class WorkspaceController
{
    // Superadmin only: other roles get their workspace from their role or SSO (phase 2).
    public function switch(Request $request)
    {
        abort_unless($request->user()->isSuperadmin(), 403);
        $v = $request->validate(['workspace_id' => 'nullable|integer|exists:workspaces,id']);
        if (empty($v['workspace_id'])) {
            $request->session()->forget(CurrentWorkspace::SESSION_KEY);
            $name = __('Semua workspace');
        } else {
            $request->session()->put(CurrentWorkspace::SESSION_KEY, (int) $v['workspace_id']);
            $name = Workspace::findOrFail($v['workspace_id'])->name;
        }
        // IDs in the current URL may belong to another workspace; start from the dashboard.
        return redirect()->route('dashboard')->with('success', __('Workspace aktif: :name', ['name' => $name]));
    }
}
