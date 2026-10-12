<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const STATUSES = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'returned' => 'Returned',
    ];

    public const PAYMENT_METHODS = [
        'cod' => 'Cash on delivery',
        'esewa' => 'eSewa',
        'khalti' => 'Khalti',
        'bank' => 'Bank transfer',
        'paid' => 'Already paid',
    ];

    public const SOURCES = ['ai' => 'AI', 'agent' => 'Chat', 'manual' => 'Manual'];

    /** Statuses that count as sales in the dashboard totals. */
    public const COUNTED = ['pending', 'confirmed', 'shipped', 'delivered'];

    protected $fillable = [
        'number', 'page_id', 'conversation_id', 'customer_name', 'phone', 'address', 'city',
        'payment_method', 'status', 'subtotal', 'delivery_charge', 'discount', 'total',
        'notes', 'source', 'user_id', 'status_changed_at',
    ];

    protected $attributes = [
        'status' => 'pending',
        'payment_method' => 'cod',
        'source' => 'agent',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'delivery_charge' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'status_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Readable order number once the id is known, e.g. ORD-00042.
        static::created(function (Order $order) {
            if (! $order->number) {
                $order->number = 'ORD-'.str_pad((string) $order->id, 5, '0', STR_PAD_LEFT);
                $order->saveQuietly();
            }
        });
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $ids = $user->pageIds();

        return $ids === null ? $query : $query->whereIn('page_id', $ids);
    }

    /**
     * Replace the items and recalculate the totals.
     *
     * @param  list<array{product_id?: ?int, name: string, unit_price: float|string, quantity: int}>  $items
     */
    public function syncItems(array $items): void
    {
        $this->items()->delete();
        $subtotal = 0.0;
        foreach ($items as $item) {
            $qty = max(1, (int) $item['quantity']);
            $price = round((float) $item['unit_price'], 2);
            $subtotal += $line = round($price * $qty, 2);
            $this->items()->create([
                'product_id' => $item['product_id'] ?? null,
                'name' => $item['name'],
                'unit_price' => $price,
                'quantity' => $qty,
                'line_total' => $line,
            ]);
        }

        $this->subtotal = $subtotal;
        $this->total = max(0, $subtotal + (float) $this->delivery_charge - (float) $this->discount);
        $this->save();
    }

    public function setStatus(string $status): void
    {
        if ($this->status !== $status) {
            $this->status = $status;
            $this->status_changed_at = now();
            $this->save();
        }
    }

    public static function money(float|string|null $amount): string
    {
        $amount = (float) $amount;
        $decimals = floor($amount) == $amount ? 0 : 2;

        return config('inbox.currency').' '.number_format($amount, $decimals);
    }

    public function itemsSummary(): string
    {
        return $this->items->map(fn (OrderItem $i) => $i->name.' x'.$i->quantity)->implode(', ');
    }

    /** Text sent to the customer to confirm the order in Messenger. */
    public function confirmationText(): string
    {
        $lines = ["✅ Order received! Order no. {$this->number}"];
        foreach ($this->items as $item) {
            $lines[] = "• {$item->name} x{$item->quantity}: ".self::money($item->line_total);
        }
        if ((float) $this->delivery_charge > 0) {
            $lines[] = 'Delivery: '.self::money($this->delivery_charge);
        }
        if ((float) $this->discount > 0) {
            $lines[] = 'Discount: -'.self::money($this->discount);
        }
        $lines[] = 'Total: '.self::money($this->total).' ('.(self::PAYMENT_METHODS[$this->payment_method] ?? $this->payment_method).')';
        if ($this->address) {
            $lines[] = 'Delivery to: '.trim($this->address.($this->city ? ', '.$this->city : ''));
        }
        $lines[] = 'Thank you! 🙏';

        return implode("\n", $lines);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'status_label' => self::STATUSES[$this->status] ?? $this->status,
            'customer_name' => $this->customer_name,
            'phone' => $this->phone,
            'total' => self::money($this->total),
            'items' => $this->itemsSummary(),
            'source' => $this->source,
            'created_at' => $this->created_at?->toIso8601String(),
            'url' => route('orders.edit', $this),
        ];
    }
}
