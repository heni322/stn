<?php

// app/Http/Middleware/RoleMiddleware.php
namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, $role)
    {
        if (!$request->user()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user = User::find($request->user()->id);
        $roles = $user->getRoleNames()->toArray(); // Get user roles as an array

        if (!in_array(Str::lower($role), array_map('strtolower', $roles))) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return $next($request);

        return $next($request);
    }
}
