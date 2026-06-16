<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Services\Assistant\AssistantTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The assistant/MCP API. Authenticated by a personal API token (Bearer) — see
 * {@see \App\Http\Middleware\AuthenticateApiToken}. Exposes the whole Titan account through one
 * generic tool dispatcher so an external agent can read every metric and write logs without the web UI.
 */
class AssistantController extends Controller
{
    /** Identify the token + owner (a cheap "is my token valid" probe). */
    public function me(Request $request): JsonResponse
    {
        $token = $request->attributes->get('api_token');

        return response()->json([
            'user' => ['name' => $request->user()->name, 'email' => $request->user()->email],
            'token' => ['name' => $token?->name, 'abilities' => $token?->abilities ?? ['*']],
        ]);
    }

    /** The tool catalog — the MCP server turns these into MCP tools. */
    public function tools(Request $request): JsonResponse
    {
        return response()->json(['tools' => (new AssistantTools($request->user()))->schemas()]);
    }

    /** Run one tool: {tool, args} → result. Write tools require the 'write' (or '*') ability. */
    public function call(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tool' => ['required', 'string', 'max:64'],
            'args' => ['nullable', 'array'],
        ]);

        $tools = new AssistantTools($request->user());
        $schema = collect($tools->schemas())->firstWhere('name', $data['tool']);
        if (! $schema) {
            return response()->json(['error' => 'unknown_tool', 'tool' => $data['tool']], 404);
        }

        /** @var ApiToken|null $token */
        $token = $request->attributes->get('api_token');
        if (($schema['write'] ?? false) && $token && ! $token->can('write')) {
            return response()->json(['error' => 'insufficient_ability', 'required' => 'write', 'tool' => $data['tool']], 403);
        }

        return response()->json([
            'tool' => $data['tool'],
            'result' => $tools->dispatch($data['tool'], $data['args'] ?? []),
        ]);
    }
}
