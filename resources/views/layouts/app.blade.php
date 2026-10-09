<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · TransSurvey</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/transsurvey.css') }}?v={{ filemtime(public_path('assets/transsurvey.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/transsurvey-motion.css') }}?v={{ filemtime(public_path('assets/transsurvey-motion.css')) }}">
    <script defer src="{{ asset('assets/transsurvey.js') }}?v={{ filemtime(public_path('assets/transsurvey.js')) }}"></script>
    <script defer src="{{ asset('assets/app.js') }}?v={{ filemtime(public_path('assets/app.js')) }}"></script>
    <link rel="stylesheet" href="{{ asset('assets/vendor/datatables/dataTables.dataTables.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/transsurvey-tables.css') }}?v={{ filemtime(public_path('assets/transsurvey-tables.css')) }}">
    @stack('styles')
    <script src="{{ asset('assets/transsurvey-tables-boot.js') }}"></script>
    @include('layouts.i18n')
</head>
<body>
    <a class="ts-skip" href="#main-content">{{ __('Lewati ke konten') }}</a>
    <aside class="sidebar" id="sidebar" aria-label="{{ __('Navigasi utama') }}">
        <a class="ts-brand-link" href="{{ route('dashboard') }}" aria-label="TransSurvey — Dashboard">@include('layouts.brand')</a>
        <div class="nav-label">{{ __('MENU UTAMA') }}</div>
        <nav>
            @php($items = [['dashboard', 'dashboard', 'dashboard', 'Dashboard', false], ['surveys.index', 'surveys.*', 'survey', __('Survei'), true], ['clients.index', 'clients.*', 'clients', __('Klien'), true], ['invitations.index', 'invitations.*', 'mail', __('Undangan'), true], ['responses.index', 'responses.*', 'response', __('Respons'), false], ['bank.index', 'bank.*', 'bank', __('Bank Pertanyaan'), true], ['reports.index', 'reports.*', 'report', __('Laporan'), false], ['followups.index', 'followups.*', 'follow', __('Tindak Lanjut'), false]])
            @foreach ($items as [$route, $pattern, $icon, $label, $admin])
                @if (!$admin || auth()->user()->role === 'admin')
                    <a class="nav-item {{ request()->routeIs($pattern) ? 'active' : '' }}" href="{{ route($route) }}" @if(request()->routeIs($pattern)) aria-current="page" @endif>
                        <span class="nav-icon">@include('layouts.icon', ['name' => $icon])</span>{{ $label }}
                    </a>
                @endif
            @endforeach
        </nav>
        <div class="ts-sidebar-footer"><span class="ts-sidebar-dot"></span> Client Satisfaction Management</div>
        <div class="profile">
            <span class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->role === 'admin' ? 'Administrator' : 'Viewer' }}</small></div>
            <form method="post" action="{{ route('logout') }}">@csrf<button class="logout" title="{{ __('Keluar') }}" aria-label="{{ __('Keluar') }}">@include('layouts.icon', ['name' => 'logout'])</button></form>
        </div>
    </aside>
    <button class="ts-menu-backdrop" type="button" data-close-menu aria-label="{{ __('Tutup navigasi') }}" tabindex="-1" hidden></button>
    <div class="shell">
        <header class="topbar">
            <div class="breadcrumb"><button class="menu-button" type="button" data-toggle-menu aria-controls="sidebar" aria-expanded="false" aria-label="{{ __('Buka navigasi') }}">@include('layouts.icon', ['name' => 'menu'])</button><span>Workspace</span><span aria-hidden="true">/</span><strong>@yield('title', 'Dashboard')</strong></div>
            <div class="topbar-meta">@include('layouts.language-switch')<span>Client Experience</span><span class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 2)) }}</span></div>
        </header>
        <main class="workspace" id="main-content" tabindex="-1">
            <div class="page-heading"><div><h1>@yield('heading', 'Dashboard')</h1><p>@yield('subtitle', __('Kelola survei dan hasil kepuasan klien.'))</p></div><div class="heading-actions">@yield('actions')</div></div>
            @if (session('success'))
                <div class="notice success ts-flash" role="status" data-success-message><span class="ts-notice-icon">✓</span><div><strong>{{ __('Berhasil') }}</strong><p>{{ __(session('success')) }}</p></div><button type="button" data-dismiss-notice aria-label="{{ __('Tutup pemberitahuan') }}">×</button></div>
            @endif
            @if ($errors->any())
                <div class="notice error" role="alert" data-error-message><strong>{{ __('Periksa kembali input Anda.') }}</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            @yield('content')
            <footer class="workspace-footer"><strong>TransSurvey</strong><span>Client Satisfaction Management</span><span class="footer-right">PT Transcosmos Indonesia</span></footer>
        </main>
    </div>
    <div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
    <script src="{{ asset('assets/vendor/datatables/jquery-3.7.1.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/datatables/dataTables.min.js') }}"></script>
    <script src="{{ asset('assets/transsurvey-tables.js') }}?v={{ filemtime(public_path('assets/transsurvey-tables.js')) }}"></script>
    @stack('scripts')
</body>
</html>
