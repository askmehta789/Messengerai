@extends('layouts.app')
@section('title', 'Orders')

@php
    use App\Models\Order;
    $q = request()->query();
    $range = request('range', '30d');
    $tab = fn (?string $status) => route('orders.index', array_filter(array_merge($q, ['status' => $status, 'pg' => null]), fn ($v) => $v !== null && $v !== ''));
    $maxOrders = max(1, collect($chart)->max('orders'));
    $peak = collect($chart)->sortByDesc('orders')->first();
@endphp

@section('content')
<main class="container container-wide">
    <div class="page-head">
        <div>
            <h1>Orders</h1>
            <p class="muted">Orders taken in chats by your team and the AI. Change the status as you confirm, ship and deliver.</p>
        </div>
        <div class="row">
            <a class="btn btn-ghost" href="{{ route('orders.export', $q) }}" title="Download the orders shown below as an Excel file">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></svg>
                Export to Excel
            </a>
            <a class="btn btn-primary" href="{{ route('orders.create') }}">+ New order</a>
        </div>
    </div>

    {{-- Filters: one row above everything they affect. --}}
    <form method="get" class="toolbar filters" id="filters">
        <div class="search">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Order no., name, phone, address">
        </div>
        <select name="range" data-autosubmit>
            @foreach ($ranges as $key => $label)
                <option value="{{ $key }}" @selected($range === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <span class="custom-range" @if ($range !== 'custom') hidden @endif>
            <input type="date" name="from" value="{{ request('from') }}" aria-label="From">
            <span class="muted">to</span>
            <input type="date" name="to" value="{{ request('to') }}" aria-label="To">
        </span>
        <select name="page" data-autosubmit>
            <option value="">All pages</option>
            @foreach ($pages as $page)
                <option value="{{ $page->id }}" @selected((string) request('page') === (string) $page->id)>{{ $page->name }}</option>
            @endforeach
        </select>
        <select name="source" data-autosubmit>
            <option value="">All sources</option>
            @foreach (Order::SOURCES as $key => $label)
                <option value="{{ $key }}" @selected(request('source') === $key)>{{ $key === 'ai' ? '✨ ' : '' }}{{ $label }}</option>
            @endforeach
        </select>
        @if (request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
        <button class="btn btn-ghost">Apply</button>
    </form>

    {{-- Headline numbers for the selected range. --}}
    <div class="kpis">
        <div class="kpi">
            <span class="kpi-label">Orders</span>
            <span class="kpi-num">{{ number_format($stats['orders']) }}</span>
            <span class="kpi-sub">{{ $ranges[$range] ?? 'Selected range' }}</span>
        </div>
        <div class="kpi">
            <span class="kpi-label">Sales</span>
            <span class="kpi-num">{{ Order::money($stats['sales']) }}</span>
            <span class="kpi-sub">excl. cancelled &amp; returned</span>
        </div>
        <div class="kpi">
            <span class="kpi-label">Average order</span>
            <span class="kpi-num">{{ Order::money(round($stats['average'])) }}</span>
            <span class="kpi-sub">per order</span>
        </div>
        <a class="kpi kpi-link" href="{{ $tab('pending') }}">
            <span class="kpi-label"><span class="status-dot s-pending"></span> To confirm</span>
            <span class="kpi-num">{{ number_format($stats['pending']) }}</span>
            <span class="kpi-sub">pending orders →</span>
        </a>
        <div class="kpi">
            <span class="kpi-label"><span class="status-dot s-delivered"></span> Delivered</span>
            <span class="kpi-num">{{ number_format($stats['delivered']) }}</span>
            <span class="kpi-sub">{{ $stats['ai'] }} orders taken by AI</span>
        </div>
    </div>

    <div class="dash-grid">
        {{-- Orders per day: one series, one colour, hover for details. --}}
        <section class="card chart-card">
            <div class="chart-head">
                <h2>Orders per day <small class="muted">last 14 days{{ request('page') ? ' · filtered page' : '' }}</small></h2>
                @if ($peak && $peak['orders'] > 0)
                    <span class="muted small">Best day: <strong>{{ $peak['label'] }}</strong> with {{ $peak['orders'] }} {{ Str::plural('order', $peak['orders']) }}</span>
                @endif
            </div>
            <div class="bar-chart" role="img" aria-label="Orders per day for the last 14 days">
                <div class="bar-grid">
                    <span>{{ $maxOrders }}</span>
                    <span>{{ intdiv($maxOrders, 2) ?: '' }}</span>
                    <span>0</span>
                </div>
                <div class="bars">
                    @foreach ($chart as $day)
                        <div class="bar-col" tabindex="0">
                            <div class="bar-track">
                                <div class="bar" style="height: {{ $day['orders'] ? max(3, round($day['orders'] / $maxOrders * 100)) : 0 }}%"></div>
                            </div>
                            <span class="bar-x {{ $loop->last ? 'today' : '' }}">{{ $loop->last ? 'Today' : $day['short'] }}</span>
                            <div class="bar-tip">
                                <strong>{{ $day['weekday'] }}, {{ $day['label'] }}</strong>
                                <span>{{ $day['orders'] }} {{ Str::plural('order', $day['orders']) }}</span>
                                <span class="muted">{{ Order::money($day['sales']) }} sales</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <details class="table-view">
                <summary>Show as table</summary>
                <table class="table">
                    <thead><tr><th>Date</th><th>Orders</th><th>Sales</th></tr></thead>
                    <tbody>
                    @foreach (array_reverse($chart) as $day)
                        <tr><td>{{ $day['weekday'] }}, {{ $day['label'] }}</td><td>{{ $day['orders'] }}</td><td>{{ Order::money($day['sales']) }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </details>
        </section>

        <section class="card">
            <h2>Top products <small class="muted">{{ strtolower($ranges[$range] ?? '') }}</small></h2>
            @if ($topProducts->isEmpty())
                <p class="muted">No sales in this range yet.</p>
            @else
                @php $topMax = max(1, (float) $topProducts->max('revenue')); @endphp
                <ul class="top-list">
                    @foreach ($topProducts as $p)
                        <li>
                            <div class="top-row"><span class="top-name">{{ $p->name }}</span><strong>{{ Order::money($p->revenue) }}</strong></div>
                            <div class="top-bar"><span style="width: {{ round($p->revenue / $topMax * 100) }}%"></span></div>
                            <span class="muted small">{{ (int) $p->qty }} sold</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    {{-- Status tabs --}}
    <nav class="status-tabs">
        <a href="{{ $tab(null) }}" @class(['active' => ! request('status')])>All <span>{{ $statusCounts->sum() }}</span></a>
        @foreach (Order::STATUSES as $key => $label)
            <a href="{{ $tab($key) }}" @class(['active' => request('status') === $key])>
                <span class="status-dot s-{{ $key }}"></span> {{ $label }} <span>{{ $statusCounts[$key] ?? 0 }}</span>
            </a>
        @endforeach
    </nav>

    <div class="card" style="padding:0;overflow:hidden">
        @if ($orders->isEmpty())
            <div class="empty" style="padding:50px 20px;text-align:center">
                <div class="empty-art">🧾</div>
                <h3 style="margin-top:14px">No orders here</h3>
                <p class="muted">Create orders from a chat with the <strong>Create order</strong> button, or the AI creates them when a customer confirms.</p>
            </div>
        @else
            <div class="table-scroll">
            <table class="table orders-table">
                <thead>
                    <tr><th>Order</th><th>Customer</th><th>Items</th><th class="num">Total</th><th>Payment</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                @foreach ($orders as $order)
                    <tr>
                        <td>
                            <a href="{{ route('orders.edit', $order) }}" class="order-no">{{ $order->number }}</a>
                            <div class="muted small">{{ $order->created_at->format('j M, H:i') }}</div>
                            <div class="small">
                                @if ($order->source === 'ai')<span class="tag tag-ad">✨ AI</span>@endif
                                <span class="page-chip">{{ $order->page?->name }}</span>
                            </div>
                        </td>
                        <td>
                            <strong>{{ $order->customer_name }}</strong>
                            <div><a href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $order->phone) }}">{{ $order->phone }}</a></div>
                            <div class="muted small clamp">{{ trim($order->address.($order->city ? ', '.$order->city : '')) }}</div>
                        </td>
                        <td class="small">
                            @foreach ($order->items as $item)
                                <div>{{ $item->name }} <span class="muted">×{{ $item->quantity }}</span></div>
                            @endforeach
                        </td>
                        <td class="num"><strong>{{ Order::money($order->total) }}</strong>
                            @if ((float) $order->delivery_charge > 0)<div class="muted small">incl. {{ Order::money($order->delivery_charge) }} delivery</div>@endif
                        </td>
                        <td class="small">{{ Order::PAYMENT_METHODS[$order->payment_method] ?? $order->payment_method }}</td>
                        <td>
                            <select class="status-select s-{{ $order->status }}" data-status-url="{{ route('orders.status', $order) }}" aria-label="Status of {{ $order->number }}">
                                @foreach (Order::STATUSES as $key => $label)
                                    <option value="{{ $key }}" @selected($order->status === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="actions">
                            @if ($order->conversation_id)
                                <a class="icon-btn" href="{{ route('inbox') }}#c{{ $order->conversation_id }}" data-tip="Open chat">💬</a>
                            @endif
                            <a class="btn btn-ghost btn-sm" href="{{ route('orders.edit', $order) }}">Open</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
    <div class="pager">{{ $orders->links('orders.pager') }}</div>
</main>
@endsection

@push('scripts')
<script>
(() => {
    const form = document.getElementById('filters');
    form.querySelectorAll('[data-autosubmit]').forEach(el => el.addEventListener('change', () => {
        if (el.name === 'range' && el.value === 'custom') {
            form.querySelector('.custom-range').hidden = false;
            return;
        }
        form.submit();
    }));

    // Change status straight from the list.
    document.querySelectorAll('[data-status-url]').forEach(sel => {
        let previous = sel.value;
        sel.addEventListener('change', async () => {
            sel.disabled = true;
            try {
                const res = await fetch(sel.dataset.statusUrl, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ status: sel.value }),
                });
                if (!res.ok) throw new Error('Could not change the status');
                sel.className = 'status-select s-' + sel.value;
                previous = sel.value;
            } catch (e) {
                sel.value = previous;
                alert(e.message);
            } finally {
                sel.disabled = false;
            }
        });
    });
})();
</script>
@endpush
