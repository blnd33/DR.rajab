<?php

use App\Http\Middleware\AutoLoginOnTestSite;
use App\Http\Middleware\EnsureOpsAccess;
use App\Http\Middleware\NormalizeDigits;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackUserSession;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware(['web', 'auth', 'track.session', 'ops'])
                ->prefix('app')
                ->name('ops.')
                ->group(base_path('routes/ops.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            // Test site only (ORTHOTRACK_AUTO_LOGIN): sign visitors in automatically.
            AutoLoginOnTestSite::class,
            SetLocale::class,
            NormalizeDigits::class,
        ]);

        // Laravel moves its "who are you" check early; the automatic sign-in must come first.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: AutoLoginOnTestSite::class);

        $middleware->alias([
            'ops' => EnsureOpsAccess::class,
            'track.session' => TrackUserSession::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));

        // Behind Nginx on the VPS.
        $middleware->trustProxies(at: '127.0.0.1');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('app/sync/*') || $request->expectsJson(),
        );
    })->create();
