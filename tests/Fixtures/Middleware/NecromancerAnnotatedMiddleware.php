<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Middleware;

use Closure;
use Illuminate\Http\Request;
use LaravelNecromancer\Attributes\Necromancer;
use LaravelNecromancer\Metadata\Risk;
use Symfony\Component\HttpFoundation\Response;

#[Necromancer(domain: 'security', risk: Risk::High)]
final class NecromancerAnnotatedMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
