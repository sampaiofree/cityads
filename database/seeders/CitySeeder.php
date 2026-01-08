<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CitySeeder extends Seeder
{
    public function run(): void
    {
        $request = Http::timeout(30)->retry(3, 500);

        if (app()->environment('local')) {
            $request = $request->withoutVerifying();
        }

        $response = $request->get('https://servicodados.ibge.gov.br/api/v1/localidades/municipios');

        if (! $response->ok()) {
            throw new RuntimeException('Failed to fetch cities from IBGE.');
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Unexpected IBGE response.');
        }

        City::query()->delete();

        $payload = [];
        foreach ($data as $row) {
            $name = $row['nome'] ?? null;
            $stateName = data_get($row, 'microrregiao.mesorregiao.UF.nome');
            $stateCode = data_get($row, 'microrregiao.mesorregiao.UF.sigla');

            if (! $name || ! $stateName) {
                continue;
            }

            $payload[] = [
                'name' => $name,
                'state' => $stateName,
                'state_code' => $stateCode,
            ];

            if (count($payload) >= 500) {
                City::query()->insert($payload);
                $payload = [];
            }
        }

        if ($payload) {
            City::query()->insert($payload);
        }
    }
}
