<?php

namespace App\Http\Middleware;

use App\Services\Access;
use Closure;
use Illuminate\Http\Request;

class ActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        Access::enrolled();

        return $next($request);
    }
}
