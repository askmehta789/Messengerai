@extends('layouts.app')
@section('title', $order->exists ? 'Order '.$order->number : 'New order')

@php
    use App\Models\Order;
    $items = old('items', $order->exists
        ? $order->items->map(fn ($i) => ['product_id' => $i->product_id, 'name' => $i->name, 'unit_price' => (float) $i->unit_price, 'quantity' => $i->quantity])->all()
        : [['product_id' => null, 'name' => '', 'unit_price' => '', 'quantity' => 1]]);
@endphp

@section('content')
<main class="container">
    <div class="page-head">
        <div>
            <a href="{{ route('orders.index') }}" class="muted">← Orders</a>
            <h1>
                {{ $order->exists ? $order->number : 'New order' }}
                @if ($order->exists)
                    <span class="status-badge s-{{ $order->status }}"><span class="status-dot s-{{ $order->status }}"></span>{{ Order::STATUSES[$order->status] }}</span>
                @endif
            </h1>
            @if ($order->exists)
                <p class="muted">
                    {{ $order->created_at->format('j M Y, H:i') }} ·
                    {{ $order->source === 'ai' ? '✨ Taken by AI' : ($order->user ? 'Created by '.$order->user->name : Order::SOURCES[$order->source]) }}
                    @if ($order->conversation_id)
                        · <a href="{{ route('inbox') }}#c{{ $order->conversation_id }}">Open chat</a>
                    @endif
                </p>
            @endif
        </div>
    </div>

    <form method="post" action="{{ $order->exists ? route('orders.update', $order) : route('orders.store') }}" id="order-form">
        @csrf
        @if ($order->exists) @method('put') @endif

        @if ($errors->any())
            <div class="flash flash-err" style="border-radius:8px;margin-bottom:16px">{{ $errors->first() }}</div>
        @endif

        <div class="card">
            <h2><span class="card-icon">👤</span> Customer &amp; delivery</h2>
            <div class="grid-2">
                <label>Customer name
                    <input name="customer_name" value="{{ old('customer_name', $order->customer_name) }}" required>
                </label>
                <label>Phone
                    <input name="phone" value="{{ old('phone', $order->phone) }}" required inputmode="tel" placeholder="98XXXXXXXX">
                </label>
                <label>Address
                    <input name="address" value="{{ old('address', $order->address) }}" placeholder="Tole, ward, landmark">
                </label>
                <label>City
                    <input name="city" value="{{ old('city', $order->city) }}" placeholder="Kathmandu">
                </label>
                @unless ($order->exists)
                    <label>Page
                        <select name="page_id" id="page-select" required>
                            @foreach ($pages as $page)
                                <option value="{{ $page->id }}" @selected((string) old('page_id', $order->page_id) === (string) $page->id)>{{ $page->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endunless
                <label>Payment
                    <select name="payment_method">
                        @foreach (Order::PAYMENT_METHODS as $key => $label)
                            <option value="{{ $key }}" @selected(old('payment_method', $order->payment_method) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Status
                    <select name="status">
                        @foreach (Order::STATUSES as $key => $label)
                            <option value="{{ $key }}" @selected(old('status', $order->status) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>

        <div class="card">
            <h2><span class="card-icon">🛍️</span> Items</h2>
            @include('orders._items', ['items' => $items, 'pageId' => $order->page_id])
            <div class="totals">
                <label>Delivery charge
                    <input type="number" name="delivery_charge" min="0" step="0.01" value="{{ old('delivery_charge', (float) $order->delivery_charge ?: '') }}" placeholder="0" data-total-input>
                </label>
                <label>Discount
                    <input type="number" name="discount" min="0" step="0.01" value="{{ old('discount', (float) $order->discount ?: '') }}" placeholder="0" data-total-input>
                </label>
                <div class="grand-total"><span class="muted">Total</span><strong data-grand-total>{{ Order::money($order->total) }}</strong></div>
            </div>
        </div>

        <div class="card">
            <h2><span class="card-icon">📝</span> Notes</h2>
            <textarea name="notes" rows="3" placeholder="Delivery time, size, colour, anything the team should know">{{ old('notes', $order->notes) }}</textarea>
        </div>

        <div class="row">
            <button class="btn btn-primary">{{ $order->exists ? 'Save order' : 'Create order' }}</button>
            <a class="btn btn-ghost" href="{{ route('orders.index') }}">Cancel</a>
            <span class="grow"></span>
            @if ($order->exists && auth()->user()->isAdmin())
                <button class="btn btn-danger" form="delete-order">Delete order</button>
            @endif
        </div>
    </form>

    @if ($order->exists && auth()->user()->isAdmin())
        <form id="delete-order" method="post" action="{{ route('orders.destroy', $order) }}" hidden
              onsubmit="return confirm('Delete order {{ $order->number }}? This cannot be undone.')">
            @csrf @method('delete')
        </form>
    @endif
</main>
@endsection

@push('scripts')
<script>window.ORDER_PRODUCTS = @json($products); window.ORDER_CURRENCY = @json(config('inbox.currency'));</script>
<script src="{{ asset('js/order-items.js') }}?v={{ filemtime(public_path('js/order-items.js')) }}"></script>
<script>
OrderItems.mount(document.getElementById('order-form'), {
    products: window.ORDER_PRODUCTS,
    currency: window.ORDER_CURRENCY,
    pageId: () => Number(document.getElementById('page-select')?.value || {{ (int) $order->page_id }}),
});
</script>
@endpush
