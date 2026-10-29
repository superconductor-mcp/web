@extends('sse::oauth.layout')

@section('title', 'Authorize '.$client)

@section('content')
    <h1>Allow {{ $client }} to use this MCP server?</h1>
    <p>Signed in as <strong>{{ $user->email ?? $user->name ?? $user->getAuthIdentifier() }}</strong>.</p>
    @if ($client_host)
        <p class="muted">This client identifies itself as <strong>{{ $client_host }}</strong>.</p>
    @else
        <p class="muted">This client registered itself; its name is not verified.</p>
    @endif
    <p class="muted">After you answer you will be sent to <strong>{{ $redirect_host }}</strong>. Only approve if you started this from an application you trust.</p>
    <p>It will act as you on <code>{{ $resource }}</code>.</p>
    @if ($scopes !== [])
        <p>With these scopes:</p>
        <ul>
            @foreach ($scopes as $scope)
                <li><code>{{ $scope }}</code></li>
            @endforeach
        </ul>
    @endif
    <form method="POST" action="{{ $action }}">
        @csrf
        <input type="hidden" name="request" value="{{ $request_id }}">
        <div class="actions">
            <button type="submit" name="decision" value="deny">Deny</button>
            <button type="submit" name="decision" value="approve" class="primary">Approve</button>
        </div>
    </form>
@endsection
