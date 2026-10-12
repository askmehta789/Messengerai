@extends('layouts.app')
@section('title', 'Pages')

@section('content')
<main class="container">
    <div class="page-head">
        <div>
            <h1>Facebook pages</h1>
            <p class="muted">Connect every page you manage. Each page can have its own products and prices for the AI.</p>
        </div>
        @if ($metaReady)
            <a class="btn btn-primary" href="{{ route('pages.connect') }}">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M24 12.07C24 5.41 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.04V9.41c0-3.02 1.8-4.7 4.54-4.7 1.31 0 2.68.24 2.68.24v2.97h-1.5c-1.5 0-1.96.93-1.96 1.89v2.26h3.32l-.53 3.5h-2.8V24C19.62 23.1 24 18.1 24 12.07"/></svg>
                Connect pages
            </a>
        @endif
    </div>

    @php
        $connected = $pages->isNotEmpty();
        $receiving = $pages->where('subscribed', true)->isNotEmpty();
    @endphp
    <div class="steps">
        <div @class(['step', 'done' => $metaReady])><b>{{ $metaReady ? '✓' : '1' }}</b><div><strong>Meta app</strong><p>App ID and secret in .env</p></div></div>
        <div @class(['step', 'done' => $metaReady && $verifyToken])><b>{{ $metaReady && $verifyToken ? '✓' : '2' }}</b><div><strong>Webhook</strong><p>Callback URL in Messenger settings</p></div></div>
        <div @class(['step', 'done' => $connected])><b>{{ $connected ? '✓' : '3' }}</b><div><strong>Connect pages</strong><p>Login with Facebook</p></div></div>
        <div @class(['step', 'done' => $receiving])><b>{{ $receiving ? '✓' : '4' }}</b><div><strong>Receiving</strong><p>Messages arrive in the inbox</p></div></div>
    </div>

    @unless ($metaReady)
        <div class="card setup">
            <h2><span class="card-icon">🔑</span> First, connect your Meta app</h2>
            <ol>
                <li>Create an app at <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">developers.facebook.com/apps</a> (type: Business) and add the <strong>Messenger</strong> and <strong>Facebook Login</strong> products.</li>
                <li>Put the App ID and App Secret in the <code>.env</code> file as <code>META_APP_ID</code> and <code>META_APP_SECRET</code>, and choose any secret text for <code>META_VERIFY_TOKEN</code>.</li>
                <li>Run <code>php artisan config:cache</code> and reload this page.</li>
            </ol>
        </div>
    @endunless

    @if ($pages->isEmpty())
        <div class="card empty">
            <div class="empty-art">📄</div>
            <h3 style="margin-top:14px">No pages connected yet</h3>
            <p class="muted">Click <strong>Connect pages</strong>, log in with Facebook and tick all the pages you want in this inbox.</p>
        </div>
    @else
        <div class="pages-grid">
        @foreach ($pages as $page)
            <div class="card page-card">
                <div class="page-card-head">
                    @if ($page->picture_url)
                        <img class="avatar" src="{{ $page->picture_url }}" alt="" referrerpolicy="no-referrer">
                    @else
                        <span class="avatar avatar-letter" style="--h:{{ crc32($page->name) % 360 }}">{{ mb_strtoupper(mb_substr($page->name, 0, 1)) }}</span>
                    @endif
                    <div class="grow">
                        <h2>{{ $page->name }}</h2>
                        <span class="muted">{{ $page->conversations_count }} conversations</span>
                        @if ($page->subscribed)
                            <span class="tag tag-ok">● Receiving</span>
                        @else
                            <span class="tag tag-human">● Not receiving</span>
                        @endif
                    </div>
                    <form method="post" action="{{ route('pages.update', $page) }}">
                        @csrf @method('patch')
                        <input type="hidden" name="ai_enabled" value="{{ $page->ai_enabled ? 0 : 1 }}">
                        <button class="btn btn-sm {{ $page->ai_enabled ? 'btn-ok' : 'btn-ghost' }}" title="Turn AI auto-reply {{ $page->ai_enabled ? 'off' : 'on' }} for this page">✨ AI {{ $page->ai_enabled ? 'on' : 'off' }}</button>
                    </form>
                </div>

                @if ($page->last_error)
                    <p class="error">{{ $page->last_error }}</p>
                @endif

                <p class="page-team">
                    <span class="muted">👥 Agents:</span>
                    @forelse ($page->users as $member)
                        <span class="page-chip">{{ $member->name }}</span>
                    @empty
                        <span class="muted">admins only</span>
                    @endforelse
                    <a href="{{ route('team.index') }}" class="small">Manage</a>
                </p>

                <form method="post" action="{{ route('pages.update', $page) }}">
                    @csrf @method('patch')
                    <label>What the AI should know about this page
                        <textarea name="ai_instructions" rows="7" placeholder="Example:
Product: Dashain special combo - Rs. 2,499 (was Rs. 3,200)
Delivery: inside Kathmandu valley free, outside Rs. 150, 2-4 days
Payment: cash on delivery, eSewa, Khalti
To order, ask for name, phone number and full address.">{{ $page->ai_instructions }}</textarea>
                    </label>
                    <div class="row">
                        <button class="btn btn-primary btn-sm">Save</button>
                        <span class="grow"></span>
                        <button class="btn btn-ghost btn-sm" form="resub-{{ $page->id }}">↻ Re-subscribe</button>
                        <button class="btn btn-danger btn-sm" form="del-{{ $page->id }}">Disconnect</button>
                    </div>
                </form>
                <form id="resub-{{ $page->id }}" method="post" action="{{ route('pages.resubscribe', $page) }}" hidden>@csrf</form>
                <form id="del-{{ $page->id }}" method="post" action="{{ route('pages.destroy', $page) }}" hidden
                      onsubmit="return confirm('Disconnect {{ addslashes($page->name) }}? Its conversations will be deleted from this inbox.')">@csrf @method('delete')</form>
            </div>
        @endforeach
        </div>
    @endif

    <div class="card setup" style="margin-top:16px">
        <h2><span class="card-icon">⚙️</span> Meta app settings</h2>
        <p class="muted" style="margin-top:-6px">Paste these into your Meta app dashboard.</p>
        <dl class="kv">
            <dt>Webhook callback URL <br><small class="muted">Messenger → Settings → Webhooks</small></dt>
            <dd><code>{{ $webhookUrl }}</code></dd>
            <dd><button type="button" class="btn btn-ghost btn-sm copy" data-copy="{{ $webhookUrl }}">Copy</button></dd>

            <dt>Verify token</dt>
            <dd><code>{{ $verifyToken ?: 'not set: add META_VERIFY_TOKEN to .env' }}</code></dd>
            <dd>@if ($verifyToken)<button type="button" class="btn btn-ghost btn-sm copy" data-copy="{{ $verifyToken }}">Copy</button>@endif</dd>

            <dt>Webhook fields</dt>
            <dd><code>messages, messaging_postbacks, messaging_referrals, message_echoes</code></dd>
            <dd></dd>

            <dt>OAuth redirect URI <br><small class="muted">Facebook Login → Settings</small></dt>
            <dd><code>{{ $callbackUrl }}</code></dd>
            <dd><button type="button" class="btn btn-ghost btn-sm copy" data-copy="{{ $callbackUrl }}">Copy</button></dd>

            <dt>Privacy policy URL <br><small class="muted">App settings → Basic</small></dt>
            <dd><code>{{ route('privacy') }}</code></dd>
            <dd><button type="button" class="btn btn-ghost btn-sm copy" data-copy="{{ route('privacy') }}">Copy</button></dd>
        </dl>
    </div>
</main>
@endsection

@push('scripts')
<script>
document.addEventListener('click', async e => {
    const btn = e.target.closest('[data-copy]');
    if (!btn) return;
    try {
        await navigator.clipboard.writeText(btn.dataset.copy);
        btn.textContent = 'Copied ✓';
        setTimeout(() => { btn.textContent = 'Copy'; }, 1500);
    } catch {
        prompt('Copy this:', btn.dataset.copy);
    }
});
</script>
@endpush
