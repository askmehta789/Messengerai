@extends('layouts.app')
@section('title', 'Forgot password')
@section('body-class', 'center-page')

@section('content')
<form method="post" action="{{ route('password.email') }}" class="card login-card">
    @csrf
    <span class="rail-logo">
        <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor"><path d="M12 2C6.36 2 2 6.13 2 11.7c0 2.91 1.19 5.44 3.14 7.17.16.15.26.35.27.57l.05 1.78c.02.57.6.94 1.12.71l1.98-.87c.17-.08.36-.09.53-.04.91.25 1.88.38 2.91.38 5.64 0 10-4.13 10-9.7S17.64 2 12 2zm6 7.46-2.94 4.66c-.47.74-1.47.93-2.17.4l-2.34-1.75a.6.6 0 0 0-.72 0l-3.16 2.4c-.42.32-.97-.18-.69-.63l2.94-4.66c.47-.74 1.47-.93 2.17-.4l2.34 1.75c.21.16.51.16.72 0l3.16-2.4c.42-.32.97.18.69.63z"/></svg>
    </span>
    <h1>Forgot password</h1>
    <p class="muted">Enter your email and we'll send you a link to reset your password.</p>

    <label>Email
        <input type="email" name="email" value="{{ old('email') }}" required autofocus>
    </label>

    @error('email')<p class="error">{{ $message }}</p>@enderror

    <button class="btn btn-primary btn-block" style="margin-top:8px">Send reset link</button>
    <p class="muted" style="margin-top:12px"><a href="{{ route('login') }}">Back to log in</a></p>
</form>
@endsection
