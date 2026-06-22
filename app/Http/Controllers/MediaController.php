<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;

class MediaController
{
    public function __invoke(MediaAsset $mediaAsset)
    {
        $user = Auth::user();

        if (! $user) {
            abort(403);
        }

        if ($user->user_type !== 'internal' && ! in_array($mediaAsset->tenant_id, $user->activeTenantIds(), true)) {
            abort(403);
        }

        $disk = Storage::disk($mediaAsset->disk);

        if (! $disk->exists($mediaAsset->path)) {
            abort(404);
        }

        return response($disk->get($mediaAsset->path), 200, [
            'Content-Type' => $mediaAsset->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $this->safeDispositionFilename($mediaAsset->file_name),
                $this->fallbackDispositionFilename($mediaAsset->file_name),
            ),
        ]);
    }

    private function safeDispositionFilename(string $filename): string
    {
        $filename = str_replace(['/', '\\'], '-', $filename);
        $filename = preg_replace('/[\x00-\x1F\x7F]+/', '', $filename) ?? '';

        return $filename !== '' ? $filename : 'media';
    }

    private function fallbackDispositionFilename(string $filename): string
    {
        $filename = $this->safeDispositionFilename($filename);
        $filename = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?? '';
        $filename = str_replace('%', '_', $filename);

        return $filename !== '' ? $filename : 'media';
    }
}
