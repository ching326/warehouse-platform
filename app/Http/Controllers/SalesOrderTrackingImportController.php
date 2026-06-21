<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Courier\TrackingImport\TrackingImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SalesOrderTrackingImportController extends Controller
{
    public function __invoke(Request $request, TrackingImportService $service): RedirectResponse
    {
        $allowedTenantIds = $this->allowedTenantIds();

        if (! $this->isInternalUser() && $allowedTenantIds === []) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'tracking_file' => ['required', 'file', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return redirect()
                ->route('sales.orders.index')
                ->with('error', $validator->errors()->first('tracking_file'));
        }

        $file = $request->file('tracking_file');
        $contents = file_get_contents($file->getRealPath());
        $service->import(
            contents: $contents === false ? '' : $contents,
            sourceFileName: $file->getClientOriginalName(),
            user: Auth::user(),
            allowedTenantIds: $allowedTenantIds,
        );

        return redirect()
            ->route('sales.orders.index')
            ->with('status', __('sales_orders.tracking_import_succeeded'));
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

    /**
     * @return list<int>
     */
    private function allowedTenantIds(): array
    {
        if ($this->isInternalUser()) {
            return Tenant::query()->pluck('id')->all();
        }

        $user = Auth::user();

        if (! $user) {
            return [];
        }

        return $user->activeTenantIds();
    }
}
