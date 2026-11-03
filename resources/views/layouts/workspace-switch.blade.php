@php($currentWorkspace = app(\App\Services\CurrentWorkspace::class))
@if (auth()->user()->isSuperadmin())
    <form method="post" action="{{ route('workspace.switch') }}" class="ts-workspace-switch" aria-label="{{ __('Workspace') }}">
        @csrf
        <select name="workspace_id" data-workspace-switch aria-label="{{ __('Pilih workspace') }}">
            <option value="">{{ __('Semua workspace') }}</option>
            @foreach (\App\Models\Workspace::orderByDesc('is_legacy')->orderBy('name')->get() as $workspace)
                <option value="{{ $workspace->id }}" @selected($currentWorkspace->filterId() === $workspace->id)>{{ $workspace->name }}</option>
            @endforeach
        </select>
        <noscript><button type="submit" class="btn">{{ __('Pilih') }}</button></noscript>
    </form>
@else
    <span class="ts-workspace-name" title="{{ __('Workspace') }}">{{ $currentWorkspace->label() }}</span>
@endif
