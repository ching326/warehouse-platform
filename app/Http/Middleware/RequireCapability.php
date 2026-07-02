<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireCapability
{
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $user = Auth::user();

        if (! $user || ! $user->is_active || ! $this->allows($capability)) {
            abort(403);
        }

        return $next($request);
    }

    private function allows(string $capability): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        return match ($capability) {
            'manage_setup' => $user->canManageSetup(),
            'manage_billing' => $user->canManageBilling(),
            'manage_api_credentials' => $user->canManageApiCredentials(),
            'operate_warehouse' => $user->canOperateWarehouse(),
            'export_courier_labels' => $user->canExportCourierLabels(),
            'mutate_inventory' => $user->canMutateInventory(),
            default => false,
        };
    }
}
