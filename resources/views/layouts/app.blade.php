<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title','Dashboard') · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/transsurvey.css') }}?v={{ filemtime(public_path('assets/transsurvey.css')) }}">
    <script defer src="{{ asset('assets/app.js') }}"></script>
</head>

<body>
    <aside class="sidebar" id="sidebar">
        <a class="ts-brand-link" href="{{ route('dashboard') }}">
            @include('layouts.brand')
        </a>

        <div class="nav-label">MENU UTAMA</div>

        <div class="nav-label">WORKSPACE</div>
        <nav>
            @php($items = [['dashboard', 'dashboard', '◫', 'Dashboard', false], ['surveys.index', 'surveys.*', '▤', 'Kuesioner', true], ['clients.index', 'clients.*', '◎', 'Klien', true], ['invitations.index', 'invitations.*', '↗', 'Distribusi', true], ['responses.index', 'responses.*', '◷', 'Respons', false], ['bank.index', 'bank.*', '▥', 'Bank Pertanyaan', true], ['reports.index', 'reports.*', '▥', 'Laporan', false], ['followups.index', 'followups.*', '✓', 'Tindak Lanjut', false]])
            @foreach ($items as [$route, $pattern, $icon, $label, $admin])
                @if (!$admin || auth()->user()->role === 'admin')
                    <a class="nav-item {{ request()->routeIs($pattern) ? 'active' : '' }}" href="{{ route($route) }}"><span
                            class="nav-icon">{{ $icon }}</span>{{ $label }}</a>
                @endif
            @endforeach
        </nav>
        <div class="sidebar-note"><span>◌</span><strong>Setiap suara berarti.</strong>
            <p>Ubah masukan klien menjadi layanan yang lebih baik.</p>
        </div>
        <div class="profile"><span class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 2)) }}</span>
            <div>
                <strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->role === 'admin' ? 'Administrator' : 'Viewer' }}</small>
            </div>
            <form method="post" action="{{ route('logout') }}">@csrf<button class="logout" title="Keluar"
                    aria-label="Keluar">↪</button></form>
        </div>
    </aside>
    <div class="shell">
        <header class="topbar">
            <div class="breadcrumb"><button class="menu-button" data-toggle-menu
                    aria-label="Buka navigasi">☰</button><span>Workspace</span><span>›</span><strong>@yield('title', 'Dashboard')</strong>
            </div>
            <div class="topbar-meta"><i></i> Client Experience <span
                    class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 2)) }}</span></div>
        </header>
        <main class="workspace">
            <div class="page-heading">
                <div>
                    <div class="eyebrow">CLIENT EXPERIENCE / WORKSPACE</div>
                    <h1>@yield('heading', 'Dashboard')</h1>
                    <p>@yield('subtitle', 'Kelola pengalaman dan kepuasan klien Anda.')</p>
                </div>
                <div class="heading-actions">@yield('actions')</div>
            </div>
            @if (session('success'))
                <div class="notice success" role="status">✓ {{ session('success') }}</div>
            @endif
            @if ($errors->any())
                <div class="notice error" role="alert"><strong>Periksa kembali input Anda.</strong>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @yield('content')
            <footer class="workspace-footer"><strong>{{ config('app.name') }}</strong><span>Dari feedback, untuk layanan yang
                    lebih baik.</span><span class="footer-right">Client Satisfaction Management</span></footer>
        </main>
    </div>
    <div class="toast" id="toast" role="status" hidden></div>@stack('scripts')
</body>

</html>
