@extends('sse::oauth.layout')

@section('title', 'Authorization failed')

@section('content')
    <h1>This request cannot be authorized</h1>
    <p>{{ $description }}</p>
    <p class="muted"><code>{{ $error }}</code></p>
@endsection
