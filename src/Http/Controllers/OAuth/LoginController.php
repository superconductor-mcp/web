<?php

namespace SuperconductorMCP\SSE\Http\Controllers\OAuth;

use Mariner\Http\Request;
use Mariner\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The authorization server's own login page, used when `sse.authorization.server.login` is null: an email and
 * password checked by the configured session guard, then back to the authorization request that sent the person here.
 */
class LoginController
{
    public function show(Request $request): SymfonyResponse
    {
        if (auth()->guard(config('sse.authorization.server.session_guard', 'web'))->check()) {
            return redirect()->intended('/');
        }

        return new Response(view('sse::oauth.login')->render(), 200, ['X-Frame-Options' => 'DENY', 'Content-Security-Policy' => "frame-ancestors 'none'"]);
    }

    public function login(Request $request): SymfonyResponse
    {
        $email = $request->input('email');
        $password = $request->input('password');

        if (! is_string($email) || ! is_string($password) || $email === '' || $password === ''
            || ! auth()->guard(config('sse.authorization.server.session_guard', 'web'))->attempt(['email' => $email, 'password' => $password])) {
            return redirect()->back()->withInput(['email' => is_string($email) ? $email : ''])->withErrors(['email' => 'These credentials do not match our records.']);
        }

        // a new session id, so one fixed before login is useless afterwards
        $request->session()->regenerate();

        return redirect()->intended('/');
    }
}
