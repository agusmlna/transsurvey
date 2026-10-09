<!doctype html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ $survey->title }} · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/transsurvey.css') }}?v={{ filemtime(public_path('assets/transsurvey.css')) }}">
    <script defer src="{{ asset('assets/transsurvey.js') }}?v={{ filemtime(public_path('assets/transsurvey.js')) }}"></script>
    <script defer src="{{ asset('assets/app.js') }}?v={{ filemtime(public_path('assets/app.js')) }}"></script>
    @include('layouts.i18n')
</head>

<body class="respondent-page">
    <main class="survey-sheet">
        <div class="survey-top"><div class="brand-panel">
                @include('layouts.brand')
            </div>@include('layouts.language-switch')
        </div>
        @if ($preview)
            <div class="notice">{{ __('Mode pratinjau · Jawaban tidak disimpan') }}</div>
        @endif
        @if ($survey->is_demo)
            <div class="demo-label">{{ __('SURVEI CONTOH') }}</div>
        @endif
        <h1>{{ $survey->title }}</h1>
        <p class="survey-intro">{{ $survey->description }}</p>
        @if ($invitation)
            <p class="muted">{{ __('Untuk') }} <strong>{{ $invitation->client->name }}</strong></p>
        @endif
        @if (session('success'))
            <div class="notice success" role="status" data-success-message>{{ __(session('success')) }}</div>
        @endif
        @if ($errors->any())
            <div class="notice error" role="alert" data-error-message><strong>{{ __('Jawaban belum dikirim.') }}</strong>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if ($closed)
            <section class="empty">
                <h2>{{ __('Survei sedang tidak menerima respons.') }}</h2>
                <p>{{ __('Periode pengisian:') }} {{ $survey->starts_at->format('d M Y') }} –
                    {{ $survey->ends_at->format('d M Y') }}.</p>
            </section>
        @else
            @php($answers = old('answers', $invitation?->draft_answers ?? []))
            <div class="progress-caption"><span
                    id="survey-progress-text">{{ $survey->questions->where('required', true)->count() }} {{ __('pertanyaan wajib') }}</span><span>{{ __('± 3 menit') }}</span></div><progress class="survey-progress" id="survey-progress"
                value="0" max="100" aria-label="{{ __('Progres pengisian') }}"></progress>
            <form method="post" action="{{ $preview ? '#' : route('survey.submit', $invitation->token) }}"
                class="respondent-form" data-submit-feedback data-busy-text="{{ __('Sedang menyimpan jawaban…') }}" @if ($preview) data-preview @endif>@csrf
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
                                @foreach ([1 => __('Sangat tidak puas'), 2 => __('Tidak puas'), 3 => __('Cukup puas'), 4 => __('Puas'), 5 => __('Sangat puas')] as $n => $text)
                                    <label class="rating-option"><input type="radio"
                                            name="answers[{{ $q->id }}][value]" value="{{ $n }}"
                                            @checked((string) ($answer['value'] ?? '') === (string) $n)
                                            @required($q->required)><span class="ts-rating-star" aria-hidden="true">★</span><strong>{{ $n }}</strong><span>{{ $text }}</span></label>
                                @endforeach
                            </fieldset>
                            <label class="low-comment">{{ __('Apa yang bisa kami tingkatkan?') }} <small>{{ __('Komentar wajib jika memilih skor 1, 2, atau 3.') }}</small>
                                <textarea name="answers[{{ $q->id }}][comment]" rows="3" maxlength="5000"
                                    placeholder="{{ __('Ceritakan pengalaman dan saran Anda…') }}">{{ $answer['comment'] ?? '' }}</textarea>
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
                                maxlength="5000" placeholder="{{ __('Tuliskan pendapat Anda…') }}" @required($q->required)>{{ $answer['value'] ?? '' }}</textarea>
                        @endif
                    </section>
                @endforeach
                <div id="preview-success" class="notice success" role="status" hidden>{{ __('Pratinjau selesai. Jawaban valid, tetapi tidak disimpan.') }}</div>
                <div class="survey-submit"><span>{{ __('Masukan Anda digunakan untuk evaluasi layanan.') }}</span>
                    <div>
                        @unless ($preview)
                            <button class="btn" type="submit" name="action" value="draft" formnovalidate>{{ __('Simpan draf') }}</button>
                        @endunless
                        <button class="btn primary" type="submit" name="action" value="submit" @unless($preview) data-confirm="{{ __('Pastikan jawaban Anda sudah sesuai. Jawaban yang sudah dikirim tidak dapat diubah melalui tautan ini.') }}" data-confirm-title="{{ __('Kirim jawaban survei?') }}" data-confirm-button="{{ __('Ya, kirim') }}" @endunless>
                            {{ $preview ? __('Coba kirim') : __('Kirim respons') }} →</button>
                    </div>
                </div>
                <p class="footnote">
                    @unless ($preview)
                        {{ __('Tautan ini khusus untuk Anda. Draf dapat dilanjutkan melalui tautan yang sama.') }}
                    @endunless {{ __('Rating 1–3 memerlukan komentar agar kami dapat memahami masukan Anda.') }}
                </p>
            </form>
        @endif
    </main>
</body>

</html>
