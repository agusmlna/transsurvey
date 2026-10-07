@forelse($questions as $q)
<tr><td><strong>{{ $q->text }}</strong>@if($q->type === 'choice')<small>{{ implode(' · ', $q->options ?? []) }}</small>@endif</td>
<td><span class="badge gray">{{ $q->category }}</span></td>
<td>{{ ['rating'=>'Rating 1–5','choice'=>'Pilihan ganda','text'=>'Teks terbuka'][$q->type] }}</td>
<td><span class="badge {{ $q->required ? 'amber' : 'gray' }}">{{ $q->required ? 'Wajib' : 'Opsional' }}</span></td>
<td><a class="text-button" href="{{ route('surveys.create') }}">Buka editor →</a></td></tr>
@empty<tr><td colspan="5" class="empty">Belum ada pertanyaan yang sesuai.</td></tr>@endforelse
