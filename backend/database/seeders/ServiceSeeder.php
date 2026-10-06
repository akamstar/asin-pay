<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    /**
     * Catalogue des actes. Le seeder est idempotent : il peut être relancé à chaque déploiement.
     */
    public function run(): void
    {
        $services = [
            ['code' => 'ACTE_NAISSANCE', 'title' => 'Acte de naissance', 'price' => 1000],
            ['code' => 'CASIER_JUDICIAIRE', 'title' => 'Casier judiciaire', 'price' => 1500],
            ['code' => 'CERTIFICAT_RESIDENCE', 'title' => 'Certificat de résidence', 'price' => 500],
        ];

        foreach ($services as $service) {
            Service::updateOrCreate(['code' => $service['code']], $service);
        }
    }
}
