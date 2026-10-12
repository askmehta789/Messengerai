@extends('layouts.app')
@section('title', 'Team')

@section('content')
<main class="container">
    <div class="page-head">
        <div>
            <h1>Team &amp; access</h1>
            <p class="muted">Give each person a login and choose which pages they can answer.</p>
        </div>
        <a class="btn btn-primary" href="#add-member">+ Add team member</a>
    </div>

    <div class="card" style="padding:0;overflow:hidden">
        <table class="table team-table">
            <thead>
                <tr><th>Member</th><th>Role</th><th>Page access</th><th>Last login</th><th></th></tr>
            </thead>
            <tbody>
            @foreach ($users as $member)
                <tr @class(['row-disabled' => ! $member->active])>
                    <td>
                        <div class="member">
                            <span class="avatar avatar-letter" style="--h:{{ crc32($member->name) % 360 }}">{{ mb_strtoupper(mb_substr($member->name, 0, 1)) }}</span>
                            <div>
                                <strong>{{ $member->name }}</strong>
                                @if ($member->is(auth()->user())) <span class="tag">You</span> @endif
                                @unless ($member->active) <span class="tag tag-human">Disabled</span> @endunless
                                <div class="muted">{{ $member->email }}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        @if ($member->isAdmin())
                            <span class="tag tag-label">👑 Admin</span>
                        @else
                            <span class="tag tag-ad">🎧 Agent</span>
                        @endif
                    </td>
                    <td>
                        @if ($member->isAdmin())
                            <span class="muted">All pages</span>
                        @elseif ($member->pages->isEmpty())
                            <span class="tag tag-human">No pages</span>
                        @else
                            <div class="page-chips">
                                @foreach ($member->pages as $page)
                                    <span class="page-chip">{{ $page->name }}</span>
                                @endforeach
                            </div>
                        @endif
                    </td>
                    <td class="muted">{{ $member->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                    <td class="actions">
                        <a class="btn btn-ghost btn-sm" href="{{ route('team.edit', $member) }}">Edit</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <form method="post" action="{{ route('team.store') }}" class="card" id="add-member">
        @csrf
        <h2><span class="card-icon">➕</span> Add team member</h2>
        @include('team._form', ['member' => null])
        <div class="row" style="margin-top:16px">
            <button class="btn btn-primary">Add member</button>
            <span class="muted">Share the email and password with them; they can log in right away.</span>
        </div>
    </form>
</main>
@endsection
