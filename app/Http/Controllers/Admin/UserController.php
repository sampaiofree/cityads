<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        $this->authorizeAdmin();

        return view('admin.users.index', [
            'users' => User::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'is_admin' => ['nullable', 'boolean'],
        ]);

        $data['is_admin'] = $request->boolean('is_admin');

        User::create($data);

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'Usuário criado com sucesso.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'password' => ['nullable', 'string', 'min:6'],
            'is_admin' => ['nullable', 'boolean'],
        ]);

        $data['is_admin'] = $request->boolean('is_admin');

        if (! $request->filled('password')) {
            unset($data['password']);
        }

        $user->update($data);

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'Usuário atualizado com sucesso.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorizeAdmin();

        if ($user->is(auth()->user())) {
            return redirect()
                ->route('admin.users.index')
                ->with('error', 'Você não pode excluir a própria conta.');
        }

        $user->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'Usuário excluído com sucesso.');
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->check() && auth()->user()->is_admin, 403);
    }
}
