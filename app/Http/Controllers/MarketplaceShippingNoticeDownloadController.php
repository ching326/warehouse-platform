<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceShippingNoticeBatch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarketplaceShippingNoticeDownloadController extends Controller
{
    public function __invoke(MarketplaceShippingNoticeBatch $batch): StreamedResponse
    {
        $user = Auth::user();

        if (! $user?->canExportCourierLabels()) {
            abort(403);
        }

        abort_unless(Storage::disk($batch->disk)->exists($batch->path), 404);

        $contentType = $batch->platform === 'amazon'
            ? 'text/plain; charset=Shift_JIS'
            : 'text/csv; charset=Shift_JIS';

        return Storage::disk($batch->disk)->download($batch->path, $batch->file_name, [
            'Content-Type' => $contentType,
        ]);
    }
}
