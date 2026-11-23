<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * يقبل دوراً واحداً أو أكثر، مثل: role:admin,supervisor
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403, 'غير مصرح لك بدخول هذه الصفحة.');
        }

        if (! $user->hasRole(...$roles)) {
            // المرحلة 6: للمندوب واجهته، فالمسارات الأخرى 403 كبقية الأدوار
            abort(403, 'غير مصرح لك بدخول هذه الصفحة.');
        }

        return $next($request);
    }
}
