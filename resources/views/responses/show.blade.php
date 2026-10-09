@extends('layouts.app')
@section('title', __('Detail Respons'))
@section('heading', $response->client->name)
@section('subtitle', $response->survey->title)
@section('actions')<a class="btn" href="{{ route('responses.index') }}">
    {{ __('Kembali') }}</a>@endsection
@section('content')<div class="detail-score"><strong>{{ $response->score === null ? '—' : number_format($response->score, 2) }}
            <small>/ 5</small></strong>
        <div>{{ __('Skor kepuasan') }}<small>{{ __('Dikirim') }} {{ $response->submitted_at->format('d M Y H:i') }} WIB</small></div>
    </div>
    <section class="panel form-panel">
        @foreach ($response->answers as $a)
            <div class="answer-detail"><span class="eyebrow">{{ $a->category }}</span>
                <h3>{{ $loop->iteration }}. {{ $a->question_text }}</h3>
                <p>{{ $a->value !== null && $a->value !== '' ? $a->value : __('Tidak diisi') }}{{ $a->type === 'rating' && $a->value ? ' / 5' : '' }}
                </p>
                @if ($a->comment)
                    <blockquote>{{ $a->comment }}</blockquote>
                @endif
            </div>
        @endforeach
    </section>
    @if ($response->followUp)
        <div class="notice">{{ __('Respons ini memiliki tindak lanjut.') }} @if (auth()->user()->role === 'admin')
                <a href="{{ route('followups.edit', $response->followUp) }}">{{ __('Kelola tindak lanjut →') }}</a>
            @endif
        </div>
    @endif @endsection
