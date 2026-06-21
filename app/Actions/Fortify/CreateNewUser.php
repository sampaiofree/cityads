<?php

namespace App\Actions\Fortify;

use App\Exceptions\PortalJeUnavailableException;
use App\Exceptions\PortalJeUserNotFoundException;
use App\Models\User;
use App\Services\PortalJe\PortalJeUserClient;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(
        private readonly PortalJeUserClient $portalJeUserClient,
    ) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $validated = Validator::make($input, [
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        try {
            $portalUser = $this->portalJeUserClient->findByEmail($validated['email']);
        } catch (PortalJeUserNotFoundException) {
            throw ValidationException::withMessages([
                'email' => 'Email não encontrado no Portal JE.',
            ]);
        } catch (PortalJeUnavailableException) {
            throw ValidationException::withMessages([
                'email' => 'Não foi possível validar o email no Portal JE. Tente novamente mais tarde.',
            ]);
        }

        Validator::make($portalUser, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'dominio' => ['required', 'string', 'max:255'],
        ])->validate();

        return User::create([
            'name' => $portalUser['name'],
            'email' => $portalUser['email'],
            'dominio' => $portalUser['dominio'],
            'password' => $validated['password'],
        ]);
    }
}
