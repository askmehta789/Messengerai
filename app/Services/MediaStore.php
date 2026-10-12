<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Saves uploaded photos/videos to the public "media" disk. */
class MediaStore
{
    /** Types Messenger can show inline. */
    public const MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
    ];

    /** Messenger's attachment limit is 25 MB. In kilobytes for validation. */
    public const MAX_KB = 25 * 1024;

    public static function rules(): array
    {
        return ['file', 'mimetypes:'.implode(',', array_keys(self::MIMES)), 'max:'.self::MAX_KB];
    }

    /** @return array{path: string, type: string, mime: string, size: int} */
    public function store(UploadedFile $file, string $folder): array
    {
        $mime = (string) $file->getMimeType();
        // The extension comes from the detected type, never from the uploaded name.
        $name = now()->format('Y/m/').Str::random(32).'.'.(self::MIMES[$mime] ?? 'bin');
        $path = trim($folder, '/').'/'.$name;

        Storage::disk('media')->putFileAs(dirname($path), $file, basename($path));

        return [
            'path' => $path,
            'type' => str_starts_with($mime, 'video/') ? 'video' : 'image',
            'mime' => $mime,
            'size' => (int) $file->getSize(),
        ];
    }
}
