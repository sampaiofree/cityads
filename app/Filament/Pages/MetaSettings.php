<?php

namespace App\Filament\Pages;

use App\Models\MetaConnection;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class MetaSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Meta Ads';
    protected static ?string $navigationLabel = 'Configuracoes Meta';
    protected static ?string $title = 'Configuracoes Meta';
    protected static ?int $navigationSort = 1;
    protected static ?string $slug = 'meta-settings';
    protected static string $view = 'filament.pages.meta-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $connection = $this->connection();

        $this->form->fill([
            'app_id' => $connection?->app_id,
            'app_secret' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('App do Meta')
                    ->description('Cadastre o App ID e conecte pelo Facebook.')
                    ->schema([
                        TextInput::make('app_id')
                            ->label('App ID')
                            ->numeric()
                            ->required()
                            ->helperText('Use o App ID do seu app no Meta for Developers.'),
                        TextInput::make('app_secret')
                            ->label('App Secret')
                            ->password()
                            ->revealable()
                            ->helperText('Opcional, mas recomendado para token de longa duracao.')
                            ->dehydrateStateUsing(fn ($state) => $state ?: null),
                        Placeholder::make('app_secret_status')
                            ->label('App Secret')
                            ->content(fn () => $this->connectionHasSecret() ? 'Preenchido' : 'Nao preenchido'),
                    ])
                    ->columns(2),
                Section::make('Status da conexao')
                    ->schema([
                        Placeholder::make('meta_status')
                            ->label('Status')
                            ->content(fn () => $this->connectionStatus()),
                    ])
                    ->columns(1),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $this->form->validate();
        $data = $this->form->getState();

        $payload = [
            'app_id' => $data['app_id'],
        ];

        if (!empty($data['app_secret'])) {
            $payload['app_secret'] = $data['app_secret'];
        }

        $connection = MetaConnection::firstOrNew(['user_id' => Auth::id()]);
        $oldAppId = $connection->app_id;

        $connection->fill($payload);

        if ($oldAppId && $oldAppId !== $payload['app_id']) {
            $connection->access_token = null;
            $connection->token_expires_at = null;
        }

        $connection->save();

        $this->form->fill([
            'app_id' => $payload['app_id'],
            'app_secret' => null,
        ]);

        Notification::make()
            ->success()
            ->title('Configuracoes salvas.')
            ->send();
    }

    public function connectWithFacebook(): void
    {
        $this->save();

        $appId = $this->data['app_id'] ?? null;

        if (!$appId) {
            Notification::make()
                ->danger()
                ->title('Informe o App ID antes de conectar.')
                ->send();
            return;
        }

        $this->dispatch('meta-sdk-connect', appId: $appId);
    }

    private function connection(): ?MetaConnection
    {
        return Auth::user()?->metaConnection;
    }

    private function connectionStatus(): string
    {
        $connection = $this->connection();
        if (!$connection || !$connection->app_id) {
            return 'App ID nao configurado.';
        }

        if (!$connection->access_token) {
            return 'Nao conectado.';
        }

        if ($connection->token_expires_at && $connection->token_expires_at->isPast()) {
            return 'Token expirado. Conecte novamente.';
        }

        return 'Conectado.';
    }

    private function connectionHasSecret(): bool
    {
        return (bool) $this->connection()?->app_secret;
    }
}
