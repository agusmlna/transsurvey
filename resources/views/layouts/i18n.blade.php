<link rel="stylesheet" href="{{ asset('assets/transsurvey-language.css') }}?v={{ filemtime(public_path('assets/transsurvey-language.css')) }}">
<script>
    window.TransSurveyI18n = {
        locale: {{ Illuminate\Support\Js::from(app()->getLocale()) }},
        messages: {{ Illuminate\Support\Js::from(app()->getLocale() === 'en' ? json_decode(file_get_contents(lang_path('en.json')), true, 512, JSON_THROW_ON_ERROR) : (object) []) }},
        t: function (key, values) {
            var text = Object.prototype.hasOwnProperty.call(this.messages, key) ? this.messages[key] : key;
            return text.replace(/:([a-zA-Z_]+)/g, function (match, name) {
                return values && Object.prototype.hasOwnProperty.call(values, name) ? String(values[name]) : match;
            });
        }
    };
</script>
<script defer src="{{ asset('assets/transsurvey-language.js') }}?v={{ filemtime(public_path('assets/transsurvey-language.js')) }}"></script>
