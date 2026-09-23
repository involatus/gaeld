<?php

namespace App\Domains\TachlyIntegration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Real pain today, twice: uncaught exception under /internal/tachly/* renders
 * as an opaque Inertia HTML error page (route lives outside bootstrap/app.php's
 * api/* exception scope), even with APP_DEBUG on. Diagnosing meant editing the
 * controller to catch(Throwable) + dump detail, deploy, curl, revert, deploy
 * again — full cycle twice this session for two different bugs.
 *
 * Catches here instead: always JSON (never Inertia HTML) for this route
 * group, and includes message/exception/file/line/trace when APP_DEBUG is
 * on — same debug gate Laravel's own handler uses, no code edit needed next
 * time.
 */
class RenderInternalExceptionsAsJson
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } catch (Throwable $e) {
            $body = ['message' => $e->getMessage() ?: 'Server Error'];

            if (config('app.debug')) {
                $body['exception'] = get_class($e);
                $body['file'] = $e->getFile();
                $body['line'] = $e->getLine();
                $body['trace'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 15);
            }

            return response()->json($body, 500);
        }
    }
}
