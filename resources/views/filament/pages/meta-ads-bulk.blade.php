<x-filament::page>
    <form wire:submit="createBatch" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit">
            Criar anuncios
        </x-filament::button>
    </form>

    <x-filament::section class="mt-8">
        <x-slot name="heading">
            Historico de lotes
        </x-slot>

        {{ $this->table }}
    </x-filament::section>

    @push('scripts')
        <script>
            window.addEventListener('meta-connect', (event) => {
                const url = event?.detail?.url;
                if (!url) {
                    return;
                }

                const width = 600;
                const height = 700;
                const left = Math.max(0, (window.screen.width - width) / 2);
                const top = Math.max(0, (window.screen.height - height) / 2);

                window.open(
                    url,
                    'metaConnect',
                    `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes`
                );
            });
        </script>
    @endpush
</x-filament::page>
