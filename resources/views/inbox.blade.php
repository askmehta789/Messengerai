@extends('layouts.app')
@section('title', 'Inbox')
@section('body-class', 'inbox-page')

@section('content')
<header class="inbox-head">
    <nav class="channel-tabs">
        <button class="channel active" data-channel="all">All messages <span class="count" data-stat="unread" hidden></span></button>
        <button class="channel" data-channel="messenger">
            <span class="ch-icon ch-messenger"></span> Messenger
        </button>
        <span class="channel disabled" title="Coming soon"><span class="ch-icon ch-instagram"></span> Instagram <em>Soon</em></span>
        <span class="channel disabled" title="Coming soon"><span class="ch-icon ch-whatsapp"></span> WhatsApp <em>Soon</em></span>
        <span class="channel disabled" title="Coming soon">Comments <em>Soon</em></span>
    </nav>

    <div class="stats">
        <div class="stat"><span class="stat-num" data-stat="today_in">–</span><span class="stat-label">Messages today</span></div>
        <div class="stat stat-ai"><span class="stat-num" data-stat="today_ai">–</span><span class="stat-label">AI replies</span></div>
        <div class="stat"><span class="stat-num" data-stat="today_team">–</span><span class="stat-label">Team replies</span></div>
        <div class="stat stat-warn"><span class="stat-num" data-stat="human">–</span><span class="stat-label">Need a human</span></div>
        @php $isAdmin = auth()->user()->isAdmin(); @endphp
        <{{ $isAdmin ? 'a' : 'span' }} class="ai-pill {{ $aiConfigured ? 'on' : 'off' }}" @if ($isAdmin) href="{{ route('settings') }}" title="Change in AI settings" @endif>
            <span class="pulse"></span>
            @if ($aiConfigured)
                <span><strong>AI on</strong> · {{ $aiModel }}</span>
            @else
                <span><strong>AI off</strong> · {{ $isAdmin ? 'add an API key' : 'ask an admin' }}</span>
            @endif
        </{{ $isAdmin ? 'a' : 'span' }}>
    </div>
</header>

@if ($pages->isEmpty())
    @if ($isAdmin)
        <div class="flash flash-warn">No pages connected yet. <a href="{{ route('pages.index') }}">Connect your Facebook pages →</a></div>
    @else
        <div class="flash flash-warn">You don't have access to any page yet. Ask an admin to give you access in Team.</div>
    @endif
@endif

<div class="inbox" id="inbox"
     data-labels='@json($labels)'
     data-quick-replies='@json($quickReplies)'>
    <aside class="list-pane">
        <div class="list-tools">
            <div class="search">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" id="search" placeholder="Search">
            </div>
            <select id="page-filter" title="Filter by page">
                <option value="">All pages</option>
                @foreach ($pages as $page)
                    <option value="{{ $page->id }}">{{ $page->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="chips" id="filters">
            <button class="chip active" data-filter="">Open</button>
            <button class="chip" data-filter="unread">Unread <span class="badge" data-stat="unread" hidden></span></button>
            <button class="chip" data-filter="human">Needs human <span class="badge" data-stat="human" hidden></span></button>
            <button class="chip" data-filter="ads">Ad replies</button>
            <button class="chip" data-filter="done">Done</button>
            <select id="label-filter" class="chip chip-select" title="Filter by label">
                <option value="">Label</option>
                @foreach ($labels as $label)
                    <option value="{{ $label }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <ul class="conv-list" id="conv-list">
            @for ($i = 0; $i < 6; $i++)
                <li class="conv skeleton"><span class="avatar"></span><div class="conv-body"><span class="sk w60"></span><span class="sk w90"></span></div></li>
            @endfor
        </ul>
    </aside>

    <section class="chat-pane" id="chat">
        <div class="empty-chat">
            <div class="empty-art">💬</div>
            <h3>Your conversations</h3>
            <p class="muted">Pick a conversation on the left. New Messenger messages from all your pages appear here, and the AI answers them automatically.</p>
        </div>
    </section>

    <aside class="details-pane" id="details" hidden></aside>
</div>

<template id="chat-template">
    <header class="chat-head">
        <button class="icon-btn back" data-action="back" aria-label="Back">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </button>
        <span class="avatar-wrap" data-ref="avatar"></span>
        <div class="who">
            <strong data-ref="name"></strong>
            <span class="muted" data-ref="page"></span>
        </div>
        <label class="switch" data-ref="switch">
            <input type="checkbox" data-ref="ai">
            <span class="switch-label">AI auto-reply</span>
        </label>
        <button class="btn btn-soft btn-sm order-btn" data-action="new-order" title="Create an order for this customer">🧾 Create order</button>
        <button class="icon-btn" data-action="flag" data-ref="flag" data-tip="Needs human">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22v-7"/></svg>
        </button>
        <button class="icon-btn" data-action="done" data-ref="done" data-tip="Mark as done">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
        </button>
        <button class="icon-btn" data-action="details" data-tip="Customer details">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><circle cx="12" cy="8" r=".6" fill="currentColor"/></svg>
        </button>
    </header>
    <div class="banner banner-ad" data-ref="ad" hidden></div>
    <div class="banner banner-warn" data-ref="human" hidden>
        <span>🙋 This customer is waiting for a person to reply.</span>
        <button class="btn btn-ghost btn-sm" data-action="resolve">Mark handled</button>
    </div>
    <div class="messages" data-ref="messages"></div>
    <form class="composer" data-ref="composer">
        <div class="window-note" data-ref="window" hidden>⏱ The 24-hour reply window has closed. Messenger will deliver replies again after the customer writes.</div>
        <div class="quick-replies" data-ref="quick"></div>
        <div class="product-picker" data-ref="picker" hidden>
            <div class="picker-head">
                <strong>Send a product</strong>
                <input type="search" data-ref="pickerSearch" placeholder="Search products">
                <a href="{{ route('products.create') }}" target="_blank" class="btn btn-ghost btn-sm">+ New</a>
                <button type="button" class="icon-btn" data-action="close-picker" aria-label="Close">✕</button>
            </div>
            <div class="picker-list" data-ref="pickerList"></div>
        </div>
        <div class="upload-progress" data-ref="uploading" hidden>Uploading…</div>
        <div class="composer-box">
            <button type="button" class="icon-btn" data-action="products" data-tip="Products & photos" aria-label="Products">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
            </button>
            <label class="icon-btn" data-tip="Send photo or video" aria-label="Upload">
                <input type="file" data-ref="file" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime" hidden>
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
            </label>
            <textarea data-ref="text" rows="1" placeholder="Write a reply…"></textarea>
            <button type="button" class="btn btn-soft" data-action="suggest" title="Let the AI write a draft">✨ AI draft</button>
            <button type="submit" class="btn btn-primary send-btn" aria-label="Send">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M3.4 20.4 21 12 3.4 3.6l-.01 6.53L15 12 3.39 13.87z"/></svg>
            </button>
        </div>
        <div class="composer-hint muted">Enter to send · Shift + Enter for a new line · Replying pauses the AI in this chat</div>
    </form>
</template>

<dialog class="dialog" id="order-dialog">
    <form method="dialog" id="order-dialog-form" class="dialog-form">
        <header class="dialog-head">
            <h2>🧾 Create order</h2>
            <button type="button" class="icon-btn" data-close-dialog aria-label="Close">✕</button>
        </header>
        <div class="dialog-body">
            <div class="grid-2">
                <label>Customer name <input name="customer_name" required></label>
                <label>Phone <input name="phone" required inputmode="tel" placeholder="98XXXXXXXX"></label>
                <label>Address <input name="address" placeholder="Tole, ward, landmark"></label>
                <label>City <input name="city" placeholder="Kathmandu"></label>
                <label>Payment
                    <select name="payment_method">
                        @foreach (\App\Models\Order::PAYMENT_METHODS as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Delivery charge <input type="number" name="delivery_charge" min="0" step="0.01" placeholder="0" data-total-input></label>
            </div>
            @include('orders._items', ['items' => [['product_id' => null, 'name' => '', 'unit_price' => '', 'quantity' => 1]], 'pageId' => null])
            <label style="margin-top:12px">Notes <input name="notes" placeholder="Size, colour, delivery time…"></label>
            <label class="check"><input type="checkbox" name="send_confirmation" value="1" checked> Send the order summary to the customer in Messenger</label>
            <p class="error" data-order-error hidden></p>
        </div>
        <footer class="dialog-foot">
            <div class="grand-total"><span class="muted">Total</span><strong data-grand-total>–</strong></div>
            <button type="button" class="btn btn-ghost" data-close-dialog>Cancel</button>
            <button type="submit" class="btn btn-primary" data-order-submit>Create order</button>
        </footer>
    </form>
</dialog>
@endsection

@push('scripts')
<script>window.ORDER_CURRENCY = @json(config('inbox.currency'));</script>
<script src="{{ asset('js/order-items.js') }}?v={{ filemtime(public_path('js/order-items.js')) }}"></script>
<script src="{{ asset('js/inbox.js') }}?v={{ filemtime(public_path('js/inbox.js')) }}"></script>
@endpush
