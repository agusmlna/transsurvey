@extends('layouts.app')
@section('title', __('data_cleanup.title'))
@section('heading', __('data_cleanup.title'))
@section('subtitle', __('data_cleanup.subtitle'))
@section('actions')
    @include('data-cleanup.rules')

    <a class="btn" href="{{ route('data-cleanup.history') }}">
        {{ __('data_cleanup.history') }}
    </a>
@endsection
@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/transsurvey-cleanup.css') }}">
@endpush
@push('scripts')
    <script defer src="{{ asset('assets/transsurvey-cleanup.js') }}"></script>
@endpush
@section('content')
    <div class="cleanup-notice"><strong>{{ __('data_cleanup.warning') }}</strong>
        <p>{{ __('data_cleanup.intro') }}</p>
    </div>
    <form method="get" class="panel cleanup-filter">
        <label>{{ __('data_cleanup.type') }}<select name="type">
                @foreach (\App\Services\DataDeletionService::TYPES as $option)
                    <option value="{{ $option }}" @selected($type === $option)>{{ __('data_cleanup.' . $option) }}
                    </option>
                @endforeach
            </select>
        </label>
        <label>{{ __('data_cleanup.source') }}<select name="source">
                @foreach (['all', 'demo', 'regular'] as $source)
                    <option value="{{ $source }}" @selected(request('source', 'all') === $source)>{{ __('data_cleanup.' . $source) }}
                    </option>
                @endforeach
            </select>
        </label>
        <label>{{ __('data_cleanup.search') }}<input type="search" name="q" maxlength="200"
                value="{{ request('q') }}"></label>
        <label>{{ __('data_cleanup.per_page') }}<select name="per_page">
                @foreach ([5, 10, 25, 50, 100] as $size)
                    <option @selected((int) request('per_page', 5) === $size)>{{ $size }}</option>
                @endforeach
            </select></label>
        <button class="btn primary">{{ __('data_cleanup.filter') }}</button>
    </form>
    @if (in_array($type, ['invitations', 'responses']))
    <p class="cleanup-help">{{ __('data_cleanup.response_warning') }}</p>@else<p class="cleanup-help">
            {{ __('data_cleanup.master_warning') }}</p>
    @endif
    <form method="post" action="{{ route('data-cleanup.preview') }}" class="panel cleanup-panel" data-cleanup-selection>
        @csrf<input type="hidden" name="type" value="{{ $type }}">
        <div class="cleanup-toolbar"><label class="cleanup-check"><input type="checkbox" data-cleanup-all>
                {{ __('data_cleanup.select_page') }}</label><span aria-live="polite"><strong data-cleanup-count>0</strong>
                {{ __('data_cleanup.selected_count') }}</span></div>
        <div class="table-wrap">
            <table class="cleanup-table">
                <thead>
                    <tr>
                        <th>{{ __('data_cleanup.select') }}</th>
                        <th>ID</th>
                        <th>{{ __('data_cleanup.record') }}</th>
                        <th>{{ __('data_cleanup.detail') }}</th>
                        <th>{{ __('data_cleanup.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $blocked =
                                ($type === 'clients' && $row->invitations_count > 0) ||
                                ($type === 'surveys' &&
                                    ($row->status !== 'draft' ||
                                        $row->invitations_count > 0 ||
                                        $row->responses_count > 0));
                            $demo = $type === 'responses' ? $row->invitation?->is_demo : $row->is_demo;
                        @endphp
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="{{ $row->id }}"
                                    @disabled($blocked)
                                    aria-label="{{ __('data_cleanup.select') }} #{{ $row->id }}"></td>
                            <td>#{{ $row->id }}</td>
                            <td><strong>{{ match ($type) {'clients' => $row->name,'surveys' => $row->title,default => $row->client?->name ?? '—'} }}</strong><small>{{ __('data_cleanup.' . ($demo ? 'demo' : 'regular')) }}</small>
                            </td>
                            <td>
                                @if ($type === 'clients')
                                    {{ $row->contact }}<small>{{ $row->email }} · {{ $row->project }}</small>
                                @elseif ($type === 'surveys')
                                    {{ $row->starts_at?->format('d/m/Y') }} –
                                    {{ $row->ends_at?->format('d/m/Y') }}<small>{{ $row->invitations_count }}
                                        {{ __('data_cleanup.invitations') }}</small>
                                    @else{{ $row->survey?->title }}<small>{{ $type === 'responses' ? $row->invitation?->recipient_email : $row->recipient_email }}</small>
                                @endif
                            </td>
                            <td><span
                                    class="cleanup-badge {{ $blocked ? 'muted' : '' }}">{{ $blocked ? __('data_cleanup.blocked') : (in_array($type, ['clients', 'surveys']) ? __('data_cleanup.ready') : __('data_cleanup.' . ($type === 'responses' || $row->completed_at ? 'completed' : 'pending'))) }}</span>
                            </td>
                        </tr>
                    @empty<tr>
                            <td colspan="5">{{ __('data_cleanup.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="cleanup-pagination">{{ $rows->links() }}<small>{{ __('data_cleanup.selection_note') }}</small></div>
        <div class="cleanup-form"><label for="cleanup-reason">{{ __('data_cleanup.reason') }}</label>
            <textarea id="cleanup-reason" name="reason" required minlength="5" maxlength="500" rows="2"
                placeholder="{{ __('data_cleanup.reason_placeholder') }}">{{ old('reason') }}</textarea><button class="btn primary" type="submit">{{ __('data_cleanup.preview') }}</button>
        </div>
    </form>
@endsection
