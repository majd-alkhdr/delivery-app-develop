<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        // المسار المباشر الكامل لكلاس الحماية الخاص بنا
        'role' => \App\Http\Middleware\CheckRole::class,
    ]);
    // قالب «بيان المالية» يُحفظ حرفياً (مسافته الأخيرة جزء منه): لا trim عليه
    $middleware->trimStrings(except: ['finance_statement_template', 'finance_rep_statement_template']);
    $middleware->trustProxies(
       at: '*',
       headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO
   );
})
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
