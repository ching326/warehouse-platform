<?php

namespace App\Livewire\Concerns;

use App\Models\MediaAsset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

trait HandlesPrivateMediaAssets
{
    protected function createPrivateMediaAsset(
        Model $parent,
        TemporaryUploadedFile $file,
        string $modelType,
        string $type,
        string $directory,
        string $activityLogName
    ): MediaAsset {
        $imageCount = $parent->mediaAssets()->count();

        if ($imageCount >= 10) {
            throw ValidationException::withMessages([
                $this->privateMediaFileProperty() => __('media.image_limit_reached'),
            ]);
        }

        $dimensions = $this->privateMediaDimensions($file);
        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg';
        $fileName = Str::uuid()->toString().'.'.strtolower($extension);
        $path = trim($directory, '/').'/'.$fileName;

        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        $asset = DB::transaction(function () use ($parent, $file, $modelType, $type, $path, $dimensions): MediaAsset {
            return MediaAsset::create([
                'tenant_id' => $parent->tenant_id,
                'model_type' => $modelType,
                'model_id' => $parent->id,
                'type' => $type,
                'disk' => 'local',
                'path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
                'sort_order' => $this->nextPrivateMediaSortOrder($modelType, $parent->id),
                'is_primary' => false,
                'uploaded_by_user_id' => Auth::id(),
            ]);
        });

        activity($activityLogName)
            ->performedOn($parent)
            ->causedBy(Auth::user())
            ->withProperties([
                'media_asset_id' => $asset->id,
                'type' => $asset->type,
            ])
            ->log('image uploaded');

        return $asset;
    }

    protected function deletePrivateMediaAsset(MediaAsset $asset, Model $parent, string $activityLogName): void
    {
        $disk = $asset->disk;
        $path = $asset->path;
        $assetId = $asset->id;

        $asset->delete();

        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable $exception) {
            Log::warning('Private media file deletion failed.', [
                'disk' => $disk,
                'path' => $path,
                'media_asset_id' => $assetId,
                'exception' => $exception->getMessage(),
            ]);
        }

        activity($activityLogName)
            ->performedOn($parent)
            ->causedBy(Auth::user())
            ->withProperties(['media_asset_id' => $assetId])
            ->log('image deleted');
    }

    protected function nextPrivateMediaSortOrder(string $modelType, int $modelId): int
    {
        return ((int) MediaAsset::query()
            ->where('model_type', $modelType)
            ->where('model_id', $modelId)
            ->max('sort_order')) + 1;
    }

    protected function privateMediaDimensions(TemporaryUploadedFile $file): array
    {
        $size = @getimagesize($file->getRealPath());

        return [
            'width' => $size[0] ?? null,
            'height' => $size[1] ?? null,
        ];
    }

    protected function privateMediaFileProperty(): string
    {
        return 'photo';
    }
}
