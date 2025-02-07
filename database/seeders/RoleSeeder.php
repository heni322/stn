<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Define roles
        $roles = [
            'Client',
            'Provider',
            'Admin',
        ];

        // Create roles with 'guard_name' set to 'api' if they don't exist
        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['name' => $role],
                ['guard_name' => 'api']
            );
        }
        // Output message
        $this->command->info('Roles created successfully!');
    }
}
