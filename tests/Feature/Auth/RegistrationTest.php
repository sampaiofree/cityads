<?php

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->withoutVite();
});

test('guests are directed to the Fortify login screen', function () {
    $this->get(route('home'))
        ->assertRedirect(route('login'));
});

test('login screen links to registration', function () {
    $response = $this->get(route('login'));

    $response->assertOk()
        ->assertSee(route('register'), escape: false);
});

test('registration screen can be rendered without a name field', function () {
    $response = $this->get(route('register'));

    $response->assertOk()
        ->assertDontSee('name="name"', escape: false)
        ->assertSee('name="email"', escape: false)
        ->assertSee('name="password"', escape: false);
});

test('new users register with the external domain returned by Portal JE', function () {
    Http::fake([
        'portalje.org/api/buscar_user/*' => Http::response([
            'id' => 200731,
            'name' => 'Bruno',
            'email' => 'bruno@example.com',
            'dominio' => 'ead1.portalje.org',
            'dominio_externo' => 'cliente.example.com',
        ]),
    ]);

    $response = $this->post(route('register.store'), registrationData());

    $response->assertSessionHasNoErrors()
        ->assertRedirect('/dashboard');

    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', [
        'name' => 'Bruno',
        'email' => 'bruno@example.com',
        'dominio' => 'cliente.example.com',
    ]);
});

test('registration falls back to the standard Portal JE domain', function () {
    Http::fake([
        'portalje.org/api/buscar_user/*' => Http::response([
            'name' => 'Bruno',
            'email' => 'bruno@example.com',
            'dominio' => 'ead1.portalje.org',
            'dominio_externo' => null,
        ]),
    ]);

    $this->post(route('register.store'), registrationData())
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('users', [
        'email' => 'bruno@example.com',
        'dominio' => 'ead1.portalje.org',
    ]);
});

test('registration uses the email returned by Portal JE', function () {
    Http::fake([
        'portalje.org/api/buscar_user/*' => Http::response([
            'name' => 'Bruno',
            'email' => 'canonical@example.com',
            'dominio' => 'ead1.portalje.org',
            'dominio_externo' => null,
        ]),
    ]);

    $this->post(route('register.store'), registrationData(email: 'alias@example.com'))
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs(
        User::query()->where('email', 'canonical@example.com')->firstOrFail()
    );
    $this->assertDatabaseMissing('users', ['email' => 'alias@example.com']);
});

test('registration rejects an email returned by Portal JE that already exists', function () {
    User::factory()->create(['email' => 'existing@example.com']);

    Http::fake([
        'portalje.org/api/buscar_user/*' => Http::response([
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'dominio' => 'ead1.portalje.org',
            'dominio_externo' => null,
        ]),
    ]);

    $this->post(route('register.store'), registrationData())
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    $this->assertDatabaseCount('users', 1);
});

test('registration rejects emails not found in Portal JE', function () {
    Http::fake([
        'portalje.org/api/buscar_user/*' => Http::response(status: 404),
    ]);

    $this->post(route('register.store'), registrationData())
        ->assertSessionHasErrors([
            'email' => 'Email não encontrado no Portal JE.',
        ]);

    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
});

test('registration rejects an unavailable Portal JE service', function () {
    Http::fake([
        'portalje.org/api/buscar_user/*' => Http::response(status: 500),
    ]);

    $this->post(route('register.store'), registrationData())
        ->assertSessionHasErrors([
            'email' => 'Não foi possível validar o email no Portal JE. Tente novamente mais tarde.',
        ]);

    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
});

test('registration rejects a failed connection to Portal JE', function () {
    Http::fake([
        'portalje.org/api/buscar_user/*' => Http::failedConnection(),
    ]);

    $this->post(route('register.store'), registrationData())
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
});

test('registration rejects an invalid Portal JE payload', function (array $payload) {
    Http::fake([
        'portalje.org/api/buscar_user/*' => Http::response($payload),
    ]);

    $this->post(route('register.store'), registrationData())
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
})->with([
    'missing name' => [[
        'email' => 'bruno@example.com',
        'dominio' => 'ead1.portalje.org',
    ]],
    'invalid returned email' => [[
        'name' => 'Bruno',
        'email' => 'not-an-email',
        'dominio' => 'ead1.portalje.org',
    ]],
    'missing both domains' => [[
        'name' => 'Bruno',
        'email' => 'bruno@example.com',
        'dominio' => null,
        'dominio_externo' => '',
    ]],
]);

test('registration rejects malformed JSON returned by Portal JE', function () {
    Http::fake([
        'portalje.org/api/buscar_user/*' => Http::response(
            '{invalid-json',
            headers: ['Content-Type' => 'application/json'],
        ),
    ]);

    $this->post(route('register.store'), registrationData())
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
});

test('registration validates local input before calling Portal JE', function (array $data) {
    Http::fake();

    $this->post(route('register.store'), $data)
        ->assertSessionHasErrors();

    Http::assertNothingSent();
    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
})->with([
    'invalid email' => [registrationData(email: 'invalid-email')],
    'missing password' => [[
        'email' => 'input@example.com',
        'password_confirmation' => 'password',
    ]],
    'unconfirmed password' => [[
        'email' => 'input@example.com',
        'password' => 'password',
        'password_confirmation' => 'different-password',
    ]],
]);

test('registration URL encodes the email sent to Portal JE', function () {
    config()->set('services.portalje.url', 'https://portalje.org/api');

    Http::fake([
        'portalje.org/api/buscar_user/*' => Http::response([
            'name' => 'Bruno',
            'email' => 'user+city@example.com',
            'dominio' => 'ead1.portalje.org',
            'dominio_externo' => null,
        ]),
    ]);

    $this->post(
        route('register.store'),
        registrationData(email: 'user+city@example.com')
    )->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request) => $request->url()
        === 'https://portalje.org/api/buscar_user/user%2Bcity%40example.com');
});

test('administrative users can still exist without a domain', function () {
    $user = User::factory()->create(['dominio' => null]);

    expect($user->dominio)->toBeNull();
});

function registrationData(string $email = 'input@example.com'): array
{
    return [
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ];
}
