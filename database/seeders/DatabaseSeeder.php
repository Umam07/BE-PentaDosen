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

        // Lecturers (Dosen)
        User::updateOrCreate(
            ['email' => 'dosen1@univ.edu'],
            ['name' => 'Dr. Budi Santoso', 'role' => 'dosen', 'scholar_id' => null, 'fakultas' => 'Fakultas Teknologi Informasi', 'program_studi' => 'Teknik Informatika', 'password' => bcrypt('password'), 'total_kpi_points' => 0]
        );
        User::updateOrCreate(
            ['email' => 'dosen2@univ.edu'],
            ['name' => 'Prof. Siti Aminah', 'role' => 'dosen', 'scholar_id' => null, 'fakultas' => 'Fakultas Kedokteran', 'program_studi' => 'Kedokteran Umum', 'password' => bcrypt('password'), 'total_kpi_points' => 0]
        );
        User::updateOrCreate(
            ['email' => 'kiki@univ.edu'],
            ['name' => 'Kiki Aimar Wicaksana', 'role' => 'dosen', 'fakultas' => 'Fakultas Ekonomi dan Bisnis', 'program_studi' => 'Akuntansi', 'password' => bcrypt('password')]
        );
        User::updateOrCreate(
            ['email' => 'danis@univ.edu'],
            ['name' => 'Rafi Danis', 'role' => 'dosen', 'fakultas' => 'Fakultas Hukum', 'program_studi' => 'Ilmu Hukum', 'password' => bcrypt('password')]
        );
        User::updateOrCreate(
            ['email' => 'umam@univ.edu'],
            ['name' => "Muhammad Syafi'ul Umam", 'role' => 'dosen', 'fakultas' => 'Fakultas Ilmu Sosial dan Politik', 'program_studi' => 'Sosiologi', 'password' => bcrypt('password')]
        );

        // Administration & Leadership
        User::updateOrCreate(
            ['email' => 'admin@univ.edu'],
            ['name' => 'Admin LPPM', 'role' => 'admin', 'password' => bcrypt('password')]
        );
        User::updateOrCreate(
            ['email' => 'prodi@univ.edu'],
            ['name' => 'Admin Prodi TI', 'role' => 'prodi', 'program_studi' => 'Teknik Informatika', 'password' => bcrypt('password')]
        );
        User::updateOrCreate(
            ['email' => 'rektor@univ.edu'],
            ['name' => 'Rektor', 'role' => 'pimpinan', 'password' => bcrypt('password')]
        );

        // Scholar data will be synced via real API instead of initialized with dummy data
    }
}
