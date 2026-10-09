<form method="post" action="{{ route('language.update') }}" class="ts-language-switch" data-language-switch aria-label="{{ __('Bahasa') }}">
    @csrf
    <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
    @foreach (['id' => 'Bahasa Indonesia', 'en' => 'English'] as $code => $name)
        <button type="submit" name="locale" value="{{ $code }}" lang="{{ $code }}"
            title="{{ $name }}" aria-label="{{ $name }}" aria-pressed="{{ app()->getLocale() === $code ? 'true' : 'false' }}"
            @disabled(app()->getLocale() === $code)>{{ strtoupper($code) }}</button>
    @endforeach
</form>
