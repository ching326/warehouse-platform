<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceShippingNoticeBatch;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarketplaceShippingNoticeDownloadController extends Controller
{
    public function __invoke(MarketplaceShippingNoticeBatch $batch): StreamedResponse
    {
        if (! $this->isInternalUser()) {
            $allowedTenantIds = $this->allowedTenantIds();

            if ($batch->tenant_id === null || ! in_array($batch->tenant_id, $allowedTenantIds, true)) {
                abort(403);
            }
        }

        abort_unless(Storage::disk($batch->disk)->exists($batch->path), 404);

        $contentType = $batch->platform === 'amazon'
            ? 'text/plain; charset=Shift_JIS'
            : 'text/csv; charset=Shift_JIS';

        return Storage::disk($batch->disk)->download($batch->path, $batch->file_name, [
            'Content-Type' => $contentType,
        ]);
    }

    private function isInternalUser(): bool
    {
        $user = Auth::user();

        return ! $user || $user->user_type === 'internal';
    }

    private function allowedTenantIds(): array
    {
        if ($this->isInternalUser()) {
            return Tenant::query()->pluck('id')->all();
        }

        return Auth::user()
            ->tenantUsers()
            ->where('status', 'active')
            ->pluck('tenant_id')
            ->all();
    }
}
