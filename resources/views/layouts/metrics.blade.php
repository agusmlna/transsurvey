<div class="metrics">
    <article class="metric"><span class="ts-metric-icon ts-blue">@include('layouts.icon', ['name' => 'survey'])</span><div><span>Survei aktif</span><strong>{{ $active }}</strong><p>Dalam periode pengisian</p></div></article>
    <article class="metric"><span class="ts-metric-icon ts-blue">@include('layouts.icon', ['name' => 'mail'])</span><div><span>Response rate</span><strong>{{ $rate }}<small>%</small></strong><p>{{ $complete }} dari {{ $invCount }} undangan</p></div></article>
    <article class="metric"><span class="ts-metric-icon ts-green">@include('layouts.icon', ['name' => 'response'])</span><div><span>Total respons</span><strong>{{ $responses->count() }}</strong><p>Pada periode yang dipilih</p></div></article>
    <article class="metric"><span class="ts-metric-icon ts-amber">@include('layouts.icon', ['name' => 'star'])</span><div><span>Skor kepuasan</span><strong>{{ $average === null ? '—' : number_format($average, 2) }}<small>/ 5</small></strong><p>{{ $average === null ? 'Belum ada penilaian' : number_format($average / 5 * 100, 1).'% kepuasan' }}</p></div></article>
</div>
