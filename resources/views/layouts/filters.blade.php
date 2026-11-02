<form method="get" class="ts-filters no-print">
    <div class="ts-filter-main">
        @php
            // One option per client name; each client row is one PIC of that client.
            $clientNames = $clients->pluck('name')->map(fn ($name) => trim($name))->unique(fn ($name) => mb_strtolower($name))->sort(SORT_NATURAL | SORT_FLAG_CASE);
            $selectedPic = request()->filled('client_id') ? $clients->firstWhere('id', (int) request('client_id')) : null;
            $selectedClient = request('client', $selectedPic?->name);
        @endphp
        <label>
            {{ __('Klien') }}
            <select name="client" data-client-filter>
                <option value="">{{ __('Semua klien') }}</option>

                @foreach ($clientNames as $name)
                    <option
                        value="{{ $name }}"
                        @selected($selectedClient !== null && mb_strtolower(trim($selectedClient)) === mb_strtolower($name))
                    >
                        {{ $name }}
                    </option>
                @endforeach
            </select>
        </label>

        <label>
            {{ __('PIC') }}
            <select name="client_id" data-pic-filter>
                <option value="">{{ __('Semua PIC') }}</option>

                @foreach ($clients->sortBy(fn ($client) => mb_strtolower(trim($client->name)).' '.mb_strtolower($client->contact)) as $client)
                    <option
                        value="{{ $client->id }}"
                        data-client="{{ mb_strtolower(trim($client->name)) }}"
                        @selected(request('client_id') == $client->id)
                    >
                        {{ trim($client->contact) ?: $client->email }}{{ $client->project ? ' - '.$client->project : '' }}
                    </option>
                @endforeach
            </select>
        </label>

        <label>
            {{ __('Kuesioner') }}
            <select name="survey_id">
                <option value="">{{ __('Semua kuesioner') }}</option>

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
            {{ __('Dari tanggal') }}
            <input
                type="date"
                name="from"
                value="{{ request('from') }}"
            >
        </label>

        <label>
            {{ __('Sampai tanggal') }}
            <input
                type="date"
                name="to"
                value="{{ request('to') }}"
            >
        </label>
    </div>

    <details
        class="ts-filter-extra"
        @if(request()->filled('project') || request()->filled('source') || request()->filled('category')) open @endif
    >
        <summary>{{ __('Filter lainnya: kategori, proyek, dan sumber data') }}</summary>

        <div class="ts-filter-secondary">
            @isset($categoryOptions)
                <label>
                    {{ __('Kategori') }}
                    <select name="category">
                        <option value="">{{ __('Semua kategori') }}</option>

                        @foreach ($categoryOptions as $category)
                            <option
                                value="{{ $category }}"
                                @selected(request('category') === $category)
                            >
                                {{ $category }}
                            </option>
                        @endforeach
                    </select>
                </label>
            @endisset

            <label>
                {{ __('Proyek') }}
                <select name="project">
                    <option value="">{{ __('Semua proyek') }}</option>

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
                {{ __('Sumber data') }}
                <select name="source">
                    <option value="">{{ __('Semua data') }}</option>
                    <option value="real" @selected(request('source') === 'real')>
                        {{ __('Operasional') }}
                    </option>
                    <option value="demo" @selected(request('source') === 'demo')>
                        {{ __('Contoh') }}
                    </option>
                </select>
            </label>
        </div>
    </details>

    <div class="ts-filter-actions">
        <button type="submit" class="btn primary">
            {{ __('Terapkan filter') }}
        </button>

        <a class="text-button" href="{{ url()->current() }}">
            Reset
        </a>
    </div>
</form>