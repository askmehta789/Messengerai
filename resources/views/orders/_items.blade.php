{{-- Order item rows; behaviour comes from public/js/order-items.js --}}
<div class="items-editor" data-items>
    <div class="item-head">
        <span>Product</span><span>Price</span><span>Qty</span><span class="num">Amount</span><span></span>
    </div>
    <div data-item-rows>
        @foreach ($items as $i => $item)
            <div class="item-row" data-item-row>
                <div class="item-product">
                    <select data-product aria-label="Product">
                        <option value="">Custom item…</option>
                    </select>
                    <input type="hidden" name="items[{{ $i }}][product_id]" value="{{ $item['product_id'] ?? '' }}" data-product-id>
                    <input name="items[{{ $i }}][name]" value="{{ $item['name'] ?? '' }}" placeholder="Item name" required data-name>
                </div>
                <input type="number" name="items[{{ $i }}][unit_price]" value="{{ $item['unit_price'] ?? '' }}" min="0" step="0.01" required data-price aria-label="Price">
                <input type="number" name="items[{{ $i }}][quantity]" value="{{ $item['quantity'] ?? 1 }}" min="1" step="1" required data-qty aria-label="Quantity">
                <span class="num" data-line>–</span>
                <button type="button" class="icon-btn" data-remove-item aria-label="Remove item">✕</button>
            </div>
        @endforeach
    </div>
    <button type="button" class="btn btn-ghost btn-sm" data-add-item>+ Add item</button>
    <div class="subtotal-row"><span class="muted">Subtotal</span><strong data-subtotal>–</strong></div>
</div>
