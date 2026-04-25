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
            ['name' => 'Chandra Prasetyo Utomo, S.Kom, M.Kom.', 'role' => 'dosen', 'scholar_id' => '86JsILAAAAAJ&hl', 'scopus_id' => '36656758200', 'fakultas' => 'Fakultas Teknologi Informasi', 'program_studi' => 'Teknik Informatika', 'password' => bcrypt('password'), 'total_kpi_points' => 0]
        );
        User::updateOrCreate(
            ['email' => 'dosen2@univ.edu'],
            ['name' => 'Kholis Ernawati, Dr. S.Si., M.Kes.', 'role' => 'dosen', 'scholar_id' => 'kvM1yXcAAAAJ&hl', 'scopus_id' => '57210110753', 'fakultas' => 'Fakultas Kedokteran', 'program_studi' => 'Kedokteran', 'password' => bcrypt('password'), 'total_kpi_points' => 0]
        );
        User::updateOrCreate(
            ['email' => 'kiki@univ.edu'],
            ['name' => 'Kiki Aimar Wicaksana', 'role' => 'dosen', 'scholar_id' => 'V4Qtn5YAAAAJ&hl  ', 'scopus_id' => '60103952600', 'fakultas' => 'Fakultas Ekonomi dan Bisnis', 'program_studi' => 'Akuntansi', 'password' => bcrypt('password')]
        );
        User::updateOrCreate(
            ['email' => 'danis@univ.edu'],
            ['name' => 'Rafi Danis', 'role' => 'dosen', 'scholar_id' => 'ghULz5YAAAAJ&hl', 'scopus_id' => '57205016667', 'fakultas' => 'Fakultas Hukum', 'program_studi' => 'Hukum', 'password' => bcrypt('password')]
        );
        User::updateOrCreate(
            ['email' => 'umam@univ.edu'],
            ['name' => "Umamz", 'role' => 'dosen', 'scholar_id' => 'tBjAaI0AAAAJ&hl', 'scopus_id' => '57220091394', 'fakultas' => 'Fakultas Psikologi', 'program_studi' => 'Psikologi', 'password' => bcrypt('password')]
        );

        // Administration & Leadership
        User::updateOrCreate(
            ['email' => 'admin@univ.edu'],
            ['name' => 'Admin LPPM', 'role' => 'admin lppm', 'password' => bcrypt('password')]
        );
        User::updateOrCreate(
            ['email' => 'prodi@univ.edu'],
            ['name' => 'Admin Prodi TI', 'role' => 'admin prodi', 'fakultas' => 'Fakultas Teknologi Informasi', 'program_studi' => 'Teknik Informatika', 'password' => bcrypt('password')]
        );
        User::updateOrCreate(
            ['email' => 'rektor@univ.edu'],
            ['name' => 'Rektor', 'role' => 'pimpinan', 'password' => bcrypt('password')]
        );

        // Scholar data will be synced via real API instead of initialized with dummy data
    }
}
