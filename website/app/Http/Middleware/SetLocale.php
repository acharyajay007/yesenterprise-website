<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route('locale');
        abort_unless(in_array($locale, config('website.locales'), true), 404);
        app()->setLocale($locale);
        View::share('locale', $locale);

        return $next($request);
    }
}
