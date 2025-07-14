<?php

namespace MCP\Transports\SSE\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;

class McpSseMessages
{
    use AsController;

    public function rules(): array
    {
        return [
            'session_id' => 'required|string',
        ];
    }

    public function asController(ActionRequest $request): array
    {
        $token = $request->get('session_id');
        $req = $request->all();

        return $this->handle($token, $req);
    }

    public function handle(string $token, array $message): array
    {
        Cache::put("incoming-message-for-{$token}", $message);
        return [];
    }

    public function jsonResponse(array $result): Response
    {
        return response(null, 202);
    }

}
