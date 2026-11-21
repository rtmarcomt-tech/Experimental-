<?php

namespace App\\Http\\Middleware;

use Closure;
use Illuminate\\Http\\Request;
use Symfony\\Component\\HttpFoundation\\Response;

class EnsureAccountType
{
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $user = $request->user();

        abort_unless($user && ($user->is_admin || $user->account_type === $type), 403, 'This account type cannot access this area.');

        return $next($request);
    }
}
