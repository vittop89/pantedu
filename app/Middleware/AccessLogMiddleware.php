<?php

namespace App\Middleware;

use App\Core\AccessLogger;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

final class AccessLogMiddleware
{
    public function handle(Request $req, callable $next): Response
    {
        $response = $next($req);

        if (Auth::check() && $response->status < 400) {
            (new AccessLogger())->logAccess(
                Auth::user()['username'] ?? 'unknown',
                Auth::role(),
                $req->server['REQUEST_URI'] ?? $req->path,
                'access'
            );
        }
        return $response;
    }
}
