<x-filament::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap gap-2">
            <x-filament::button type="submit">
                Salvar configuracoes
            </x-filament::button>
            <x-filament::button type="button" color="gray" wire:click="connectWithFacebook">
                Conectar com Facebook
            </x-filament::button>
        </div>
    </form>

    @include('partials.meta-sdk')
</x-filament::page>
