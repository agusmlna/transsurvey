<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ $survey->title }} · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/transsurvey.css') }}?v={{ filemtime(public_path('assets/transsurvey.css')) }}">
    <script defer src="{{ asset('assets/app.js') }}"></script>
</head>

<body class="respondent-page">
    <main class="survey-sheet">
        <div class="survey-top"><div class="brand-panel">
                @include('layouts.brand')
            </div><small>Client experience</small>
        </div>
        @if ($preview)
            <div class="notice">Mode pratinjau · Jawaban tidak disimpan</div>
        @endif
        @if ($survey->is_demo)
            <div class="demo-label">SURVEI CONTOH</div>
        @endif
        <h1>{{ $survey->title }}</h1>
        <p class="survey-intro">{{ $survey->description }}</p>
        @if ($invitation)
            <p class="muted">Untuk <strong>{{ $invitation->client->name }}</strong></p>
        @endif
        @if (session('success'))
            <div class="notice success" role="status">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="notice error" role="alert"><strong>Jawaban belum dikirim.</strong>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if ($closed)
            <section class="empty">
                <h2>Survei sedang tidak menerima respons.</h2>
                <p>Periode pengisian: {{ $survey->starts_at->format('d M Y') }} –
                    {{ $survey->ends_at->format('d M Y') }}.</p>
            </section>
        @else
            @php($answers = old('answers', $invitation?->draft_answers ?? []))
            <div class="progress-caption"><span
                    id="survey-progress-text">{{ $survey->questions->where('required', true)->count() }} pertanyaan
                    wajib</span><span>± 3 menit</span></div><progress class="survey-progress" id="survey-progress"
                value="0" max="100" aria-label="Progres pengisian"></progress>
            <form method="post" action="{{ $preview ? '#' : route('survey.submit', $invitation->token) }}"
                class="respondent-form" @if ($preview) data-preview @endif>@csrf
                @foreach ($survey->questions as $q)
                    @php($answer = $answers[$q->id] ?? [])
                    <section class="survey-question" data-question data-type="{{ $q->type }}"
                        data-required="{{ $q->required ? '1' : '0' }}"><span class="eyebrow">{{ $q->category }}</span>
                        <h2 id="question-{{ $q->id }}">{{ $loop->iteration }}. {{ $q->text }}
                            @if ($q->required)
                                <span class="required">*</span>
                            @endif
                        </h2>
                        @if ($q->type === 'rating')
                            <fieldset class="rating-group" aria-labelledby="question-{{ $q->id }}">
                                @foreach ([1 => 'Sangat tidak puas', 2 => 'Tidak puas', 3 => 'Cukup puas', 4 => 'Puas', 5 => 'Sangat puas'] as $n => $text)
                                    <label class="rating-option"><input type="radio"
                                            name="answers[{{ $q->id }}][value]" value="{{ $n }}"
                                            @checked((string) ($answer['value'] ?? '') === (string) $n)
                                            @required($q->required)><strong>{{ $n }}</strong><span>{{ $text }}</span></label>
                                @endforeach
                            </fieldset>
                            <label class="low-comment">Apa yang bisa kami tingkatkan? <small>Komentar wajib jika memilih
                                    skor 1, 2, atau 3.</small>
                                <textarea name="answers[{{ $q->id }}][comment]" rows="3" maxlength="5000"
                                    placeholder="Ceritakan pengalaman dan saran Anda…">{{ $answer['comment'] ?? '' }}</textarea>
                            </label>
                        @elseif($q->type === 'choice')
                            <fieldset class="choice-group" aria-labelledby="question-{{ $q->id }}">
                                @foreach ($q->options ?? [] as $option)
                                    <label class="choice-option"><input type="radio"
                                            name="answers[{{ $q->id }}][value]" value="{{ $option }}"
                                            @checked(($answer['value'] ?? '') === $option) @required($q->required)>{{ $option }}</label>
                                @endforeach
                            </fieldset>
                        @else
                            <textarea aria-labelledby="question-{{ $q->id }}" name="answers[{{ $q->id }}][value]" rows="4"
                                maxlength="5000" placeholder="Tuliskan pendapat Anda…" @required($q->required)>{{ $answer['value'] ?? '' }}</textarea>
                        @endif
                    </section>
                @endforeach
                <div id="preview-success" class="notice success" role="status" hidden>Pratinjau selesai. Jawaban valid,
                    tetapi tidak disimpan.</div>
                <div class="survey-submit"><span>Masukan Anda digunakan untuk evaluasi layanan.</span>
                    <div>
                        @unless ($preview)
                            <button class="btn" type="submit" name="action" value="draft" formnovalidate>Simpan
                                draf</button>
                        @endunless
                        <button class="btn primary" type="submit" name="action" value="submit">
                            {{ $preview ? 'Coba kirim' : 'Kirim respons' }} →</button>
                    </div>
                </div>
                <p class="footnote">
                    @unless ($preview)
                        Tautan ini khusus untuk Anda. Draf dapat dilanjutkan melalui tautan yang sama.
                    @endunless Rating 1–3 memerlukan komentar agar kami dapat memahami masukan Anda.
                </p>
            </form>
        @endif
    </main>
</body>

</html>
