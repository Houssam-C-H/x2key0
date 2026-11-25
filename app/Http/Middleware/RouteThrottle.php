<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * `throttle:N,M` with one counter per route (spec Part 23 "Rate limits"). Laravel's default numeric throttle keys only
 * by user or IP, so every route sharing it shares one counter: 30 chat messages would also block checkout. Here the
 * route name (or method + URI) is part of the key. Named limiters (`throttle:login`) are unchanged.
 */
final class RouteThrottle extends ThrottleRequests
{
    protected function resolveRequestSignature($request): string
    {
        $route = $request->route();
        $scope = $route === null ? $request->path() : ($route->getName() ?? $request->method().' '.$route->uri());

        return sha1(parent::resolveRequestSignature($request).'|'.$scope);
    }
}
