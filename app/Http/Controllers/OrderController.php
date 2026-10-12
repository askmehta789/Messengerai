<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Page;
use App\Models\Product;
use App\Services\OrderService;
use App\Services\ReplySender;
use App\Services\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public const RANGES = [
        'today' => 'Today',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        'month' => 'This month',
        'all' => 'All time',
        'custom' => 'Custom',
    ];

    // ---------------------------------------------------------------- dashboard

    public function index(Request $request)
    {
        $user = $request->user();
        [$from, $to] = $this->range($request);

        // Everything in the date range and page filter, before the status/search filter.
        $base = $this->scoped($request, $from, $to);
        $counted = (clone $base)->whereIn('status', Order::COUNTED);

        $statusCounts = (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $sales = (float) (clone $counted)->sum('total');
        $countedN = (clone $counted)->count();

        $stats = [
            'orders' => (int) $statusCounts->sum(),
            'sales' => $sales,
            'average' => $countedN ? $sales / $countedN : 0,
            'pending' => (int) ($statusCounts['pending'] ?? 0),
            'delivered' => (int) ($statusCounts['delivered'] ?? 0),
            'ai' => (clone $base)->where('source', 'ai')->count(),
        ];

        $orders = $this->filtered($request, $from, $to)
            ->with(['items', 'page:id,name', 'user:id,name'])
            ->latest('id')
            ->paginate(50, ['*'], 'pg') // "page" is the Facebook page filter
            ->withQueryString();

        $topProducts = OrderItem::query()
            ->whereIn('order_id', (clone $counted)->select('id'))
            ->selectRaw('name, sum(quantity) as qty, sum(line_total) as revenue')
            ->groupBy('name')
            ->orderByDesc('revenue')
            ->limit(6)
            ->get();

        return view('orders.index', [
            'orders' => $orders,
            'stats' => $stats,
            'statusCounts' => $statusCounts,
            'chart' => $this->chart($request),
            'topProducts' => $topProducts,
            'pages' => Page::visibleTo($user)->orderBy('name')->get(['id', 'name']),
            'ranges' => self::RANGES,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /** Orders and sales per day for the last 14 days (page filter only). */
    private function chart(Request $request): array
    {
        $start = now()->startOfDay()->subDays(13);
        $rows = Order::visibleTo($request->user())
            ->when($request->filled('page'), fn ($q) => $q->where('page_id', $request->integer('page')))
            ->where('created_at', '>=', $start)
            ->whereIn('status', Order::COUNTED)
            ->get(['created_at', 'total'])
            ->groupBy(fn (Order $o) => $o->created_at->toDateString());

        $days = [];
        for ($d = $start->copy(); $d->lte(now()); $d->addDay()) {
            $day = $rows->get($d->toDateString(), collect());
            $days[] = [
                'date' => $d->toDateString(),
                'label' => $d->format('j M'),
                'short' => $d->format('j'),
                'weekday' => $d->format('D'),
                'orders' => $day->count(),
                'sales' => (float) $day->sum('total'),
            ];
        }

        return $days;
    }

    // ---------------------------------------------------------------- export

    public function export(Request $request, XlsxWriter $xlsx)
    {
        [$from, $to] = $this->range($request);
        $orders = $this->filtered($request, $from, $to)
            ->with(['items', 'page:id,name', 'user:id,name'])
            ->oldest('id')
            ->lazy(200);

        $headers = ['Order no.', 'Date', 'Status', 'Page', 'Customer', 'Phone', 'Address', 'City',
            'Items', 'Quantity', 'Subtotal', 'Delivery', 'Discount', 'Total', 'Payment', 'Source', 'Created by', 'Notes'];

        $rows = (function () use ($orders) {
            foreach ($orders as $o) {
                yield [
                    $o->number,
                    $o->created_at->format('Y-m-d H:i'),
                    Order::STATUSES[$o->status] ?? $o->status,
                    $o->page?->name,
                    $o->customer_name,
                    $o->phone,
                    $o->address,
                    $o->city,
                    $o->items->map(fn ($i) => "{$i->name} x{$i->quantity}")->implode("\n"),
                    (int) $o->items->sum('quantity'),
                    (float) $o->subtotal,
                    (float) $o->delivery_charge,
                    (float) $o->discount,
                    (float) $o->total,
                    Order::PAYMENT_METHODS[$o->payment_method] ?? $o->payment_method,
                    Order::SOURCES[$o->source] ?? $o->source,
                    $o->user?->name ?? ($o->source === 'ai' ? 'AI' : ''),
                    $o->notes,
                ];
            }
        })();

        $name = 'orders-'.($from ? $from->format('Ymd').'-' : '').now()->format('Ymd-His');

        // Without PHP's zip extension we can't build .xlsx; CSV opens in Excel too.
        if (! class_exists(\ZipArchive::class)) {
            return response()->streamDownload(function () use ($headers, $rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Nepali text correctly
                fputcsv($out, $headers);
                foreach ($rows as $row) {
                    // Stop spreadsheet apps from treating customer text as a formula.
                    fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, $row));
                }
                fclose($out);
            }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $file = tempnam(sys_get_temp_dir(), 'orders');
        $xlsx->write($file, 'Orders', $headers, $rows,
            [0 => 12, 1 => 16, 2 => 11, 3 => 18, 4 => 22, 5 => 15, 6 => 34, 7 => 14, 8 => 34, 9 => 9, 10 => 12, 11 => 11, 12 => 11, 13 => 12, 14 => 16, 15 => 9, 16 => 14, 17 => 30],
            [10, 11, 12, 13]);

        return response()->download($file, $name.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    // ---------------------------------------------------------------- create / edit

    public function create(Request $request)
    {
        $pages = Page::visibleTo($request->user())->orderBy('name')->get(['id', 'name']);

        return view('orders.form', [
            'order' => new Order(['page_id' => $pages->first()?->id, 'source' => 'manual']),
            'pages' => $pages,
            'products' => $this->productsFor($request),
        ]);
    }

    public function store(Request $request, OrderService $service)
    {
        $data = $request->validate(OrderService::rules() + [
            'page_id' => ['required', 'integer', Rule::in(Page::visibleTo($request->user())->pluck('id'))],
        ], OrderService::messages());

        $order = $service->save(new Order(['source' => 'manual', 'user_id' => $request->user()->id]), $data, (int) $data['page_id']);

        return redirect()->route('orders.edit', $order)->with('status', "Order {$order->number} saved.");
    }

    public function edit(Request $request, Order $order)
    {
        Gate::authorize('access-order', $order);

        return view('orders.form', [
            'order' => $order->load(['items', 'conversation', 'user']),
            'pages' => Page::visibleTo($request->user())->orderBy('name')->get(['id', 'name']),
            'products' => $this->productsFor($request),
        ]);
    }

    public function update(Request $request, Order $order, OrderService $service)
    {
        Gate::authorize('access-order', $order);
        $data = $request->validate(OrderService::rules(), OrderService::messages());
        $service->save($order, $data, $order->page_id);

        return redirect()->route('orders.edit', $order)->with('status', 'Order saved.');
    }

    public function updateStatus(Request $request, Order $order)
    {
        Gate::authorize('access-order', $order);
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Order::STATUSES))]]);
        $order->setStatus($data['status']);

        return $request->expectsJson()
            ? response()->json(['order' => $order->load('items')->toApiArray()])
            : back()->with('status', "{$order->number} is now ".Order::STATUSES[$order->status].'.');
    }

    public function destroy(Request $request, Order $order)
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only admins can delete orders.');
        $order->delete();

        return redirect()->route('orders.index')->with('status', "Deleted {$order->number}.");
    }

    // ---------------------------------------------------------------- from a chat

    public function forConversation(Conversation $conversation)
    {
        return response()->json([
            'orders' => $conversation->orders()->with('items')->get()->map->toApiArray(),
        ]);
    }

    public function storeFromConversation(Request $request, Conversation $conversation, OrderService $service, ReplySender $sender)
    {
        $data = $request->validate(OrderService::rules() + ['send_confirmation' => ['boolean']], OrderService::messages());

        $order = $service->save(new Order([
            'conversation_id' => $conversation->id,
            'source' => 'agent',
            'user_id' => $request->user()->id,
        ]), $data, $conversation->page_id);
        $service->markConversation($conversation);

        $sent = null;
        if ($request->boolean('send_confirmation')) {
            $sent = $sender->send($conversation->load('page'), $order->confirmationText(), 'agent', $request->user()->id);
        }

        return response()->json([
            'order' => $order->toApiArray(),
            'confirmation' => $sent ? ['status' => $sent->status, 'error' => $sent->error] : null,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function range(Request $request): array
    {
        $range = $request->query('range', '30d');

        return match ($range) {
            'today' => [now()->startOfDay(), null],
            '7d' => [now()->startOfDay()->subDays(6), null],
            'month' => [now()->startOfMonth(), null],
            'all' => [null, null],
            'custom' => [
                $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : null,
                $request->filled('to') ? Carbon::parse($request->query('to'))->endOfDay() : null,
            ],
            default => [now()->startOfDay()->subDays(29), null],
        };
    }

    /** Orders the user may see, limited by page and date range. */
    private function scoped(Request $request, ?Carbon $from, ?Carbon $to): Builder
    {
        return Order::visibleTo($request->user())
            ->when($request->filled('page'), fn ($q) => $q->where('page_id', $request->integer('page')))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));
    }

    /** scoped() plus the status, source and search filters. */
    private function filtered(Request $request, ?Carbon $from, ?Carbon $to): Builder
    {
        return $this->scoped($request, $from, $to)
            ->when(array_key_exists((string) $request->query('status'), Order::STATUSES), fn ($q) => $q->where('status', $request->query('status')))
            ->when(array_key_exists((string) $request->query('source'), Order::SOURCES), fn ($q) => $q->where('source', $request->query('source')))
            ->when(trim((string) $request->query('q')) !== '', function ($q) use ($request) {
                $s = '%'.trim((string) $request->query('q')).'%';
                $q->where(fn ($w) => $w->where('number', 'like', $s)
                    ->orWhere('customer_name', 'like', $s)
                    ->orWhere('phone', 'like', $s)
                    ->orWhere('address', 'like', $s));
            });
    }

    private function productsFor(Request $request)
    {
        return Product::visibleTo($request->user())
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Product $p) => ['id' => $p->id, 'page_id' => $p->page_id, 'name' => $p->name, 'unit_price' => $p->unitPrice()]);
    }
}
