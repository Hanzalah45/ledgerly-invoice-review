<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · {{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite(['resources/css/app.scss', 'resources/js/app.js'])
</head>
<body>
<div class="auth-wrap">
    <section class="auth-brand">
        <div class="brand p-0">
            @include('partials.logo', ['size' => 30])
            <span><span class="word">{{ config('app.name') }}</span><span class="sub">Invoice intelligence</span></span>
        </div>

        <div>
            <div class="eyebrow mb-3 text-muted-2">Accounts payable</div>
            <h1>Every invoice, read and checked before it counts.</h1>
            <p class="lede">Ledgerly extracts supplier, dates, totals and line items from PDFs, checks the arithmetic, and holds each record for a person to approve.</p>

            <div class="doc-mock" aria-hidden="true">
                <span class="stamp">Sample</span>
                <div class="sup">Northwind Freight Ltd</div>
                <div class="dl"><span>Invoice</span><b><span class="hl">INV-2026-0412</span></b></div>
                <div class="dl"><span>Invoice date</span><b><span class="hl">02 Sep 2026</span></b></div>
                <div class="rule"></div>
                <div class="dl"><span>Container drayage × 4</span><b>£1,540.00</b></div>
                <div class="dl"><span>Customs clearance × 4</span><b>£260.00</b></div>
                <div class="dl"><span>Demurrage × 2</span><b>£240.00</b></div>
                <div class="rule"></div>
                <div class="dl"><span>VAT (20%)</span><b>£408.00</b></div>
                <div class="dl"><span>Total due</span><b><span class="hl">£2,448.00</span></b></div>
                <div class="chips"><span class="chip"><i></i>Totals reconcile</span><span class="chip"><i></i>No duplicate found</span><span class="chip warn"><i></i>Awaiting approval</span></div>
            </div>
        </div>

        <div class="fs-xs text-muted-2">A CoreHives portfolio project. Sample data only.</div>
    </section>

    <section class="auth-form">
        <div class="box">
            <div class="d-lg-none mb-4 brand p-0">@include('partials.logo', ['size' => 30])<span class="word">{{ config('app.name') }}</span></div>
            @if(config('docai.public_demo'))
                <h2 class="mb-1">Try the demo</h2>
                <p class="text-muted-2 mb-4">Opens a reviewer session in a sample workspace. No sign-up, nothing to type.</p>
                <form method="post" action="{{ route('demo.login') }}">
                    @csrf
                    <button class="btn btn-primary w-100" type="submit">Enter the demo workspace</button>
                </form>
                <ul class="fs-xs text-muted-2 mt-4 ps-3 mb-0">
                    <li>Synthetic sample data only. Please do not upload real invoices.</li>
                    <li>Uploads are limited to 3 PDFs of 2 MB, and the data resets daily.</li>
                    <li>Extraction here uses the rule-based demo parser, not an AI model.</li>
                    <li>The first load after a quiet spell can take about a minute.</li>
                </ul>
            @else
            <h2 class="mb-1">Sign in</h2>
            <p class="text-muted-2 mb-4">Use your workspace account to continue.</p>

            <form method="post" action="{{ route('login.attempt') }}" novalidate>
                @csrf
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" autocomplete="username" required autofocus>
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror" autocomplete="current-password" required>
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1">
                    <label class="form-check-label" for="remember">Keep me signed in</label>
                </div>
                <button class="btn btn-primary w-100" type="submit">Sign in</button>
            </form>

            @if(app()->environment('local'))
                <div class="eyebrow mt-5 mb-2">Local demo accounts</div>
                <div class="demo-accounts">
                    <button type="button" data-demo-login="admin@ledgerly.test"><span><strong>Admin</strong> · settings, delete</span><span class="mono">admin@ledgerly.test</span></button>
                    <button type="button" data-demo-login="reviewer@ledgerly.test"><span><strong>Reviewer</strong> · edit, approve</span><span class="mono">reviewer@ledgerly.test</span></button>
                    <button type="button" data-demo-login="viewer@ledgerly.test"><span><strong>Viewer</strong> · read-only</span><span class="mono">viewer@ledgerly.test</span></button>
                </div>
                <p class="fs-xs text-muted-2 mt-2">Seeded for local development only. Password: <span class="mono">password</span></p>
            @endif
            @endif
        </div>
    </section>
</div>
</body>
</html>
