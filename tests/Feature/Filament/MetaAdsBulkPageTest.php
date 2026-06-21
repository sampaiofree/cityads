<?php

use App\Filament\Pages\MetaAdsBulk;
use App\Jobs\ProcessMetaAdBatch;
use App\Models\MetaAdBatch;
use App\Models\MetaConnection;
use App\Models\User;
use App\Services\Meta\MetaAdsService;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->withoutVite();
});

test('fixed campaign fields and automatic domain URL are read only', function () {
    $user = User::factory()->create([
        'dominio' => 'http://ead1.portalje.org///',
    ]);

    Livewire::actingAs($user)
        ->test(MetaAdsBulk::class)
        ->assertFormSet([
            'destination_type' => 'WEBSITE',
            'objective' => 'OUTCOME_LEADS',
            'url_template' => 'https://ead1.portalje.org?c={cidade}',
        ])
        ->assertFormFieldIsDisabled('destination_type')
        ->assertFormFieldIsDisabled('objective')
        ->assertFormFieldIsDisabled('url_template')
        ->assertFormFieldDoesNotExist('auto_activate')
        ->assertFormFieldExists('image_path', function (FileUpload $field): bool {
            return $field->getAcceptedFileTypes() === [
                'image/jpeg',
                'image/png',
                'image/webp',
            ];
        });
});

test('users without a domain can enter the destination URL manually', function () {
    $user = User::factory()->create(['dominio' => null]);

    Livewire::actingAs($user)
        ->test(MetaAdsBulk::class)
        ->assertFormSet(['url_template' => null])
        ->assertFormFieldIsEnabled('url_template');
});

test('invalid forms do not open the confirmation modal', function () {
    $user = User::factory()->create(['dominio' => null]);

    Livewire::actingAs($user)
        ->test(MetaAdsBulk::class)
        ->call('prepareCreateBatch')
        ->assertSet('mountedActions', [])
        ->assertHasErrors();
});

test('confirmation modal warns the user and cancellation creates nothing', function () {
    Queue::fake();

    $user = createMetaAdsPageUser();
    fakeMetaAdsPageService();

    Livewire::actingAs($user)
        ->test(MetaAdsBulk::class)
        ->fillForm(validExistingPostFormData())
        ->call('prepareCreateBatch')
        ->assertSet('mountedActions.0', 'confirmCreateBatch')
        ->assertSee('Nao nos responsabilizamos pelos gastos')
        ->assertSee('ativar manualmente a campanha')
        ->assertSee('limite de gastos')
        ->call('unmountAction');

    expect(MetaAdBatch::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('confirmed batches enforce website leads and paused status', function () {
    Queue::fake();

    $user = createMetaAdsPageUser();
    fakeMetaAdsPageService();

    Livewire::actingAs($user)
        ->test(MetaAdsBulk::class)
        ->fillForm(validExistingPostFormData())
        ->set('data.destination_type', 'WHATSAPP')
        ->set('data.objective', 'OUTCOME_SALES')
        ->set('data.auto_activate', true)
        ->call('prepareCreateBatch')
        ->call('callMountedAction')
        ->assertHasNoErrors();

    $batch = MetaAdBatch::query()->sole();

    expect($batch->destination_type)->toBe('WEBSITE')
        ->and($batch->objective)->toBe('OUTCOME_LEADS')
        ->and($batch->auto_activate)->toBeFalse()
        ->and($batch->url_template)->toBe('')
        ->and($batch->settings['creative_source_mode'])->toBe('existing_post')
        ->and($batch->settings['existing_post_id'])->toBe('123_456');

    Queue::assertPushed(ProcessMetaAdBatch::class, 1);
});

test('automatic URL overrides manipulated form data for image ads', function () {
    Queue::fake();
    Storage::fake('public');

    $user = createMetaAdsPageUser('https://cliente.portalje.org/');
    fakeMetaAdsPageService();

    Livewire::actingAs($user)
        ->test(MetaAdsBulk::class)
        ->fillForm([
            ...validBaseFormData(),
            'creative_source_mode' => 'single_media',
            'image_path' => UploadedFile::fake()->image('creative.jpg'),
            'url_template' => 'https://malicioso.example',
        ])
        ->call('prepareCreateBatch')
        ->call('callMountedAction')
        ->assertHasNoErrors();

    $batch = MetaAdBatch::query()->sole();

    expect($batch->url_template)->toBe('https://cliente.portalje.org?c={cidade}')
        ->and($batch->settings['creative_media_type'])->toBe('image')
        ->and($batch->auto_activate)->toBeFalse();
});

test('users without a domain keep the manually entered URL', function () {
    Queue::fake();
    Storage::fake('public');

    $user = createMetaAdsPageUser();
    fakeMetaAdsPageService();

    Livewire::actingAs($user)
        ->test(MetaAdsBulk::class)
        ->fillForm([
            ...validBaseFormData(),
            'creative_source_mode' => 'single_media',
            'image_path' => UploadedFile::fake()->image('creative.png'),
            'url_template' => 'https://manual.example/curso?cidade={cidade}',
        ])
        ->call('prepareCreateBatch')
        ->call('callMountedAction')
        ->assertHasNoErrors();

    expect(MetaAdBatch::query()->sole()->url_template)
        ->toBe('https://manual.example/curso?cidade={cidade}');
});

test('image rotation also uses the automatic domain URL', function () {
    Queue::fake();
    Storage::fake('public');

    $user = createMetaAdsPageUser('rodizio.portalje.org');
    fakeMetaAdsPageService();

    Livewire::actingAs($user)
        ->test(MetaAdsBulk::class)
        ->fillForm([
            ...validBaseFormData(),
            'creative_source_mode' => 'image_rotation',
            'rotation_image_paths' => [
                UploadedFile::fake()->image('creative-1.jpg'),
                UploadedFile::fake()->image('creative-2.webp'),
            ],
        ])
        ->call('prepareCreateBatch')
        ->call('callMountedAction')
        ->assertHasNoErrors();

    $batch = MetaAdBatch::query()->sole();

    expect($batch->url_template)->toBe('https://rodizio.portalje.org?c={cidade}')
        ->and($batch->settings['creative_source_mode'])->toBe('image_rotation')
        ->and($batch->settings['creative_image_paths'])->toHaveCount(2);
});

test('video uploads cannot open the confirmation modal', function () {
    Storage::fake('public');

    $user = createMetaAdsPageUser();
    fakeMetaAdsPageService();

    Livewire::actingAs($user)
        ->test(MetaAdsBulk::class)
        ->fillForm([
            ...validBaseFormData(),
            'creative_source_mode' => 'single_media',
            'image_path' => UploadedFile::fake()->create('creative.mp4', 100, 'video/mp4'),
        ])
        ->call('prepareCreateBatch')
        ->assertSet('mountedActions', [])
        ->assertHasErrors();
});

function createMetaAdsPageUser(?string $domain = null): User
{
    $user = User::factory()->create(['dominio' => $domain]);

    MetaConnection::query()->create([
        'user_id' => $user->id,
        'access_token' => 'access-token',
        'ad_account_id' => 'act_123',
        'page_id' => '123',
        'pixel_id' => 'pixel_123',
    ]);

    return $user;
}

function fakeMetaAdsPageService(): void
{
    $service = Mockery::mock(MetaAdsService::class);
    $service->shouldReceive('fetchAdAccounts')
        ->zeroOrMoreTimes()
        ->andReturn(['act_123' => 'Conta principal']);
    $service->shouldReceive('fetchPages')
        ->zeroOrMoreTimes()
        ->andReturn(['123' => 'Pagina principal']);
    $service->shouldReceive('fetchPixels')
        ->zeroOrMoreTimes()
        ->andReturn(['pixel_123' => 'Pixel principal']);

    app()->instance(MetaAdsService::class, $service);
}

function validBaseFormData(): array
{
    return [
        'ad_account_id' => 'act_123',
        'page_id' => '123',
        'pixel_id' => 'pixel_123',
        'state' => 'Goias',
    ];
}

function validExistingPostFormData(): array
{
    return [
        ...validBaseFormData(),
        'creative_source_mode' => 'existing_post',
        'existing_post_id' => '456',
    ];
}
