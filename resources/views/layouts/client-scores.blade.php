<section class="panel"><div class="panel-heading"><div><h2>Kepuasan per klien</h2><p>Rata-rata rating pada filter yang dipilih</p></div></div><div class="table-wrap"><table id="client-scores-table" class="ts-data-table" data-ts-table aria-label="Kepuasan per klien"><thead><tr><th>Klien / proyek</th><th>Respons</th><th>Skor</th><th>Persentase</th></tr></thead><tbody>
@forelse($responses->groupBy('client_id') as $clientRows)
@php($clientAverage=$clientRows->whereNotNull('score')->avg('score'))
<tr><td><strong>{{ $clientRows->first()->client->name }}</strong><small>{{ $clientRows->first()->client->project }}</small></td><td>{{ $clientRows->count() }}</td><td data-order="{{ $clientAverage ?? -1 }}">{{ $clientAverage===null?'—':number_format($clientAverage,2).' / 5' }}</td><td data-order="{{ $clientAverage ?? -1 }}">{{ $clientAverage===null?'—':number_format($clientAverage/5*100,1).'%' }}</td></tr>
@empty<tr><td colspan="4" class="empty">Belum ada respons.</td></tr>@endforelse
</tbody></table></div></section>
