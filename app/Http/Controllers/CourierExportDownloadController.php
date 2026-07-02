<?php

namespace App\Http\Controllers;

use App\Models\CourierExportBatch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CourierExportDownloadController extends Controller
{
    public function __invoke(CourierExportBatch $batch): StreamedResponse
    {
        $user = Auth::user();

        if (! $user?->canExportCourierLabels()) {
            abort(403);
        }

        abort_unless(Storage::disk($batch->disk)->exists($batch->path), 404);

        return Storage::disk($batch->disk)->download($batch->path, $batch->file_name, [
            'Content-Type' => str_ends_with(strtolower($batch->file_name), '.pdf')
                ? 'application/pdf'
                : 'text/csv; charset=Shift_JIS',
        ]);
    }
}
