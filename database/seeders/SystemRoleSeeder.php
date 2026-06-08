<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\StandardRoles;
use Illuminate\Database\Seeder;

class SystemRoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (StandardRoles::definitions() as $definition) {
            Role::query()->updateOrCreate(
                [
                    'business_id' => null,
                    'slug' => $definition['slug'],
                ],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'permissions' => $definition['permissions'],
                    'is_default' => $definition['is_default'],
                ],
            );
        }
    }
}
