<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite(['resources/css/app.scss', 'resources/js/app.js'])
</head>
<body>
@php($user = auth()->user())
<div class="app-shell">
    <aside class="app-sidebar offcanvas-lg offcanvas-start" id="sidebar" tabindex="-1" aria-label="Main navigation">
        <a href="{{ route('dashboard') }}" class="brand">
            @if($brandLogo)
                <img src="{{ route('brand.logo') }}" alt="" class="brand-logo">
            @else
                @include('partials.logo', ['size' => 28])
            @endif
            <span><span class="word">{{ $brandName }}</span><span class="sub">{{ $brandLogo || $brandName !== config('app.name') ? 'Invoices · '.config('app.name') : 'Invoice intelligence' }}</span></span>
        </a>

        <nav class="flex-grow-1 overflow-auto pb-2">
            <div class="nav-section">Workspace</div>
            <a class="side-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><i class="bi bi-grid-1x2"></i> Dashboard</a>
            <a class="side-link {{ request()->routeIs('documents.index', 'documents.show') ? 'active' : '' }}" href="{{ route('documents.index') }}">
                <i class="bi bi-files"></i> Documents
                @if($navAwaiting > 0)<span class="count" title="Awaiting review">{{ $navAwaiting }}</span>@endif
            </a>
            @can('create', \App\Models\Document::class)
                <a class="side-link {{ request()->routeIs('documents.create') ? 'active' : '' }}" href="{{ route('documents.create') }}"><i class="bi bi-cloud-arrow-up"></i> Upload</a>
            @endcan

            <div class="nav-section">Procurement</div>
            <a class="side-link {{ request()->routeIs('purchase-orders.*') ? 'active' : '' }}" href="{{ route('purchase-orders.index') }}"><i class="bi bi-journal-check"></i> Purchase orders</a>
            <a class="side-link {{ request()->routeIs('suppliers.*') ? 'active' : '' }}" href="{{ route('suppliers.index') }}"><i class="bi bi-buildings"></i> Suppliers</a>

            <div class="nav-section">Data</div>
            <a class="side-link {{ request()->routeIs('exports.*') ? 'active' : '' }}" href="{{ route('exports.index') }}"><i class="bi bi-box-arrow-down"></i> Exports</a>
            <a class="side-link {{ request()->routeIs('activity') ? 'active' : '' }}" href="{{ route('activity') }}"><i class="bi bi-clock-history"></i> Activity</a>

            @can('manage-settings')
                <div class="nav-section">Administration</div>
                <a class="side-link {{ request()->routeIs('settings') ? 'active' : '' }}" href="{{ route('settings') }}"><i class="bi bi-sliders"></i> Settings</a>
                <a class="side-link {{ request()->routeIs('departments.*') ? 'active' : '' }}" href="{{ route('departments.index') }}"><i class="bi bi-diagram-3"></i> Departments</a>
            @endcan
        </nav>

        <div class="side-foot">
            <div class="mode-chip">
                <span class="dot {{ $navProvider->isDemo() ? 'demo' : 'live' }}"></span>
                <div>
                    @if($navProvider->isDemo())
                        <strong>Demo mode</strong>
                        <span>Rule-based parser. Output is not produced by an AI model.</span>
                    @else
                        <strong>Live AI: {{ ucfirst($navProvider->name()) }}</strong>
                        <span class="mono">{{ $navProvider->model() }}</span>
                    @endif
                </div>
            </div>
        </div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <button class="btn btn-outline-secondary btn-sm d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Open navigation">
                <i class="bi bi-list fs-5"></i>
            </button>
            <nav aria-label="breadcrumb" class="d-none d-md-block">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ config('app.name') }}</a></li>
                    @yield('crumbs')
                </ol>
            </nav>

            <form class="topbar-search" action="{{ route('documents.index') }}" method="get" role="search">
                <i class="bi bi-search"></i>
                <input type="search" name="q" class="form-control form-control-sm" placeholder="Search supplier, invoice no. or file" value="{{ request()->routeIs('documents.index') ? request('q') : '' }}" aria-label="Search documents">
            </form>

            <div class="dropdown">
                <button class="btn btn-link p-0 d-flex align-items-center gap-2 text-decoration-none" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu">
                    <span class="avatar">{{ $user->initials() }}</span>
                    <span class="d-none d-md-block text-start lh-sm">
                        <span class="d-block fw-medium text-body fs-sm">{{ $user->name }}</span>
                        <span class="d-block fs-xs text-muted-2">{{ $user->roleLabel() }}</span>
                    </span>
                    <i class="bi bi-chevron-down fs-xs text-muted-2 d-none d-md-block"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li class="px-3 py-2 fs-xs text-muted-2">{{ $user->email }}</li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li>
                        <form method="post" action="{{ route('logout') }}">
                            @csrf
                            <button class="dropdown-item"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button>
                        </form>
                    </li>
                </ul>
            </div>
        </header>

        <main class="app-content">
            @if(config('docai.public_demo'))
                <div class="demo-banner" role="note"><i class="bi bi-info-circle-fill"></i>
                    <div><strong>Public demo.</strong> Synthetic data only, so please do not upload real invoices. Data resets daily; uploads are limited to 3 PDFs of 2 MB.</div>
                </div>
            @endif
            @yield('content')
        </main>
    </div>
</div>

{{-- Flash messages --}}
<div class="toast-stack" aria-live="polite">
    @foreach(['success' => ['check-circle-fill', 'text-success'], 'warning' => ['exclamation-triangle-fill', 'text-warning'], 'error' => ['x-octagon-fill', 'text-danger']] as $type => [$icon, $tone])
        @if(session($type))
            <div class="toast align-items-center" role="status" data-bs-delay="{{ $type === 'success' ? 5000 : 9000 }}">
                <div class="toast-body"><i class="bi bi-{{ $icon }} {{ $tone }}"></i><div class="flex-grow-1">{{ session($type) }}</div>
                    <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="toast" aria-label="Close"></button></div>
            </div>
        @endif
    @endforeach
    @if($errors->any() && ! request()->routeIs('login'))
        <div class="toast align-items-center" role="alert" data-bs-delay="10000">
            <div class="toast-body"><i class="bi bi-x-octagon-fill text-danger"></i>
                <div class="flex-grow-1">{{ $errors->count() === 1 ? $errors->first() : 'Please fix the '.$errors->count().' highlighted problems and try again.' }}</div>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="toast" aria-label="Close"></button></div>
        </div>
    @endif
</div>

{{-- Shared confirmation dialog --}}
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0"><h2 class="modal-title fs-6" id="confirmTitle">Are you sure?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body pt-2 text-muted-2" id="confirmBody"></div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmAction">Confirm</button>
            </div>
        </div>
    </div>
</div>
@stack('modals')
</body>
</html>
