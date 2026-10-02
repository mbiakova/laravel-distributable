<?php

declare(strict_types=1);

namespace Apps\Iam\Database\Seeders;

use Apps\Iam\Models\User;
use Illuminate\Database\Seeder;

final class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create(['name' => 'admin']);
    }
}
