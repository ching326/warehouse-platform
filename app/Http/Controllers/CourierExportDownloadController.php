<?php

namespace App\Http\Controllers;

use App\Models\CourierExportBatch;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CourierExportDownloadController extends Controller
{
    public function __invoke(CourierExportBatch $batch): StreamedResponse
    {
        if (! $this->isInternalUser()) {
            $allowedTenantIds = $this->allowedTenantIds();

            if ($batch->tenant_id === null || ! in_array($batch->tenant_id, $allowedTenantIds, true)) {
                abort(403);
            }
        }

        abort_unless(Storage::disk($batch->disk)->exists($batch->path), 404);

        return Storage::disk($batch->disk)->download($batch->path, $batch->file_name, [
            'Content-Type' => 'text/csv; charset=Shift_JIS',
        ]);
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return $user?->user_type === 'internal';
    }

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
