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
            abort(503, 'Order exports aren’t available right now.');
        }

        $provided = (string) $request->query('token', '');
        if ($provided === '') {
            $provided = (string) $request->header(config('admin.export_token_header', 'X-Admin-Export-Token'), '');
        }

        if (! hash_equals($expected, $provided)) {
            abort(403, 'This link isn’t valid or has expired.');
        }

        return $next($request);
    }
}
