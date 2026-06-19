<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            ['name' => 'admin', 'description' => 'Platform superuser'],
            ['name' => 'shop_owner', 'description' => 'Tailoring shop owner'],
            ['name' => 'branch_manager', 'description' => 'Physical store manager'],
            ['name' => 'staff', 'description' => 'Tailoring shop staff member'],
            ['name' => 'customer', 'description' => 'Customer'],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role['name']], $role);
        }
    }
}
