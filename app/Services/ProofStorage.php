<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** No public URLs. Legacy files are read only after transaction authorization. */
final class ProofStorage
{
    public function store(UploadedFile $image): string
    {
        return $image->store('proofs-private', 'proofs');
    }

    public function deleteNew(?string $path): void
    {
        if ($path && str_starts_with($path, 'proofs-private/')) {
            Storage::disk('proofs')->delete($path);
        }
    }

    public function response(string $path)
    {
        // Stored paths are not a client-controlled filesystem/file selector.
        // Validate even legacy DB paths before accessing either disk.
        if (!preg_match('~^(proofs-private|proofs)/[A-Za-z0-9_-]+\.(jpg|jpeg|png|webp)$~iD', $path, $match)) {
            abort(404, 'Bukti pengambilan tidak tersedia.');
        }
        $disk = Storage::disk(strtolower($match[1]) === 'proofs-private' ? 'proofs' : 'public');
        if (!$disk->exists($path)) abort(404, 'Bukti pengambilan tidak tersedia.');
        $mime = match (strtolower($match[2])) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        };
        return $disk->response($path, 'bukti-pengambilan.' . strtolower($match[2]), [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ], 'inline');
    }
}
