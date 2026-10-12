<?php

namespace App\Http\Controllers;

use App\Services\MessengerWebhook;
use App\Services\MetaGraph;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /** Meta calls this once when you save the webhook URL in the app dashboard. */
    public function verify(Request $request)
    {
        $token = (string) config('services.meta.verify_token');

        if ($request->query('hub_mode') === 'subscribe'
            && $token !== ''
            && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request, MessengerWebhook $webhook)
    {
        $raw = $request->getContent();

        if (! MetaGraph::validSignature($raw, $request->header('X-Hub-Signature-256'))) {
            Log::warning('Rejected webhook with invalid signature');

            return response('Invalid signature', 403);
        }

        try {
            $webhook->handle(json_decode($raw, true, 512, JSON_BIGINT_AS_STRING) ?? []);
        } catch (\Throwable $e) {
            // Always acknowledge, otherwise Meta keeps retrying and may disable the webhook.
            Log::error('Webhook processing failed', ['error' => $e->getMessage()]);
        }

        return response('EVENT_RECEIVED', 200);
    }
}
