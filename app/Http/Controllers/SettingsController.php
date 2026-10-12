<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Setting;
use App\Services\Ai\AiResponder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class SettingsController extends Controller
{
    public function edit()
    {
        $key = (string) Setting::get('ai_api_key');

        $usage = Message::where('sender_type', 'ai')
            ->where('status', 'sent')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('ai_model, count(*) as replies, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens')
            ->groupBy('ai_model')
            ->get();

        return view('settings', [
            'settings' => collect(array_keys(Setting::DEFAULTS))
                ->reject(fn ($k) => $k === 'ai_api_key')
                ->mapWithKeys(fn ($k) => [$k => Setting::get($k)]),
            'keyHint' => $key === '' ? null : '••••'.substr($key, -4),
            'providers' => AiResponder::PROVIDERS,
            'models' => AiResponder::MODEL_SUGGESTIONS,
            'usage' => $usage,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'ai_provider' => ['required', Rule::in(array_keys(AiResponder::PROVIDERS))],
            'ai_model' => ['nullable', 'string', 'max:100'],
            'ai_base_url' => ['nullable', 'url', 'max:255'],
            'ai_api_key' => ['nullable', 'string', 'max:500'],
            'ai_instructions' => ['nullable', 'string', 'max:30000'],
            'ai_reply_delay' => ['required', 'integer', 'min:0', 'max:15'],
            'ai_pause_hours' => ['required', 'integer', 'min:0', 'max:720'],
            'ai_handoff_message' => ['required', 'string', 'max:1000'],
            'quick_replies' => ['nullable', 'string', 'max:10000'],
        ]);

        foreach ($data as $key => $value) {
            // Leaving the key field empty keeps the saved key.
            if ($key === 'ai_api_key' && ($value === null || $value === '')) {
                continue;
            }
            Setting::put($key, $value === null ? '' : (string) $value);
        }
        if ($request->boolean('remove_key')) {
            Setting::put('ai_api_key', '');
        }

        return back()->with('status', 'Settings saved.');
    }

    /** Send a sample customer message to the configured AI to check the key and model. */
    public function test(Request $request)
    {
        $question = $request->input('question') ?: 'price kati ho?';

        try {
            $result = AiResponder::provider()->reply(
                'You are a helpful shop assistant replying on Facebook Messenger. Keep replies short. Reply in the customer\'s language.'
                ."\n\nBusiness information:\n".Setting::get('ai_instructions'),
                'Current date: '.now()->toDateString(),
                [['role' => 'user', 'content' => $question]],
            );
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'text' => AiResponder::clean($result->text),
            'model' => $result->model,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
        ]);
    }
}
