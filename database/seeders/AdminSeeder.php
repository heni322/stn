<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Check if the admin role exists, if not, create it
        $role = Role::findByName('Admin', 'api');

        // Create the user and assign the admin role
        $user = User::create([
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'), // Make sure to hash the password
        ]);

        // Assign the 'admin' role to the user
        $user->assignRole($role);
    }
}
