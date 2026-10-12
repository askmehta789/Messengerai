<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['page_id', 'name', 'price', 'description', 'active'];

    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)->orderBy('sort')->orderBy('id');
    }

    /** Products shown for a page: its own plus the ones shared by all pages. */
    public function scopeForPage(Builder $query, int $pageId): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('page_id')->orWhere('page_id', $pageId));
    }

    /** Products a user may see: shared ones plus those of their pages. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $ids = $user->pageIds();

        return $ids === null ? $query : $query->where(fn ($q) => $q->whereNull('page_id')->orWhereIn('page_id', $ids));
    }

    /** One line per product for the AI prompt. */
    public function catalogLine(): string
    {
        $line = "- [product {$this->id}] {$this->name}";
        if ($this->price) {
            $line .= " | price: {$this->price}";
        }
        if ($this->description) {
            $line .= ' | '.str_replace(["\r", "\n"], ' ', $this->description);
        }
        $count = $this->media->count();
        if ($count) {
            $line .= " | {$count} photo/video(s) available";
        }

        return $line;
    }

    /** Number from the free-text price, e.g. "Rs. 2,499" -> 2499. */
    public function unitPrice(): float
    {
        $clean = str_replace(',', '', (string) $this->price);

        return preg_match('/\d+(?:\.\d+)?/', preg_replace('/^\D*\./', '', $clean) ?? $clean, $m) ? (float) $m[0] : 0.0;
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'page_id' => $this->page_id,
            'name' => $this->name,
            'price' => $this->price,
            'unit_price' => $this->unitPrice(),
            'description' => $this->description,
            'media' => $this->media->map->toApiArray()->all(),
        ];
    }
}
