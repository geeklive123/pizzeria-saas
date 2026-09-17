<?php

use App\Http\Middleware\AuthenticatePrintAgent;
use App\Http\Middleware\ResolveBranchContext;
use App\Http\Middleware\ResolveCompanyContext;
use App\Http\Middleware\TrackUserAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'company.context' => ResolveCompanyContext::class,
            'branch.context' => ResolveBranchContext::class,
            'print.agent' => AuthenticatePrintAgent::class,
            'user.access' => TrackUserAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
