@php
    /** @var \App\Models\User|null $member */
    $member ??= null;
    $selected = collect(old('pages', $member?->pages->pluck('id')->all() ?? []))->map(fn ($id) => (int) $id)->all();
    $role = old('role', $member->role ?? 'agent');
@endphp

<div class="grid-2">
    <label>Name
        <input name="name" value="{{ old('name', $member?->name) }}" required>
    </label>
    <label>Email (used to log in)
        <input type="email" name="email" value="{{ old('email', $member?->email) }}" required autocomplete="off">
    </label>
    <label>{{ $member ? 'New password' : 'Password' }}
        <input type="password" name="password" autocomplete="new-password" {{ $member ? '' : 'required' }} minlength="8"
               placeholder="{{ $member ? 'Leave empty to keep the current password' : 'At least 8 characters' }}">
    </label>
    @if ($member)
        <label>Status
            <span class="check"><input type="checkbox" name="active" value="1" @checked(old('active', $member->active))> Can log in</span>
        </label>
    @endif
</div>

<h3 class="form-sub">Role</h3>
<div class="role-grid" data-role-picker>
    @foreach (\App\Models\User::ROLES as $value => $label)
        @php [$title, $desc] = explode(': ', $label, 2); @endphp
        <label class="provider">
            <input type="radio" name="role" value="{{ $value }}" @checked($role === $value)>
            <span><i>{{ $value === 'admin' ? '👑' : '🎧' }}</i>{{ $title }}<small>{{ ucfirst($desc) }}</small></span>
        </label>
    @endforeach
</div>

<div data-page-access @if ($role === 'admin') hidden @endif>
    <h3 class="form-sub">Page access <small class="muted">this person only sees chats and products of the ticked pages</small></h3>
    @if ($pages->isEmpty())
        <p class="muted">No pages connected yet.</p>
    @else
        <div class="row" style="margin-bottom:8px">
            <button type="button" class="btn btn-ghost btn-sm" data-select-pages="all">Select all</button>
            <button type="button" class="btn btn-ghost btn-sm" data-select-pages="none">Clear</button>
        </div>
        <div class="page-checks">
            @foreach ($pages as $page)
                <label class="page-check">
                    <input type="checkbox" name="pages[]" value="{{ $page->id }}" @checked(in_array($page->id, $selected, true))>
                    <span>
                        @if ($page->picture_url)
                            <img class="avatar" src="{{ $page->picture_url }}" alt="" referrerpolicy="no-referrer">
                        @else
                            <span class="avatar avatar-letter" style="--h:{{ crc32($page->name) % 360 }}">{{ mb_strtoupper(mb_substr($page->name, 0, 1)) }}</span>
                        @endif
                        {{ $page->name }}
                    </span>
                </label>
            @endforeach
        </div>
    @endif
</div>

@if ($errors->any())
    <div class="flash flash-err" style="border-radius:8px;margin-top:12px">{{ $errors->first() }}</div>
@endif

@once
@push('scripts')
<script>
document.querySelectorAll('[data-role-picker]').forEach(picker => {
    const form = picker.closest('form');
    const access = form.querySelector('[data-page-access]');
    picker.addEventListener('change', e => { access.hidden = e.target.value === 'admin'; });
    form.querySelectorAll('[data-select-pages]').forEach(btn => btn.addEventListener('click', () => {
        form.querySelectorAll('input[name="pages[]"]').forEach(cb => { cb.checked = btn.dataset.selectPages === 'all'; });
    }));
});
</script>
@endpush
@endonce
