<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

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
            'Content-Disposition' => 'inline; filename="'.$mediaAsset->file_name.'"',
        ]);
    }
}
