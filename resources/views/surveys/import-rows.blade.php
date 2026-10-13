<div class="qi-table-wrap" tabindex="0" role="region" aria-label="{{ __('question_import.review') }}">
<table class="qi-table">
    <thead><tr><th>{{ __('question_import.excel_row') }}</th><th>{{ __('question_import.question') }}</th><th>{{ __('question_import.settings') }}</th><th>{{ __('question_import.validation') }}</th></tr></thead>
    <tbody>
    @foreach ($rows as $row)
        @php($q = $row['question'])
        <tr>
            <td><strong>{{ $row['row'] }}</strong><small>{{ __('question_import.order_label') }} {{ $row['order'] }}</small></td>
            <td><span class="qi-category">{{ $q['category'] }}</span><p class="qi-prewrap">{{ $q['text'] }}</p>@if ($q['options'])<ul>@foreach ($q['options'] as $option)<li>{{ $option }}</li>@endforeach</ul>@endif</td>
            <td><span class="qi-type">{{ $q['type'] }}</span><small>{{ __('question_import.required_label') }}: {{ $q['required'] ? __('question_import.yes') : __('question_import.no') }}</small><small>{{ __('question_import.other_label') }}: {{ $q['allow_other'] ? __('question_import.yes') : __('question_import.no') }}</small></td>
            <td>@if ($row['errors'])<ul class="qi-error">@foreach ($row['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul>@else<span class="qi-valid">{{ __('question_import.valid') }}</span>@endif</td>
        </tr>
    @endforeach
    </tbody>
</table>
</div>
