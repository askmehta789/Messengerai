<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** Admins add team members, choose their role and which pages they can see. */
class TeamController extends Controller
{
    public function index()
    {
        return view('team.index', [
            'users' => User::with('pages:id,name,picture_url')->orderByDesc('role')->orderBy('name')->get(),
            'pages' => Page::orderBy('name')->get(['id', 'name', 'picture_url']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'],
            'active' => true,
        ]);
        $user->pages()->sync($data['role'] === 'admin' ? [] : ($data['pages'] ?? []));

        return redirect()->route('team.index')->with('status', "{$user->name} can now log in with {$user->email}.");
    }

    public function edit(User $user)
    {
        return view('team.edit', [
            'member' => $user->load('pages:id'),
            'pages' => Page::orderBy('name')->get(['id', 'name', 'picture_url']),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);
        $isSelf = $user->is($request->user());

        if ($isSelf && ($data['role'] !== 'admin' || ! $request->boolean('active'))) {
            throw ValidationException::withMessages(['role' => 'You cannot remove your own admin access or disable yourself.']);
        }
        if ($user->isAdmin() && ($data['role'] !== 'admin' || ! $request->boolean('active'))) {
            $this->ensureAnotherAdmin($user);
        }

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'active' => $request->boolean('active'),
        ]);
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();
        $user->pages()->sync($data['role'] === 'admin' ? [] : ($data['pages'] ?? []));

        return redirect()->route('team.index')->with('status', "Saved {$user->name}.");
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot delete your own account.');
        }
        if ($user->isAdmin()) {
            $this->ensureAnotherAdmin($user);
        }
        $user->delete();

        return redirect()->route('team.index')->with('status', "Removed {$user->name}.");
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', Password::min(8)],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'pages' => ['array'],
            'pages.*' => ['integer', 'exists:pages,id'],
        ]);
    }

    private function ensureAnotherAdmin(User $user): void
    {
        $others = User::where('role', 'admin')->where('active', true)->whereKeyNot($user->id)->exists();
        if (! $others) {
            throw ValidationException::withMessages(['role' => 'There must always be at least one active admin.']);
        }
    }
}
