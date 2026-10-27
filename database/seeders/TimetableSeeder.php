<?php

namespace Database\Seeders;

use App\Services\TimetableImporter;
use Illuminate\Database\Seeder;

class TimetableSeeder extends Seeder
{
    public function run(): void
    {
        app(TimetableImporter::class)->run();
    }
}
