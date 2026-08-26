<?php

namespace App\Http\Middleware;

use App\Models\PrintAgent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePrintAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('thermal-printing.agent.require_https') && ! $request->isSecure()) {
            return response()->json(['message' => 'El agente de impresión requiere HTTPS.'], 400);
        }

        $token = $request->bearerToken();
        if (! is_string($token) || strlen($token) < 48) {
            return response()->json(['message' => 'Token de agente inválido.'], 401);
        }

        $agent = PrintAgent::query()->where('token_hash', hash('sha256', $token))
            ->where('is_active', true)->whereNull('revoked_at')->first();
        if (! $agent) {
            return response()->json(['message' => 'Token de agente inválido.'], 401);
        }

        $agent->forceFill(['last_seen_at' => now()])->save();
        $request->attributes->set('print_agent', $agent);

        return $next($request);
    }
}
