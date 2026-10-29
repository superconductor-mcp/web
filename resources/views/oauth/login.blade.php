@extends('sse::oauth.layout')

@section('title', 'Sign in')

@section('content')
    <h1>Sign in</h1>
    <p class="muted">An application is asking to connect to your account.</p>
    <form method="POST">
        @csrf
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        <label for="password">Password</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required>
        @if ($errors->has('email'))
            <p class="error" role="alert">{{ $errors->first('email') }}</p>
        @endif
        <div class="actions">
            <button type="submit" class="primary">Sign in</button>
        </div>
    </form>
@endsection
