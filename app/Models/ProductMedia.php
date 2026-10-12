<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductMedia extends Model
{
    protected $fillable = ['product_id', 'type', 'path', 'mime', 'size', 'sort'];

    protected static function booted(): void
    {
        // Remove the file together with the record.
        static::deleting(fn (ProductMedia $media) => Storage::disk('media')->delete($media->path));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Public URL. Meta downloads attachments from here, so it must be reachable from the internet. */
    public function url(): string
    {
        return Storage::disk('media')->url($this->path);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'url' => $this->url(),
        ];
    }
}
