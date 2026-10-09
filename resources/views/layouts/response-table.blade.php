@php($showAction = $showAction ?? false)
<div class="table-wrap">
    <table id="{{ $showAction ? 'responses-table' : 'recent-responses-table' }}" class="ts-data-table" data-ts-table
        @if ($showAction) data-server-table="responses" @endif aria-label="{{ __('Daftar respons') }}">
        <thead>
            <tr>
                <th>{{ __('Klien / proyek') }}</th>
                <th>{{ __('Kuesioner') }}</th>
                <th>{{ __('Skor') }}</th>
                <th>{{ __('Tanggal') }}</th>
                @if ($showAction)
                    <th data-dt-order="disable">{{ __('Tindakan') }}</th>
                @endif
                <th data-dt-order="disable">Detail</th>
            </tr>
        </thead>
        <tbody>
            @include('responses.rows')
        </tbody>
    </table>
</div>

@if ($showAction)
    @push('scripts')
        <script>
            document.addEventListener('click', function(e) {
                var opener = e.target.closest('[data-modal-open]');
                if (opener) {
                    var modal = document.getElementById(opener.dataset.modalOpen);
                    if (modal) {
                        modal.hidden = false;
                        document.body.style.overflow = 'hidden';
                    }
                    return;
                }
                var overlay = e.target.closest('.modal-overlay');
                if (overlay && (e.target === overlay || e.target.closest('[data-modal-close]'))) {
                    overlay.hidden = true;
                    document.body.style.overflow = '';
                }
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.modal-overlay:not([hidden])').forEach(function(m) {
                        m.hidden = true;
                    });
                    document.body.style.overflow = '';
                }
            });
        </script>
    @endpush
@endif
