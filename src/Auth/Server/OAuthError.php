<?php

namespace SuperconductorMCP\SSE\Auth\Server;

use Mariner\Http\JsonResponse;
use RuntimeException;

/** An OAuth error response (RFC 6749 §5.2): an `error` code and a description, at $status. */
final class OAuthError extends RuntimeException
{
    // where an authorization request's error is sent, once its client and redirect URI are known good
    public ?string $redirect_uri = null;

    public ?string $state = null;

    public function __construct(public readonly string $error, string $description, public readonly int $status = 400)
    {
        parent::__construct($description);
    }

    public function redirectingTo(string $redirect_uri, ?string $state): self
    {
        $this->redirect_uri = $redirect_uri;
        $this->state = $state;

        return $this;
    }

    public function toResponse(): JsonResponse
    {
        $headers = ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'];

        // RFC 6749 §5.2: a client that tried HTTP Basic is told which scheme failed
        if ($this->status === 401) {
            $headers['WWW-Authenticate'] = 'Basic realm="mcp"';
        }

        return new JsonResponse(['error' => $this->error, 'error_description' => $this->getMessage()], $this->status, $headers);
    }
}
