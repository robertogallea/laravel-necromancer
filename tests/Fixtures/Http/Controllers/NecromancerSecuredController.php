<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Http\Controllers;

use Illuminate\Routing\Attributes\Controllers\Middleware as MiddlewareAttribute;
use Illuminate\Routing\Attributes\Controllers\WithoutMiddleware;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use RuntimeException;

#[MiddlewareAttribute('throttle:60,1')]
#[MiddlewareAttribute('signed', only: ['update'])]
final class NecromancerSecuredController implements HasMiddleware
{
    public function __construct()
    {
        throw new RuntimeException('Controllers must never be instantiated during collection.');
    }

    /**
     * @return list<Middleware|string>
     */
    public static function middleware(): array
    {
        return [
            'auth',
            new Middleware('verified', only: ['update']),
            new Middleware('log', except: ['index']),
        ];
    }

    #[WithoutMiddleware('auth')]
    public function index(): string
    {
        return 'index';
    }

    #[MiddlewareAttribute('can:update')]
    public function update(): string
    {
        return 'update';
    }
}
