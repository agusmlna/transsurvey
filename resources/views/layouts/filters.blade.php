<form method="get" class="ts-filters no-print">
    <div class="ts-filter-main">
        <label>
            Klien
            <select name="client_id">
                <option value="">Semua klien</option>

                @foreach ($clients as $client)
                    <option
                        value="{{ $client->id }}"
                        @selected(request('client_id') == $client->id)
                    >
                        {{ $client->name }}
                    </option>
                @endforeach
            </select>
        </label>

        <label>
            Kuesioner
            <select name="survey_id">
                <option value="">Semua kuesioner</option>

                @foreach ($surveys as $survey)
                    <option
                        value="{{ $survey->id }}"
                        @selected(request('survey_id') == $survey->id)
                    >
                        {{ $survey->title }}
                    </option>
                @endforeach
            </select>
        </label>

        <label>
            Dari tanggal
            <input
                type="date"
                name="from"
                value="{{ request('from') }}"
            >
        </label>

        <label>
            Sampai tanggal
            <input
                type="date"
                name="to"
                value="{{ request('to') }}"
            >
        </label>
    </div>

    <details
        class="ts-filter-extra"
        @if(request()->filled('project') || request()->filled('source')) open @endif
    >
        <summary>Filter lainnya: proyek dan sumber data</summary>

        <div class="ts-filter-secondary">
            <label>
                Proyek
                <select name="project">
                    <option value="">Semua proyek</option>

                    @foreach ($clients->pluck('project')->unique()->sort() as $project)
                        <option
                            value="{{ $project }}"
                            @selected(request('project') === $project)
                        >
                            {{ $project }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label>
                Sumber data
                <select name="source">
                    <option value="">Semua data</option>
                    <option value="real" @selected(request('source') === 'real')>
                        Operasional
                    </option>
                    <option value="demo" @selected(request('source') === 'demo')>
                        Contoh
                    </option>
                </select>
            </label>
        </div>
    </details>

    <div class="ts-filter-actions">
        <button type="submit" class="btn primary">
            Terapkan filter
        </button>

        <a class="text-button" href="{{ url()->current() }}">
            Reset
        </a>
    </div>
</form>