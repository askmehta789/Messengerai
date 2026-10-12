<?php

namespace App\Services;

use App\Models\Page;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Thin wrapper around the Facebook Graph API / Messenger Platform. */
class MetaGraph
{
    /** Permissions requested when connecting pages. */
    public const SCOPES = [
        'pages_show_list',
        'pages_messaging',
        'pages_manage_metadata',
        'pages_read_engagement',
        'business_management',
    ];

    /** Webhook fields each connected page is subscribed to. */
    public const WEBHOOK_FIELDS = [
        'messages',
        'messaging_postbacks',
        'messaging_referrals',
        'message_echoes',
    ];

    /** Messenger rejects text messages longer than this. */
    public const MAX_TEXT = 2000;

    private function http(): PendingRequest
    {
        $version = config('services.meta.graph_version');

        return Http::baseUrl("https://graph.facebook.com/{$version}/")
            ->acceptJson()
            ->timeout(20);
    }

    public function loginUrl(string $redirectUri, string $state): string
    {
        $version = config('services.meta.graph_version');

        return "https://www.facebook.com/{$version}/dialog/oauth?".http_build_query([
            'client_id' => config('services.meta.app_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(',', self::SCOPES),
            'response_type' => 'code',
        ]);
    }

    /** Exchange the OAuth code for a long-lived user token. */
    public function userTokenFromCode(string $code, string $redirectUri): string
    {
        $short = $this->call('get', 'oauth/access_token', [
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ])['access_token'];

        // Page tokens fetched with a long-lived user token never expire.
        return $this->call('get', 'oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'fb_exchange_token' => $short,
        ])['access_token'];
    }

    /** @return list<array{id: string, name: string, access_token: string, picture: ?string}> */
    public function managedPages(string $userToken): array
    {
        $pages = [];
        $params = [
            'fields' => 'id,name,access_token,picture{url}',
            'limit' => 100,
            'access_token' => $userToken,
        ];
        $url = 'me/accounts';

        do {
            $res = $this->call('get', $url, $params);
            foreach ($res['data'] ?? [] as $p) {
                if (empty($p['access_token'])) {
                    continue;
                }
                $pages[] = [
                    'id' => (string) $p['id'],
                    'name' => $p['name'],
                    'access_token' => $p['access_token'],
                    'picture' => $p['picture']['data']['url'] ?? null,
                ];
            }
            $after = $res['paging']['cursors']['after'] ?? null;
            $hasNext = isset($res['paging']['next']);
            $params['after'] = $after;
        } while ($hasNext && $after);

        return $pages;
    }

    /** Subscribe our app to the page's messaging webhooks. */
    public function subscribePage(Page $page): void
    {
        $this->call('post', "{$page->page_id}/subscribed_apps", [
            'subscribed_fields' => implode(',', self::WEBHOOK_FIELDS),
            'access_token' => $page->access_token,
        ]);
    }

    public function unsubscribePage(Page $page): void
    {
        $this->call('delete', "{$page->page_id}/subscribed_apps", [
            'access_token' => $page->access_token,
        ]);
    }

    /** @return array{name: ?string, pic: ?string} */
    public function customerProfile(Page $page, string $psid): array
    {
        try {
            $res = $this->call('get', $psid, [
                'fields' => 'first_name,last_name,name,profile_pic',
                'access_token' => $page->access_token,
            ]);
        } catch (RuntimeException) {
            return ['name' => null, 'pic' => null];
        }

        $name = $res['name'] ?? trim(($res['first_name'] ?? '').' '.($res['last_name'] ?? ''));

        return ['name' => $name ?: null, 'pic' => $res['profile_pic'] ?? null];
    }

    /**
     * Send a text reply. Long texts are split into several messages.
     *
     * @return list<string> message ids
     */
    public function sendText(Page $page, string $psid, string $text): array
    {
        $ids = [];
        foreach (self::chunk($text) as $part) {
            $res = $this->call('post', 'me/messages', [
                'recipient' => ['id' => $psid],
                'messaging_type' => 'RESPONSE',
                'message' => ['text' => $part],
            ], $page->access_token);
            $ids[] = $res['message_id'] ?? null;
        }

        return array_values(array_filter($ids));
    }

    /**
     * Send an image/video/file. Meta downloads it from the URL, so the URL must be public (https).
     *
     * @return string|null message id
     */
    public function sendAttachment(Page $page, string $psid, string $type, string $url): ?string
    {
        $res = $this->call('post', 'me/messages', [
            'recipient' => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message' => ['attachment' => [
                'type' => in_array($type, ['image', 'video', 'audio'], true) ? $type : 'file',
                'payload' => ['url' => $url, 'is_reusable' => true],
            ]],
        ], $page->access_token);

        return $res['message_id'] ?? null;
    }

    /** Show "typing..." to the customer. Failures are ignored. */
    public function typing(Page $page, string $psid, bool $on = true): void
    {
        try {
            $this->call('post', 'me/messages', [
                'recipient' => ['id' => $psid],
                'sender_action' => $on ? 'typing_on' : 'typing_off',
            ], $page->access_token);
        } catch (RuntimeException) {
            // cosmetic only
        }
    }

    /** @return list<string> */
    public static function chunk(string $text): array
    {
        $text = trim($text);
        if (mb_strlen($text) <= self::MAX_TEXT) {
            return [$text];
        }

        $parts = [];
        while (mb_strlen($text) > self::MAX_TEXT) {
            $slice = mb_substr($text, 0, self::MAX_TEXT);
            $cut = max(mb_strrpos($slice, "\n") ?: 0, mb_strrpos($slice, ' ') ?: 0);
            if ($cut < self::MAX_TEXT / 2) {
                $cut = self::MAX_TEXT;
            }
            $parts[] = trim(mb_substr($text, 0, $cut));
            $text = trim(mb_substr($text, $cut));
        }
        if ($text !== '') {
            $parts[] = $text;
        }

        return $parts;
    }

    /** Verify the X-Hub-Signature-256 header Meta sends with every webhook. */
    public static function validSignature(string $payload, ?string $header): bool
    {
        $secret = (string) config('services.meta.app_secret');
        if ($secret === '' || ! $header || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $payload, $secret), $header);
    }

    private function call(string $method, string $path, array $data = [], ?string $token = null): array
    {
        $req = $this->http();
        if ($token) {
            $req = $req->withQueryParameters(['access_token' => $token]);
        }

        $res = match ($method) {
            'get' => $req->get($path, $data),
            'delete' => $req->delete($path.'?'.http_build_query($data)),
            default => $token ? $req->asJson()->post($path, $data) : $req->asForm()->post($path, $data),
        };

        if ($res->failed()) {
            $message = $res->json('error.message') ?? $res->body();
            throw new RuntimeException("Meta API error: {$message}");
        }

        return $res->json() ?? [];
    }
}
