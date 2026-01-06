<?php

namespace App\Filament\Pages;

use App\Jobs\ProcessMetaAdBatch;
use App\Models\MetaAdBatch;
use App\Models\MetaConnection;
use App\Models\City;
use App\Services\Meta\MetaAdsService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Throwable;

class MetaAdsBulk extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';
    protected static ?string $navigationGroup = 'Meta Ads';
    protected static ?string $navigationLabel = 'Criar anuncios';
    protected static ?string $title = 'Criar anuncios em massa';
    protected static ?int $navigationSort = 2;
    protected static ?string $slug = 'meta-ads';
    protected static string $view = 'filament.pages.meta-ads-bulk';

    public ?array $data = [];

    public function mount(): void
    {
        $connection = $this->connection();

        $this->form->fill([
            'app_id' => $connection?->app_id,
            'app_secret' => null,
            'objective' => 'OUTCOME_LEADS',
            'daily_budget' => 6.6,
            'start_at' => now()->addMinutes(10),
            'title_template' => '{cidade} RECEBE +40 CURSOS PROFISSIONALIZANTES',
            'body_template' => "Programa liberado para {cidade}.\n\nSem mensalidades e sem custo de material.\n\nClique em \"Saiba mais\" e garanta sua vaga.",
            'ad_account_id' => $connection?->ad_account_id,
            'page_id' => $connection?->page_id,
            'instagram_actor_id' => $connection?->instagram_actor_id,
            'pixel_id' => $connection?->pixel_id,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('App do Meta')
                    ->description('Cadastre o App ID e App Secret antes de conectar.')
                    ->schema([
                        TextInput::make('app_id')
                            ->label('App ID')
                            ->required()
                            ->helperText('Use o App ID do seu app no Meta for Developers.'),
                        TextInput::make('app_secret')
                            ->label('App Secret')
                            ->password()
                            ->revealable()
                            ->helperText('Necessario para concluir a conexao.')
                            ->dehydrateStateUsing(fn ($state) => $state ?: null),
                    ])
                    ->columns(2),
                Section::make('Conexao Meta')
                    ->description('Conecte sua conta do Meta para carregar os ativos.')
                    ->schema([
                        Placeholder::make('meta_status')
                            ->label('Status')
                            ->content(fn () => $this->connectionStatus()),
                        Select::make('ad_account_id')
                            ->label('Conta de anuncios')
                            ->options(fn () => $this->getAdAccountOptions())
                            ->searchable()
                            ->required()
                            ->disabled(fn () => !$this->hasValidConnection())
                            ->reactive()
                            ->afterStateUpdated(fn (callable $set) => $set('pixel_id', null)),
                        Select::make('page_id')
                            ->label('Pagina do Facebook')
                            ->options(fn () => $this->getPageOptions())
                            ->searchable()
                            ->required()
                            ->disabled(fn () => !$this->hasValidConnection()),
                        Select::make('instagram_actor_id')
                            ->label('Conta do Instagram')
                            ->options(fn () => $this->getInstagramOptions())
                            ->searchable()
                            ->disabled(fn () => !$this->hasValidConnection()),
                        Select::make('pixel_id')
                            ->label('Pixel')
                            ->options(fn (Get $get) => $this->getPixelOptions($get('ad_account_id')))
                            ->searchable()
                            ->required()
                            ->disabled(fn () => !$this->hasValidConnection()),
                    ])
                    ->columns(2),
                Section::make('Criativo')
                    ->schema([
                        FileUpload::make('image_path')
                            ->label('Imagem do anuncio')
                            ->disk('public')
                            ->directory('meta_ads/source')
                            ->image()
                            ->maxSize(2048)
                            ->required(),
                        TextInput::make('title_template')
                            ->label('Titulo')
                            ->required()
                            ->helperText('Use {cidade} para inserir o nome da cidade.'),
                        Textarea::make('body_template')
                            ->label('Texto do anuncio')
                            ->required()
                            ->rows(6)
                            ->helperText('Use {cidade} para inserir o nome da cidade.'),
                    ])
                    ->columns(1),
                Section::make('Destino e Segmentacao')
                    ->schema([
                        TextInput::make('url_template')
                            ->label('URL de destino')
                            ->required()
                            ->helperText('Use {cidade} para inserir o nome da cidade.'),
                        Select::make('state')
                            ->label('Estado')
                            ->options($this->stateOptions())
                            ->searchable()
                            ->helperText('Ao selecionar um estado, todas as cidades dele serao usadas.')
                            ->reactive()
                            ->afterStateUpdated(fn (callable $set) => $set('city_ids', [])),
                        Select::make('city_ids')
                            ->label('Cidades')
                            ->multiple()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => $this->searchCities($search))
                            ->getOptionLabelsUsing(fn (array $values) => $this->getCityLabels($values))
                            ->disabled(fn (Get $get) => filled($get('state'))),
                    ])
                    ->columns(1),
                Section::make('Agendamento e Orcamento')
                    ->schema([
                        Select::make('objective')
                            ->label('Objetivo')
                            ->options([
                                'OUTCOME_LEADS' => 'Cadastros',
                                'OUTCOME_SALES' => 'Vendas',
                            ])
                            ->required(),
                        DateTimePicker::make('start_at')
                            ->label('Inicio da campanha')
                            ->required(),
                        TextInput::make('daily_budget')
                            ->label('Orcamento diario (R$)')
                            ->numeric()
                            ->required(),
                        Toggle::make('auto_activate')
                            ->label('Ativar automaticamente'),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function createBatch(): void
    {
        $data = $this->form->getState();

        if (!$this->hasValidConnection()) {
            Notification::make()
                ->danger()
                ->title('Conecte sua conta Meta antes de criar o lote.')
                ->send();
            return;
        }

        if (empty($data['state']) && empty($data['city_ids'])) {
            Notification::make()
                ->danger()
                ->title('Selecione pelo menos um estado ou uma cidade.')
                ->send();
            return;
        }

        $user = Auth::user();

        $batch = MetaAdBatch::create([
            'user_id' => $user->id,
            'objective' => $data['objective'],
            'ad_account_id' => $data['ad_account_id'],
            'page_id' => $data['page_id'] ?? null,
            'instagram_actor_id' => $data['instagram_actor_id'] ?? null,
            'pixel_id' => $data['pixel_id'] ?? null,
            'start_at' => $data['start_at'],
            'url_template' => $data['url_template'],
            'title_template' => $data['title_template'],
            'body_template' => $data['body_template'],
            'image_path' => $data['image_path'],
            'auto_activate' => (bool) ($data['auto_activate'] ?? false),
            'daily_budget_cents' => (int) round(((float) $data['daily_budget']) * 100),
            'settings' => [
                'state' => $data['state'] ?? null,
                'city_ids' => $data['city_ids'] ?? [],
            ],
        ]);

        MetaConnection::updateOrCreate(
            ['user_id' => $user->id],
            $this->buildMetaConnectionPayload($data)
        );

        ProcessMetaAdBatch::dispatch($batch->id);

        Notification::make()
            ->success()
            ->title('Lote enviado para processamento.')
            ->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(MetaAdBatch::query()->where('user_id', Auth::id())->latest())
            ->columns([
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('success_count')
                    ->label('Sucesso'),
                TextColumn::make('error_count')
                    ->label('Erros'),
                TextColumn::make('meta_campaign_id')
                    ->label('Campanha'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connectMeta')
                ->label('Conectar Meta')
                ->action('openConnectPopup')
                ->visible(fn () => !$this->hasValidConnection()),
            Action::make('refreshMeta')
                ->label('Atualizar listas')
                ->action(fn () => $this->refreshMetaAssets())
                ->visible(fn () => $this->hasValidConnection()),
        ];
    }

    public function openConnectPopup(): void
    {
        $data = $this->form->getState();

        if (empty($data['app_id']) || empty($data['app_secret'])) {
            Notification::make()
                ->danger()
                ->title('Informe App ID e App Secret antes de conectar.')
                ->send();
            return;
        }

        $user = Auth::user();
        MetaConnection::updateOrCreate(
            ['user_id' => $user->id],
            $this->buildMetaConnectionPayload($data)
        );

        $this->dispatch('meta-connect', url: route('meta.connect', ['popup' => 1]));
    }

    public function refreshMetaAssets(): void
    {
        $user = Auth::user();
        $service = app(MetaAdsService::class);
        $service->forgetCacheForUser($user->id);
        $service->forgetPixelsCacheForUser($user->id, $this->data['ad_account_id'] ?? null);

        Notification::make()
            ->success()
            ->title('Listas atualizadas.')
            ->send();
    }

    private function connection(): ?MetaConnection
    {
        return Auth::user()?->metaConnection;
    }

    private function hasValidConnection(): bool
    {
        $connection = $this->connection();
        if (!$connection || !$connection->access_token) {
            return false;
        }

        if ($connection->token_expires_at && $connection->token_expires_at->isPast()) {
            return false;
        }

        return true;
    }

    private function connectionStatus(): string
    {
        $connection = $this->connection();
        if (!$connection || !$connection->app_id || !$connection->app_secret) {
            return 'App ID e App Secret nao configurados.';
        }

        if (!$connection->access_token) {
            return 'Nao conectado.';
        }

        if ($connection->token_expires_at && $connection->token_expires_at->isPast()) {
            return 'Token expirado. Conecte novamente.';
        }

        return 'Conectado.';
    }

    private function getAdAccountOptions(): array
    {
        if (!$this->hasValidConnection()) {
            return [];
        }

        try {
            $service = app(MetaAdsService::class);
            return $service->fetchAdAccounts($this->connection()->access_token, Auth::id());
        } catch (Throwable) {
            return [];
        }
    }

    private function getPageOptions(): array
    {
        if (!$this->hasValidConnection()) {
            return [];
        }

        try {
            $service = app(MetaAdsService::class);
            return $service->fetchPages($this->connection()->access_token, Auth::id());
        } catch (Throwable) {
            return [];
        }
    }

    private function getInstagramOptions(): array
    {
        if (!$this->hasValidConnection()) {
            return [];
        }

        try {
            $service = app(MetaAdsService::class);
            return $service->fetchInstagramAccounts($this->connection()->access_token, Auth::id());
        } catch (Throwable) {
            return [];
        }
    }

    private function getPixelOptions(?string $adAccountId): array
    {
        if (!$this->hasValidConnection() || !$adAccountId) {
            return [];
        }

        try {
            $service = app(MetaAdsService::class);
            return $service->fetchPixels($this->connection()->access_token, Auth::id(), $adAccountId);
        } catch (Throwable) {
            return [];
        }
    }

    private function searchCities(string $search): array
    {
        return City::query()
            ->where('name', 'like', '%' . $search . '%')
            ->limit(20)
            ->get()
            ->mapWithKeys(fn (City $city) => [$city->id => sprintf('%s - %s', $city->name, $city->state)])
            ->all();
    }

    private function getCityLabels(array $values): array
    {
        return City::query()
            ->whereIn('id', $values)
            ->get()
            ->mapWithKeys(fn (City $city) => [$city->id => sprintf('%s - %s', $city->name, $city->state)])
            ->all();
    }

    private function stateOptions(): array
    {
        return [
            'Acre' => 'Acre',
            'Alagoas' => 'Alagoas',
            'Amapa' => 'Amapa',
            'Amazonas' => 'Amazonas',
            'Bahia' => 'Bahia',
            'Ceara' => 'Ceara',
            'Distrito Federal' => 'Distrito Federal',
            'Espirito Santo' => 'Espirito Santo',
            'Goias' => 'Goias',
            'Maranhao' => 'Maranhao',
            'Mato Grosso' => 'Mato Grosso',
            'Mato Grosso do Sul' => 'Mato Grosso do Sul',
            'Minas Gerais' => 'Minas Gerais',
            'Para' => 'Para',
            'Paraiba' => 'Paraiba',
            'Parana' => 'Parana',
            'Pernambuco' => 'Pernambuco',
            'Piaui' => 'Piaui',
            'Rio de Janeiro' => 'Rio de Janeiro',
            'Rio Grande do Norte' => 'Rio Grande do Norte',
            'Rio Grande do Sul' => 'Rio Grande do Sul',
            'Rondonia' => 'Rondonia',
            'Roraima' => 'Roraima',
            'Santa Catarina' => 'Santa Catarina',
            'Sao Paulo' => 'Sao Paulo',
            'Sergipe' => 'Sergipe',
            'Tocantins' => 'Tocantins',
        ];
    }

    private function buildMetaConnectionPayload(array $data): array
    {
        $payload = Arr::only($data, [
            'app_id',
            'app_secret',
            'ad_account_id',
            'page_id',
            'instagram_actor_id',
            'pixel_id',
        ]);

        return array_filter($payload, fn ($value) => $value !== null && $value !== '');
    }
}
