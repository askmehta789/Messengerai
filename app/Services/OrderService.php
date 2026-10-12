<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Creates and updates orders from the chat, the orders screen and the AI. */
class OrderService
{
    public static function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['required', Rule::in(array_keys(Order::PAYMENT_METHODS))],
            'status' => ['sometimes', Rule::in(array_keys(Order::STATUSES))],
            'delivery_charge' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.name' => ['required', 'string', 'max:200'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public static function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number.',
            'items.required' => 'Add at least one item.',
            'items.*.name.required' => 'Each item needs a name.',
        ];
    }

    /** @param array<string, mixed> $data validated with rules() */
    public function save(Order $order, array $data, int $pageId): Order
    {
        return DB::transaction(function () use ($order, $data, $pageId) {
            // Only keep product links that belong to this page (or shared products).
            $allowed = Product::forPage($pageId)->pluck('id')->all();
            $items = array_map(function (array $item) use ($allowed) {
                if (! in_array((int) ($item['product_id'] ?? 0), $allowed, true)) {
                    $item['product_id'] = null;
                }

                return $item;
            }, array_values($data['items']));

            $status = $data['status'] ?? $order->status ?? 'pending';
            $order->fill([
                'page_id' => $pageId,
                'customer_name' => $data['customer_name'],
                'phone' => $data['phone'],
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'payment_method' => $data['payment_method'],
                'delivery_charge' => (float) ($data['delivery_charge'] ?? 0),
                'discount' => (float) ($data['discount'] ?? 0),
                'notes' => $data['notes'] ?? null,
            ]);
            if (! $order->exists || $order->status !== $status) {
                $order->status = $status;
                $order->status_changed_at = now();
            }
            $order->save();
            $order->syncItems($items);

            return $order->load('items');
        });
    }

    /** After an order is created from a chat: label the chat "Ordered". */
    public function markConversation(Conversation $conversation): void
    {
        $labels = $conversation->labels ?? [];
        if (! in_array('Ordered', $labels, true)) {
            $labels[] = 'Ordered';
            $conversation->update(['labels' => $labels]);
        }
    }

    /**
     * Create an order from the AI's [ORDER: {...}] marker. Returns null when the
     * data is incomplete or the same order was already created recently.
     */
    public function fromAi(Conversation $conversation, array $payload): ?Order
    {
        $phone = trim((string) ($payload['phone'] ?? ''));
        $name = trim((string) ($payload['name'] ?? $conversation->customer_name ?? ''));
        if ($name === '' || ! preg_match('/^[0-9+\-\s()]{7,30}$/', $phone)) {
            return null;
        }

        $products = Product::forPage($conversation->page_id)->get()->keyBy('id');
        $items = [];
        foreach ((array) ($payload['items'] ?? []) as $row) {
            $product = $products->get((int) ($row['product'] ?? 0));
            if ($product) {
                $items[] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'unit_price' => $product->unitPrice(),
                    'quantity' => max(1, min(100, (int) ($row['qty'] ?? 1))),
                ];
            }
        }
        if ($items === []) {
            return null;
        }

        // The AI may repeat the marker in a later reply; don't create the order twice.
        $duplicate = $conversation->orders()
            ->where('source', 'ai')
            ->where('phone', $phone)
            ->where('created_at', '>=', now()->subHours(2))
            ->exists();
        if ($duplicate) {
            return null;
        }

        $payment = strtolower((string) ($payload['payment'] ?? 'cod'));
        $order = new Order(['conversation_id' => $conversation->id, 'source' => 'ai']);
        $order = $this->save($order, [
            'customer_name' => mb_substr($name, 0, 150),
            'phone' => $phone,
            'address' => isset($payload['address']) ? mb_substr((string) $payload['address'], 0, 1000) : null,
            'city' => isset($payload['city']) ? mb_substr((string) $payload['city'], 0, 100) : null,
            'payment_method' => array_key_exists($payment, Order::PAYMENT_METHODS) ? $payment : 'cod',
            'notes' => 'Created by AI from the chat. Please check the details and delivery charge.',
            'items' => $items,
        ], $conversation->page_id);

        $this->markConversation($conversation);

        return $order;
    }
}
