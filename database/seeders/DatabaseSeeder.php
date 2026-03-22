<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Initial point weights
        \App\Models\PointWeight::updateOrCreate(['category' => 'Jurnal Internasional'], ['weight_value' => 40]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'Jurnal Nasional'], ['weight_value' => 20]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'HKI'], ['weight_value' => 20]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'Proposal'], ['weight_value' => 10]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'Laporan'], ['weight_value' => 10]);

        // Default users
        $u1 = User::updateOrCreate(
            ['email' => 'dosen1@univ.edu'],
            ['id' => 1, 'name' => 'Dr. Budi Santoso', 'role' => 'dosen', 'scholar_id' => null, 'fakultas' => 'Fakultas Teknologi Informasi', 'program_studi' => 'Teknik Informatika', 'password' => bcrypt('password'), 'total_kpi_points' => 0]
        );
        $u2 = User::updateOrCreate(
            ['email' => 'dosen2@univ.edu'],
            ['id' => 2, 'name' => 'Prof. Siti Aminah', 'role' => 'dosen', 'scholar_id' => null, 'fakultas' => 'Fakultas Teknologi Informasi', 'program_studi' => 'Perpustakaan dan Sains Informasi', 'password' => bcrypt('password'), 'total_kpi_points' => 0]
        );
        User::updateOrCreate(
            ['email' => 'admin@univ.edu'],
            ['id' => 3, 'name' => 'Admin Pusat', 'role' => 'admin', 'password' => bcrypt('password')]
        );
        User::updateOrCreate(
            ['email' => 'rektor@univ.edu'],
            ['id' => 4, 'name' => 'Rektor', 'role' => 'pimpinan', 'password' => bcrypt('password')]
        );

        // Scholar data will be synced via real API instead of initialized with dummy data
    }
}
