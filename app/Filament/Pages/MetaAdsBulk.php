<?php

namespace App\Filament\Pages;

use App\Filament\Pages\MetaSettings;
use App\Jobs\ProcessMetaAdBatch;
use App\Models\MetaAdBatch;
use App\Models\MetaConnection;
use App\Models\City;
use App\Services\Meta\MetaAdsService;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
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
use Filament\Support\Enums\MaxWidth;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
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
            'daily_budget' => 6.6,
            'start_at' => now()->addMinutes(10),
            'title_template' => '{cidade} RECEBE +40 CURSOS PROFISSIONALIZANTES',
            'body_template' => "Programa liberado para {cidade}.\n\nSem mensalidades e sem custo de material.\n\nClique em \"Saiba mais\" e garanta sua vaga.",
            'overlay_text' => '{cidade}',
            'overlay_text_color' => '#ffffff',
            'overlay_bg_color' => '#000000',
            'overlay_position_x' => 50,
            'overlay_position_y' => 12,
            'ad_account_id' => $connection?->ad_account_id,
            'page_id' => $connection?->page_id,
            'pixel_id' => $connection?->pixel_id,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Agendamento e Orcamento')
                    ->schema([
                        Select::make('destination_type')
                            ->label('Tipo de destino')
                            ->options($this->destinationTypeOptions())
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function (callable $set) {
                                $set('objective', null);
                            }),
                        Select::make('objective')
                            ->label('Objetivo')
                            ->options(fn (Get $get) => $this->objectiveOptions($get('destination_type')))
                            ->disabled(fn (Get $get) => blank($get('destination_type')))
                            ->placeholder('Selecione o tipo de destino')
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
                            ->required(fn () => $this->hasValidConnection())
                            ->disabled(fn () => !$this->hasValidConnection())
                            ->reactive()
                            ->afterStateUpdated(fn (callable $set) => $set('pixel_id', null)),
                        Select::make('page_id')
                            ->label('Pagina do Facebook')
                            ->options(fn () => $this->getPageOptions())
                            ->searchable()
                            ->required(fn () => $this->hasValidConnection())
                            ->disabled(fn () => !$this->hasValidConnection()),
                        Select::make('pixel_id')
                            ->label('Pixel')
                            ->options(fn (Get $get) => $this->getPixelOptions($get('ad_account_id')))
                            ->searchable()
                            ->required(fn () => $this->hasValidConnection())
                            ->disabled(fn () => !$this->hasValidConnection()),
                    ])
                    ->columns(2),
                Section::make('Destino e Segmentacao')
                    ->schema([
                        TextInput::make('url_template')
                            ->label('URL de destino')
                            ->required(fn () => ($this->data['destination_type'] ?? null) !== 'WHATSAPP')
                            ->visible(fn () => ($this->data['destination_type'] ?? null) !== 'WHATSAPP')
                            ->reactive()
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
                            ->reactive()
                            ->getSearchResultsUsing(fn (string $search) => $this->searchCities($search))
                            ->getOptionLabelsUsing(fn (array $values) => $this->getCityLabels($values))
                            ->disabled(fn (Get $get) => filled($get('state'))),
                    ])
                    ->columns(1),
                Section::make('Criativo')
                    ->schema([
                        FileUpload::make('image_path')
                            ->label('Imagem do anuncio')
                            ->disk('public')
                            ->directory('meta_ads/source')
                            ->image()
                            ->extraInputAttributes(['id' => 'meta-ads-image-input'])
                            ->deletable()
                            ->nullable()
                            ->maxSize(2048)
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state instanceof TemporaryUploadedFile) {
                                    $set('image_preview_url', $state->temporaryUrl());
                                    return;
                                }

                                if (is_array($state)) {
                                    $file = reset($state);
                                    if ($file instanceof TemporaryUploadedFile) {
                                        $set('image_preview_url', $file->temporaryUrl());
                                        return;
                                    }
                                }

                                $set('image_preview_url', null);
                            })
                            ->disabled(fn (Get $get) => blank($get('state')) && empty($get('city_ids')))
                            ->helperText('Selecione um estado ou cidades antes de enviar a imagem.')
                            ->required(),
                        Hidden::make('image_preview_url')
                            ->dehydrated(false),
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
                Section::make('Bloco de texto na imagem')
                    ->description('Arraste o bloco na previa para posicionar.')
                    ->schema([
                        Textarea::make('overlay_text')
                            ->label('Texto do bloco')
                            ->rows(2)
                            ->live()
                            ->helperText('Opcional. Use {cidade} como placeholder.'),
                        ColorPicker::make('overlay_text_color')
                            ->label('Cor do texto')
                            ->required()
                            ->live(),
                        Toggle::make('overlay_bg_transparent')
                            ->label('Fundo transparente')
                            ->live()
                            ->afterStateUpdated(fn ($state, callable $set) => $state ? $set('overlay_bg_color', null) : $set('overlay_bg_color', '#000000')),    
                        ColorPicker::make('overlay_bg_color')
                            ->label('Cor do fundo')
                            ->required(fn (Get $get) => !$get('overlay_bg_transparent'))
                            ->disabled(fn (Get $get) => (bool) $get('overlay_bg_transparent'))
                            ->live(),
                        Hidden::make('overlay_position_x')
                            ->required(),
                        Hidden::make('overlay_position_y')
                            ->required(),
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

        if (empty($data['pixel_id'])) {
            Notification::make()
                ->danger()
                ->title('Selecione um pixel antes de criar o lote.')
                ->send();
            return;
        }

        $destinationType = $data['destination_type'] ?? null;
        $whatsappNumber = null;
        $urlTemplate = $data['url_template'] ?? '';

        if ($destinationType === 'WHATSAPP') {
            $whatsappNumber = is_string($data['whatsapp_number'] ?? null)
                ? preg_replace('/\D/', '', $data['whatsapp_number'])
                : null;

            $urlTemplate = !empty($data['page_id'])
                ? sprintf('https://www.facebook.com/%s', $data['page_id'])
                : '';
        }

        $user = Auth::user();

        $instagramActorId = $data['instagram_actor_id'] ?? $this->connection()?->instagram_actor_id;

        $batch = MetaAdBatch::create([
            'user_id' => $user->id,
            'objective' => $data['objective'],
            'destination_type' => $destinationType,
            'ad_account_id' => $data['ad_account_id'],
            'page_id' => $data['page_id'] ?? null,
            'instagram_actor_id' => $instagramActorId,
            'pixel_id' => $data['pixel_id'] ?? null,
            'start_at' => $data['start_at'],
            'url_template' => $urlTemplate,
            'title_template' => $data['title_template'],
            'body_template' => $data['body_template'],
            'image_path' => $data['image_path'],
            'auto_activate' => (bool) ($data['auto_activate'] ?? false),
            'daily_budget_cents' => (int) round(((float) $data['daily_budget']) * 100),
            'settings' => [
                'destination_type' => $destinationType,
                'whatsapp_number' => $whatsappNumber,
                'state' => $data['state'] ?? null,
                'city_ids' => $data['city_ids'] ?? [],
                'overlay_text' => $data['overlay_text'] ?? '',
                'overlay_text_color' => $data['overlay_text_color'] ?? '#ffffff',
                'overlay_bg_color' => $data['overlay_bg_transparent'] ? 'transparent' : ($data['overlay_bg_color'] ?? '#000000'),
                'overlay_bg_transparent' => (bool) $data['overlay_bg_transparent'],
                'overlay_position_x' => $data['overlay_position_x'] ?? 50,
                'overlay_position_y' => $data['overlay_position_y'] ?? 12,
                
            ],
        ]);

        MetaConnection::updateOrCreate(
            ['user_id' => $user->id],
            [
                'ad_account_id' => $data['ad_account_id'],
                'page_id' => $data['page_id'] ?? null,
                'instagram_actor_id' => $instagramActorId,
                'pixel_id' => $data['pixel_id'] ?? null,
            ]
        );

        ProcessMetaAdBatch::dispatch($batch->id);

        Notification::make()
            ->success()
            ->title('Lote enviado para processamento.')
            ->send();

        $this->data['image_preview_url'] = null;
    }

    public function addImage(): void
    {
        $this->data['image_path'] = null;
        $this->data['image_preview_url'] = null;
        $this->dispatch('meta-ads-image-picker');
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
                TextColumn::make('error_message')
                    ->label('Erro')
                    ->limit(80)
                    ->placeholder('-')
                    ->wrap(),
                TextColumn::make('success_count')
                    ->label('Sucesso'),
                TextColumn::make('error_count')
                    ->label('Erros'),
                TextColumn::make('meta_campaign_id')
                    ->label('Campanha'),
            ])
            ->actions([
                TableAction::make('viewBatchItems')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (MetaAdBatch $record) => 'Itens do lote #' . $record->id)
                    ->modalWidth(MaxWidth::SevenExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->modalContent(fn (MetaAdBatch $record) => view('filament.pages.meta-ad-batch-items-modal', [
                        'batchId' => $record->id,
                        'batchErrorMessage' => $record->error_message,
                    ])),
                TableAction::make('cancelBatch')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (MetaAdBatch $record) => $this->cancelBatch($record))
                    ->visible(fn (MetaAdBatch $record) => in_array($record->status, ['queued', 'processing'], true)),
            ]);
    }

    public function cancelBatch(MetaAdBatch $record): void
    {
        if ($record->user_id !== Auth::id()) {
            Notification::make()
                ->danger()
                ->title('Voce nao tem permissao para cancelar este lote.')
                ->send();
            return;
        }

        if (!in_array($record->status, ['queued', 'processing'], true)) {
            Notification::make()
                ->warning()
                ->title('Este lote nao pode ser cancelado.')
                ->send();
            return;
        }

        $newStatus = $record->status === 'queued' ? 'cancelled' : 'cancel_requested';

        $record->update([
            'status' => $newStatus,
            'cancel_requested_at' => now(),
            'cancelled_at' => $newStatus === 'cancelled' ? now() : null,
        ]);

        Notification::make()
            ->success()
            ->title('Solicitacao de cancelamento registrada.')
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connectMeta')
                ->label('Conectar com Facebook')
                ->action('openSdkConnect')
                ->visible(fn () => $this->hasAppId() && !$this->hasValidConnection()),
            Action::make('metaSettings')
                ->label('Configurar App')
                ->url(fn () => MetaSettings::getUrl())
                ->visible(fn () => !$this->hasAppId()),
            Action::make('refreshMeta')
                ->label('Atualizar listas')
                ->action(fn () => $this->refreshMetaAssets())
                ->visible(fn () => $this->hasValidConnection()),
        ];
    }

    public function openSdkConnect(): void
    {
        $connection = $this->connection();
        $appId = $connection?->app_id;

        if (!$appId) {
            Notification::make()
                ->danger()
                ->title('Cadastre o App ID antes de conectar.')
                ->send();
            return;
        }

        $this->dispatch('meta-sdk-connect', appId: $appId);
    }

    public function refreshMetaAssets(): void
    {
        $user = Auth::user();
        $service = app(MetaAdsService::class);
        $service->forgetCacheForUser($user->id);
        $service->forgetInstagramAccountsCacheForUser($user->id, $this->data['ad_account_id'] ?? null);
        $service->forgetPixelsCacheForUser($user->id, $this->data['ad_account_id'] ?? null);

        Notification::make()
            ->success()
            ->title('Listas atualizadas.')
            ->send();
    }

    public function getPreviewImageUrlProperty(): ?string
    {
        $previewUrl = $this->data['image_preview_url'] ?? null;
        if (is_string($previewUrl) && $previewUrl !== '') {
            return $previewUrl;
        }

        $imagePath = $this->data['image_path'] ?? null;
        if (is_string($imagePath) && $imagePath !== '') {
            return Storage::disk('public')->url($imagePath);
        }

        return null;
    }

    private function connection(): ?MetaConnection
    {
        return Auth::user()?->metaConnection;
    }

    private function hasAppId(): bool
    {
        return (bool) $this->connection()?->app_id;
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

    private function getAdAccountOptions(): array
    {
        if (!$this->hasValidConnection()) {
            return [];
        }

        try {
            Log::info('MetaAdsBulk fetching ad accounts', ['user_id' => Auth::id()]);
            $service = app(MetaAdsService::class);
            return $service->fetchAdAccounts($this->connection()->access_token, Auth::id());
        } catch (Throwable $exception) {
            Log::error('MetaAdsBulk fetch ad accounts failed', ['user_id' => Auth::id(), 'exception' => $exception->getMessage()]);
            return [];
        }
    }

    private function getPageOptions(): array
    {
        if (!$this->hasValidConnection()) {
            return [];
        }

        try {
            Log::info('MetaAdsBulk fetching pages', ['user_id' => Auth::id()]);
            $service = app(MetaAdsService::class);
            return $service->fetchPages($this->connection()->access_token, Auth::id());
        } catch (Throwable $exception) {
            Log::error('MetaAdsBulk fetch pages failed', ['user_id' => Auth::id(), 'exception' => $exception->getMessage()]);
            return [];
        }
    }

    private function getInstagramOptions(): array
    {
        if (!$this->hasValidConnection()) {
            return [];
        }

        try {
            Log::info('MetaAdsBulk fetching instagram accounts', ['user_id' => Auth::id()]);
            $service = app(MetaAdsService::class);
            $adAccountId = $this->data['ad_account_id'] ?? null;
            return $service->fetchInstagramAccounts($this->connection()->access_token, Auth::id(), $adAccountId, [
                'ad_account_id' => $adAccountId,
            ]);
        } catch (Throwable $exception) {
            Log::error('MetaAdsBulk fetch instagram accounts failed', ['user_id' => Auth::id(), 'exception' => $exception->getMessage()]);
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
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        $normalizedSearch = $this->normalizeSearchText($search);
        $normalizedNameSql = $this->normalizedNameSql('name');

        return City::query()
            ->where(function ($query) use ($search, $normalizedSearch, $normalizedNameSql) {
                $query->where('name', 'like', '%' . $search . '%');

                if ($normalizedSearch !== '') {
                    $query->orWhereRaw("{$normalizedNameSql} like ?", ['%' . $normalizedSearch . '%']);
                }
            })
            ->limit(20)
            ->get()
            ->mapWithKeys(fn (City $city) => [$city->id => sprintf('%s - %s', $city->name, $city->state)])
            ->all();
    }

    private function normalizeSearchText(string $value): string
    {
        return (string) Str::of($value)->trim()->lower()->ascii();
    }

    private function normalizedNameSql(string $column): string
    {
        $expression = sprintf('LOWER(%s)', $column);

        foreach ($this->accentMap() as $from => $to) {
            $expression = sprintf("REPLACE(%s, '%s', '%s')", $expression, $from, $to);
        }

        return $expression;
    }

    private function accentMap(): array
    {
        return [
            "\u{00E1}" => 'a',
            "\u{00E0}" => 'a',
            "\u{00E2}" => 'a',
            "\u{00E3}" => 'a',
            "\u{00E4}" => 'a',
            "\u{00E5}" => 'a',
            "\u{00E9}" => 'e',
            "\u{00E8}" => 'e',
            "\u{00EA}" => 'e',
            "\u{00EB}" => 'e',
            "\u{00ED}" => 'i',
            "\u{00EC}" => 'i',
            "\u{00EE}" => 'i',
            "\u{00EF}" => 'i',
            "\u{00F3}" => 'o',
            "\u{00F2}" => 'o',
            "\u{00F4}" => 'o',
            "\u{00F5}" => 'o',
            "\u{00F6}" => 'o',
            "\u{00FA}" => 'u',
            "\u{00F9}" => 'u',
            "\u{00FB}" => 'u',
            "\u{00FC}" => 'u',
            "\u{00E7}" => 'c',
            "\u{00F1}" => 'n',
        ];
    }

    private function getCityLabels(array $values): array
    {
        return City::query()
            ->whereIn('id', $values)
            ->get()
            ->mapWithKeys(fn (City $city) => [$city->id => sprintf('%s - %s', $city->name, $city->state)])
            ->all();
    }

    private function destinationTypeOptions(): array
    {
        return [
            'WEBSITE' => 'Website',
            'WHATSAPP' => 'WhatsApp',
        ];
    }

    private function objectiveOptions(?string $destinationType): array
    {
        if (!$destinationType) {
            return [];
        }

        return match ($destinationType) {
            'WEBSITE' => [
                'OUTCOME_AWARENESS' => 'Reconhecimento',
                'OUTCOME_LEADS' => 'Cadastros',
                'OUTCOME_LEADS_CONTENT_VIEW' => 'ContentView',
                'OUTCOME_SALES' => 'Vendas',
            ],
            'WHATSAPP' => [
                'OUTCOME_AWARENESS' => 'Reconhecimento',
                'OUTCOME_TRAFFIC' => 'Trafego',
                'OUTCOME_ENGAGEMENT' => 'Engajamento',
            ],
            default => [],
        };
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
}
