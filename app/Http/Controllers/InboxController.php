<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiResponder;
use App\Services\MediaStore;
use App\Services\ReplySender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The shared inbox. Every query is limited to the pages the logged-in user may see;
 * routes that take a {conversation} are guarded by the "access-conversation" gate.
 */
class InboxController extends Controller
{
    public function index(Request $request)
    {
        $provider = Setting::get('ai_provider');

        return view('inbox', [
            'pages' => Page::visibleTo($request->user())->orderBy('name')->get(['id', 'name', 'picture_url']),
            'aiConfigured' => AiResponder::configured(),
            'aiLabel' => AiResponder::PROVIDERS[$provider] ?? $provider,
            'aiModel' => Setting::get('ai_model') ?: (AiResponder::MODEL_SUGGESTIONS[$provider][0] ?? ''),
            'labels' => Conversation::LABELS,
            'quickReplies' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) Setting::get('quick_replies'))))),
        ]);
    }

    /** Counters for the stats strip at the top of the inbox. */
    private function stats(User $user): array
    {
        $today = now()->startOfDay();
        $conversations = Conversation::visibleTo($user);
        $messages = Message::whereIn('conversation_id', Conversation::visibleTo($user)->select('id'))
            ->where('created_at', '>=', $today);
        $out = (clone $messages)->where('direction', 'out')->where('status', 'sent');

        return [
            'today_in' => (clone $messages)->where('direction', 'in')->count(),
            'today_ai' => (clone $out)->where('sender_type', 'ai')->count(),
            'today_team' => (clone $out)->whereIn('sender_type', ['agent', 'business_suite'])->count(),
            'unread' => (clone $conversations)->where('unread_count', '>', 0)->where('status', '!=', 'done')->count(),
            'human' => (clone $conversations)->where('needs_human', true)->count(),
        ];
    }

    public function conversations(Request $request)
    {
        $query = Conversation::visibleTo($request->user())
            ->with('page')
            ->whereNotNull('last_message_at')
            ->orderByDesc('last_message_at');

        if ($request->filled('page')) {
            $query->where('page_id', $request->integer('page'));
        }
        match ($request->query('filter')) {
            'unread' => $query->where('unread_count', '>', 0),
            'human' => $query->where('needs_human', true),
            'ads' => $query->whereNotNull('ad_id'),
            'done' => $query->where('status', 'done'),
            default => $query->where('status', '!=', 'done'),
        };
        if (in_array($request->query('label'), Conversation::LABELS, true)) {
            $query->whereJsonContains('labels', $request->query('label'));
        }
        if ($search = trim((string) $request->query('q'))) {
            $query->where(fn ($q) => $q
                ->where('customer_name', 'like', "%{$search}%")
                ->orWhere('last_message_text', 'like', "%{$search}%"));
        }

        $conversations = $query->limit(200)->get();

        return response()->json([
            'conversations' => $conversations->map->toListArray(),
            'stats' => $this->stats($request->user()),
        ]);
    }

    public function messages(Request $request, Conversation $conversation)
    {
        $messages = $conversation->messages()
            ->with('user:id,name')
            ->when($request->filled('after'), fn ($q) => $q->where('id', '>', $request->integer('after')))
            ->orderBy('id')
            ->limit(500)
            ->get();

        return response()->json([
            'conversation' => $conversation->load('page')->toListArray(),
            'messages' => $messages->map->toApiArray(),
            'counts' => [
                'total' => $conversation->messages()->count(),
                'ai' => $conversation->messages()->where('sender_type', 'ai')->where('status', 'sent')->count(),
            ],
        ]);
    }

    public function read(Conversation $conversation)
    {
        $conversation->update(['unread_count' => 0]);

        return response()->json(['ok' => true]);
    }

    public function reply(Request $request, Conversation $conversation, ReplySender $sender)
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:6000']]);

        $message = $sender->send($conversation->load('page'), $data['text'], 'agent', $request->user()->id);
        $this->humanReplied($conversation, $message);

        return response()->json(['message' => $message->load('user:id,name')->toApiArray()]);
    }

    /** Products (with photos) that can be sent in this conversation. */
    public function products(Conversation $conversation)
    {
        $products = Product::forPage($conversation->page_id)
            ->where('active', true)
            ->with('media')
            ->orderBy('name')
            ->get();

        return response()->json(['products' => $products->map->toApiArray()]);
    }

    /** Send one saved product photo/video. */
    public function sendProductMedia(Request $request, Conversation $conversation, ReplySender $sender)
    {
        $data = $request->validate(['media_id' => ['required', 'integer']]);

        $media = ProductMedia::whereKey($data['media_id'])
            ->whereHas('product', fn ($q) => $q->forPage($conversation->page_id))
            ->firstOrFail();

        $message = $sender->sendMedia($conversation->load('page'), $media->type, $media->url(), 'agent', $request->user()->id);
        $this->humanReplied($conversation, $message);

        return response()->json(['message' => $message->load('user:id,name')->toApiArray()]);
    }

    /** Upload a photo/video from the computer and send it. */
    public function upload(Request $request, Conversation $conversation, ReplySender $sender, MediaStore $store)
    {
        $request->validate(['file' => ['required', ...MediaStore::rules()]]);

        $saved = $store->store($request->file('file'), 'chat');
        $url = Storage::disk('media')->url($saved['path']);

        $message = $sender->sendMedia($conversation->load('page'), $saved['type'], $url, 'agent', $request->user()->id);
        $this->humanReplied($conversation, $message);

        return response()->json(['message' => $message->load('user:id,name')->toApiArray()]);
    }

    /** Generate an AI draft without sending it. */
    public function suggest(Conversation $conversation, AiResponder $responder)
    {
        try {
            $result = $responder->reply($conversation->load('page'));
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['text' => AiResponder::clean($result->text)]);
    }

    public function update(Request $request, Conversation $conversation)
    {
        $data = $request->validate([
            'ai_enabled' => ['sometimes', 'boolean'],
            'needs_human' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:open,done'],
            'labels' => ['sometimes', 'array'],
            'labels.*' => ['string', Rule::in(Conversation::LABELS)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        if (isset($data['labels'])) {
            $data['labels'] = array_values(array_unique($data['labels']));
        }

        if (($data['ai_enabled'] ?? false) === true) {
            $data['ai_paused_until'] = null;
        }
        $conversation->update($data);

        return response()->json(['conversation' => $conversation->load('page')->toListArray()]);
    }

    /** A person answered: stop the AI so it doesn't talk over them. */
    private function humanReplied(Conversation $conversation, Message $message): void
    {
        if ($message->status === 'sent') {
            $conversation->update(['needs_human' => false]);
            $conversation->pauseAi((int) Setting::get('ai_pause_hours'));
        }
    }
}
