<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AccountingModuleSeeder extends Seeder
{
    public function run(): void
    {
        $count = 0;
        foreach (User::cursor() as $user) {
            $mods = $user->modules ?? [];
            if (is_array($mods) && !in_array('accounting', $mods)) {
                $mods[] = 'accounting';
                $user->modules = $mods;
                $user->save();
                $count++;
            }
        }

        $this->command?->info("Added accounting module to {$count} users.");
    }
}
