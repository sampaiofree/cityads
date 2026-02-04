<x-layouts.app :title="__('Usuários')">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <flux:heading>{{ __('Usuários') }}</flux:heading>
                <flux:subheading>{{ __('Gerencie os usuários do sistema') }}</flux:subheading>
            </div>

            <flux:button id="open-create-user" variant="filled">
                {{ __('Criar usuário') }}
            </flux:button>
        </div>

        @if (session('status'))
            <flux:callout variant="success">
                {{ session('status') }}
            </flux:callout>
        @endif

        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                    <thead class="bg-zinc-50 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:bg-zinc-800 dark:text-zinc-300">
                        <tr>
                            <th class="px-4 py-3">{{ __('Nome') }}</th>
                            <th class="px-4 py-3">{{ __('Email') }}</th>
                            <th class="px-4 py-3">{{ __('Admin') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Ações') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse ($users as $user)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                                <td class="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">{{ $user->name }}</td>
                                <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $user->email }}</td>
                                <td class="px-4 py-3">
                                    @if ($user->is_admin)
                                        <span class="rounded-full bg-emerald-100 px-2 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200">Sim</span>
                                    @else
                                        <span class="rounded-full bg-zinc-100 px-2 py-1 text-xs font-semibold text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">Não</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <flux:button
                                        class="edit-user"
                                        variant="outline"
                                        size="sm"
                                        data-id="{{ $user->id }}"
                                        data-name="{{ $user->name }}"
                                        data-email="{{ $user->email }}"
                                        data-is-admin="{{ $user->is_admin ? '1' : '0' }}"
                                    >
                                        {{ __('Editar') }}
                                    </flux:button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="px-4 py-6 text-center text-zinc-500 dark:text-zinc-400" colspan="4">
                                    {{ __('Nenhum usuário encontrado.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <dialog id="user-modal" class="w-full max-w-lg rounded-xl bg-white p-0 shadow-xl backdrop:bg-black/40 dark:bg-zinc-900">
        <form method="POST" id="user-form" class="space-y-6 p-6">
            @csrf
            <input type="hidden" name="_method" id="user-form-method" value="PUT" disabled />

            <div class="space-y-2">
                <h2 id="user-modal-title" class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">
                    {{ __('Criar usuário') }}
                </h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400" id="user-modal-subtitle">
                    {{ __('Informe os dados para o novo usuário.') }}
                </p>
            </div>

            <div class="grid gap-4">
                <flux:input name="name" id="user-name" :label="__('Nome')" required />
                <flux:input name="email" id="user-email" type="email" :label="__('Email')" required />
                <flux:input name="password" id="user-password" type="password" :label="__('Senha')" />

                <label class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-200">
                    <input type="checkbox" name="is_admin" id="user-is-admin" class="h-4 w-4 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-900" />
                    {{ __('Administrador') }}
                </label>
            </div>

            <div class="flex items-center justify-end gap-2">
                <button type="button" id="close-user-modal" class="rounded-lg border border-zinc-200 px-4 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                    {{ __('Cancelar') }}
                </button>
                <button type="submit" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800">
                    {{ __('Salvar') }}
                </button>
            </div>
        </form>
    </dialog>

    <script>
        (() => {
            const modal = document.getElementById('user-modal');
            const form = document.getElementById('user-form');
            const methodInput = document.getElementById('user-form-method');
            const title = document.getElementById('user-modal-title');
            const subtitle = document.getElementById('user-modal-subtitle');
            const nameInput = document.getElementById('user-name');
            const emailInput = document.getElementById('user-email');
            const passwordInput = document.getElementById('user-password');
            const adminInput = document.getElementById('user-is-admin');
            const closeButton = document.getElementById('close-user-modal');

            const openCreate = () => {
                form.action = "{{ route('admin.users.store') }}";
                methodInput.disabled = true;
                title.textContent = 'Criar usuário';
                subtitle.textContent = 'Informe os dados para o novo usuário.';
                nameInput.value = '';
                emailInput.value = '';
                passwordInput.value = '';
                adminInput.checked = false;
                modal.showModal();
            };

            const openEdit = (button) => {
                const userId = button.dataset.id;
                form.action = "{{ url('adm/usuarios') }}/" + userId;
                methodInput.disabled = false;
                title.textContent = 'Editar usuário';
                subtitle.textContent = 'Atualize os dados do usuário.';
                nameInput.value = button.dataset.name || '';
                emailInput.value = button.dataset.email || '';
                passwordInput.value = '';
                adminInput.checked = button.dataset.isAdmin === '1';
                modal.showModal();
            };

            document.getElementById('open-create-user').addEventListener('click', openCreate);
            document.querySelectorAll('.edit-user').forEach((button) => {
                button.addEventListener('click', () => openEdit(button));
            });
            closeButton.addEventListener('click', () => modal.close());
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    modal.close();
                }
            });
        })();
    </script>
</x-layouts.app>
