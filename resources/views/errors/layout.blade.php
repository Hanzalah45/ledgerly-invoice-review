<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') · {{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite(['resources/css/app.scss'])
</head>
<body>
<div class="d-flex align-items-center justify-content-center text-center px-3" style="min-height:100vh">
    <div style="max-width:420px">
        <div class="mb-4">@include('partials.logo', ['size' => 40])</div>
        <div class="mono text-muted-2 mb-1">@yield('code')</div>
        <h1 class="h3 mb-2">@yield('heading')</h1>
        <p class="text-muted-2 mb-4">@yield('message')</p>
        <a href="{{ auth()->check() ? route('dashboard') : route('login') }}" class="btn btn-primary">Back to {{ auth()->check() ? 'dashboard' : 'sign in' }}</a>
    </div>
</div>
</body>
</html>
