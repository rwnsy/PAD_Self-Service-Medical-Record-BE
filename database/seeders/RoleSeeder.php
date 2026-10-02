<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $timestamp = now();

        Role::query()->upsert([
            ['role_name' => 'USER', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['role_name' => 'ADMIN', 'created_at' => $timestamp, 'updated_at' => $timestamp],
        ], ['role_name'], ['updated_at']);

        $userRoleId = Role::query()->where('role_name', 'USER')->value('role_id');

        User::query()->whereNull('role_id')->update(['role_id' => $userRoleId]);
    }
}
