@extends('layouts.app')
@section('title', 'Edit '.$member->name)

@section('content')
<main class="container">
    <div class="page-head">
        <div>
            <a href="{{ route('team.index') }}" class="muted">← Team</a>
            <h1>{{ $member->name }}</h1>
            <p class="muted">{{ $member->email }} · last login {{ $member->last_login_at?->diffForHumans() ?? 'never' }}</p>
        </div>
    </div>

    <form method="post" action="{{ route('team.update', $member) }}" class="card">
        @csrf @method('put')
        @include('team._form')
        <div class="row" style="margin-top:16px">
            <button class="btn btn-primary">Save</button>
            <a class="btn btn-ghost" href="{{ route('team.index') }}">Cancel</a>
            <span class="grow"></span>
            @unless ($member->is(auth()->user()))
                <button class="btn btn-danger" form="delete-member">Remove member</button>
            @endunless
        </div>
    </form>
    <form id="delete-member" method="post" action="{{ route('team.destroy', $member) }}" hidden
          onsubmit="return confirm('Remove {{ addslashes($member->name) }}? They will no longer be able to log in. Messages they sent stay in the inbox.')">
        @csrf @method('delete')
    </form>
</main>
@endsection
