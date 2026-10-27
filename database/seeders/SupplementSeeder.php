<?php

namespace Database\Seeders;

use App\Services\SupplementImporter;
use Illuminate\Database\Seeder;

class SupplementSeeder extends Seeder
{
    public function run(): void
    {
        app(SupplementImporter::class)->run();
    }
}
