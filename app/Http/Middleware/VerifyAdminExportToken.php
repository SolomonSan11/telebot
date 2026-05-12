<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyAdminExportToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('admin.export_token');
        if (! is_string($expected) || $expected === '') {
            abort(503, 'Admin exports are not configured (set ADMIN_EXPORT_TOKEN in .env).');
        }

        $provided = (string) $request->query('token', '');
        if ($provided === '') {
            $provided = (string) $request->header(config('admin.export_token_header', 'X-Admin-Export-Token'), '');
        }

        if (! hash_equals($expected, $provided)) {
            abort(403, 'Invalid or missing export token.');
        }

        return $next($request);
    }
}
