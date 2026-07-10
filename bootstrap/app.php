<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        /*
         * Routes served in the context of a single campaign: everything the web
         * group provides, plus resolution of the campaign from the request's
         * Host header.
         *
         * This exists as a named group so that Fortify can be pointed at it from
         * config/fortify.php, which is where it reads the middleware for the auth
         * routes it registers. Operators live in their campaign's own database,
         * so signing in has to happen with that database connected.
         *
         * Both guards are raised to the highest middleware priority by
         * TenancyServiceProvider, so they run ahead of the web group whatever
         * order they are listed in here. The listing still reads in execution
         * order: refuse central hosts first, then identify the campaign.
         */
        $middleware->group('tenant', [
            'web',
            PreventAccessFromCentralDomains::class,
            InitializeTenancyByDomain::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
