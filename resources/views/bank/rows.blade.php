@forelse($questions as $q)
<tr><td><strong>{{ $q->text }}</strong>@if($q->type === 'choice')<small>{{ implode(' · ', $q->options ?? []) }}</small>@endif</td>
<td><span class="badge gray">{{ $q->category }}</span></td>
<td>{{ ['rating'=>'Rating 1–5','choice'=>__('Pilihan ganda'),'text'=>__('Teks terbuka')][$q->type] }}</td>
<td><span class="badge {{ $q->required ? 'amber' : 'gray' }}">{{ $q->required ? __('Wajib') : __('Opsional') }}</span></td>
<td><a class="text-button" href="{{ route('surveys.create') }}">{{ __('Buka editor →') }}</a></td></tr>
@empty<tr><td colspan="5" class="empty">{{ __('Belum ada pertanyaan yang sesuai.') }}</td></tr>@endforelse
