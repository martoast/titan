<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use Illuminate\Http\Request;

/**
 * In-account management of personal API tokens — the credential a user generates and hands to an
 * external agent (Claude via MCP) so it can run their Titan account. The plaintext is shown once.
 */
class ApiTokenController extends Controller
{
    public function index(Request $request)
    {
        return view('connect.index', [
            'tokens' => $request->user()->apiTokens()->latest()->get(),
            'plain' => session('plain_token'),       // the just-minted secret, shown once
            'apiBase' => rtrim(config('app.url', url('/')), '/').'/api',
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'scope' => ['required', 'in:full,read'],
        ]);

        [$token, $plain] = ApiToken::mint(
            $request->user(),
            $data['name'],
            $data['scope'] === 'read' ? ['read'] : ['*'],
        );

        return redirect()->route('connect.index')
            ->with('plain_token', $plain)
            ->with('status', "Token \"{$token->name}\" created — copy it now, it won't be shown again.");
    }

    public function destroy(Request $request, ApiToken $token)
    {
        abort_unless($token->user_id === $request->user()->id, 404);
        $token->delete();

        return redirect()->route('connect.index')->with('status', 'Token revoked.');
    }
}
