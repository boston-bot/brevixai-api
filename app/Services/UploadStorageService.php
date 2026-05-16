<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class UploadStorageService
{
    private string $disk;

    public function __construct()
    {
        // Default to s3 (which can be configured to point to MinIO in .env)
        $this->disk = config('filesystems.default', 'local');
    }

    public function createPresignedUploadUrl(string $path, int $expirationMinutes = 60): string
    {
        if ($this->disk === 'local') {
            // Local disk doesn't natively support presigned PUT URLs out of the box,
            // but we can return a local temporary URL if configured, or just a dummy URL for local testing.
            return url("/api/uploads/local-presigned?path=" . urlencode($path));
        }

        return Storage::disk($this->disk)->temporaryUploadUrl(
            $path,
            now()->addMinutes($expirationMinutes)
        );
    }

    public function statStoredObject(string $path): ?array
    {
        if (!Storage::disk($this->disk)->exists($path)) {
            return null;
        }

        return [
            'size' => Storage::disk($this->disk)->size($path),
            'lastModified' => Storage::disk($this->disk)->lastModified($path),
        ];
    }

    public function removeStoredObject(string $path): bool
    {
        if (Storage::disk($this->disk)->exists($path)) {
            return Storage::disk($this->disk)->delete($path);
        }
        return false;
    }
}
