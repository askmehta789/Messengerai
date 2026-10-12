@extends('layouts.app')
@section('title', 'AI settings')

@section('content')
@php
    $icons = ['openai' => '🟢', 'gemini' => '🔷', 'anthropic' => '🟠', 'openai_compatible' => '🔌'];
    $notes = [
        'openai' => 'ChatGPT models',
        'gemini' => 'Google AI Studio key',
        'anthropic' => 'Claude models',
        'openai_compatible' => 'DeepSeek, Groq, OpenRouter…',
    ];
@endphp
<main class="container">
    <div class="page-head">
        <div>
            <h1>AI settings</h1>
            <p class="muted">Use any AI provider with your own API key. Keys are stored encrypted.</p>
        </div>
        <span class="state {{ $keyHint ? 'state-on' : 'state-off' }}">{{ $keyHint ? 'AI is connected' : 'No API key yet' }}</span>
    </div>

    <form method="post" action="{{ route('settings') }}" id="settings-form">
        @csrf
        <div class="card">
            <h2><span class="card-icon">🤖</span> AI provider</h2>
            <div class="provider-grid">
                @foreach ($providers as $value => $label)
                    <label class="provider">
                        <input type="radio" name="ai_provider" value="{{ $value }}" @checked($settings['ai_provider'] === $value)>
                        <span><i>{{ $icons[$value] ?? '🤖' }}</i>{{ explode(' (', $label)[0] }}<small>{{ $notes[$value] ?? '' }}</small></span>
                    </label>
                @endforeach
            </div>
            <div class="grid-2">
                <label>Model
                    <input name="ai_model" id="model" list="model-list" value="{{ $settings['ai_model'] }}">
                    <datalist id="model-list"></datalist>
                    <small class="muted">Pick a suggestion or type any model name your provider offers. Smaller models cost much less.</small>
                </label>
                <label>API key
                    <input type="password" name="ai_api_key" autocomplete="new-password" placeholder="{{ $keyHint ? 'Saved ('.$keyHint.'), leave empty to keep' : 'Paste your API key' }}">
                    @if ($keyHint)
                        <span class="check"><input type="checkbox" name="remove_key" value="1"> Remove saved key</span>
                    @endif
                </label>
                <label id="base-url-field">Base URL <small class="muted">(OpenAI-compatible only)</small>
                    <input name="ai_base_url" value="{{ $settings['ai_base_url'] }}" placeholder="https://api.deepseek.com/v1">
                </label>
            </div>
        </div>

        <div class="card">
            <h2><span class="card-icon">🏪</span> Business information <small class="muted">used for every page</small></h2>
            <textarea name="ai_instructions" rows="9" placeholder="Example:
We are ABC Store, an online shop in Kathmandu.
Phone: 97XXXXXXXX (share only when the customer asks to call)
Opening hours: 10am - 7pm, Sunday to Friday
Always be polite and use 'hajur' when replying in Nepali.
If the customer asks the price, tell the price and ask if they want to order.">{{ $settings['ai_instructions'] }}</textarea>
        </div>

        <div class="card">
            <h2><span class="card-icon">⚡</span> Behaviour</h2>
            <div class="grid-3">
                <label>Wait before replying (seconds)
                    <input type="number" name="ai_reply_delay" min="0" max="15" value="{{ $settings['ai_reply_delay'] }}">
                    <small class="muted">Lets customers finish typing several messages first.</small>
                </label>
                <label>Pause AI after a person replies (hours)
                    <input type="number" name="ai_pause_hours" min="0" max="720" value="{{ $settings['ai_pause_hours'] }}">
                    <small class="muted">0 = keep the AI off in that chat until you turn it back on.</small>
                </label>
                <label>Message when handing over to a person
                    <input name="ai_handoff_message" value="{{ $settings['ai_handoff_message'] }}">
                </label>
            </div>
        </div>

        <div class="card">
            <h2><span class="card-icon">💬</span> Quick replies <small class="muted">one per line, shown above the reply box</small></h2>
            <textarea name="quick_replies" rows="5">{{ $settings['quick_replies'] }}</textarea>
        </div>

        <div class="row" style="margin-bottom:16px">
            <button class="btn btn-primary">Save settings</button>
        </div>
    </form>

    <div class="card">
        <h2><span class="card-icon">🧪</span> Test the AI</h2>
        <p class="muted" style="margin-top:-6px">Uses the saved settings. Save first if you changed something.</p>
        <div class="row">
            <input id="test-question" value="price kati ho?" class="grow">
            <button class="btn btn-soft" id="test-btn">✨ Send test message</button>
        </div>
        <div id="test-result" class="test-result" hidden></div>
    </div>

    <div class="card">
        <h2><span class="card-icon">📊</span> AI usage, last 30 days</h2>
        @if ($usage->isEmpty())
            <p class="muted">No AI replies yet.</p>
        @else
            <table class="table">
                <thead><tr><th>Model</th><th>Replies</th><th>Input tokens</th><th>Output tokens</th></tr></thead>
                <tbody>
                @foreach ($usage as $row)
                    <tr>
                        <td>{{ $row->ai_model }}</td>
                        <td>{{ number_format($row->replies) }}</td>
                        <td>{{ number_format($row->input_tokens) }}</td>
                        <td>{{ number_format($row->output_tokens) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="muted">Multiply by your provider's price per million tokens to get the cost.</p>
        @endif
    </div>
</main>
@endsection

@push('scripts')
<script>
(() => {
    const models = @json($models);
    const radios = [...document.querySelectorAll('input[name="ai_provider"]')];
    const list = document.getElementById('model-list');
    const model = document.getElementById('model');
    const baseUrl = document.getElementById('base-url-field');
    const current = () => radios.find(r => r.checked)?.value;

    function sync() {
        const m = models[current()] || [];
        list.innerHTML = m.map(x => `<option value="${x}">`).join('');
        model.placeholder = m[0] || '';
        baseUrl.hidden = current() !== 'openai_compatible';
    }
    radios.forEach(r => r.addEventListener('change', () => { model.value = ''; sync(); }));
    sync();

    const btn = document.getElementById('test-btn');
    const out = document.getElementById('test-result');
    btn.addEventListener('click', async () => {
        btn.disabled = true;
        btn.textContent = '✨ Waiting for AI…';
        out.hidden = false;
        out.className = 'test-result';
        out.textContent = '';
        try {
            const res = await fetch('{{ route('settings.test') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ question: document.getElementById('test-question').value }),
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Failed');
            out.textContent = `🤖 ${data.text}\n\n(${data.model} · ${data.input_tokens ?? '?'} input / ${data.output_tokens ?? '?'} output tokens)`;
        } catch (e) {
            out.classList.add('error');
            out.textContent = e.message;
        } finally {
            btn.disabled = false;
            btn.textContent = '✨ Send test message';
        }
    });
})();
</script>
@endpush
