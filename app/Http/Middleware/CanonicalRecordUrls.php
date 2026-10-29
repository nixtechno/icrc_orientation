<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class CanonicalRecordUrls
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        // Redirect legacy document links after access checks; API/AJAX requests remain compatible.
        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200 || $request->header('Sec-Fetch-Mode') !== 'navigate') return $response;
        $route = $request->route();
        $original = $route?->originalParameters() ?? [];
        $changed = false;
        $path = preg_replace_callback('/\{([^}]+)\}/', function ($match) use ($route, $original, &$changed) {
            $name = rtrim($match[1], '?');
            $value = $route->parameter($name);
            if ($value instanceof Model && $value->getRouteKeyName() === 'slug') {
                $key = $value->getRouteKey();
                $changed = $changed || (string) ($original[$name] ?? '') !== (string) $key;
                return rawurlencode((string) $key);
            }
            return rawurlencode((string) ($original[$name] ?? $value));
        }, $route->uri());
        return $changed ? redirect(url($path).($request->getQueryString() ? '?'.$request->getQueryString() : ''), 301) : $response;
    }
}
