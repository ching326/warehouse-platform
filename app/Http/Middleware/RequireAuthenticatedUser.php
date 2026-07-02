<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireAuthenticatedUser
{
    public function handle(Request $request, Closure $next): Response
    {
        // TEMPORARY: auto-login a default internal user so the site can be
        // accessed without a login screen for rental-server testing. Security
        // is handled by the rental server's access control. Remove this block
        // and restore the abort(403) below to re-enable real authentication.
        if (! Auth::check()) {
            $user = User::query()
                ->where('user_type', 'internal')
                ->where('is_active', true)
                ->first();

            if ($user) {
                Auth::login($user);
            }
        }
        // END TEMPORARY

        if (! Auth::check() || ! Auth::user()?->is_active) {
            abort(403);
        }

        return $next($request);
    }
}
