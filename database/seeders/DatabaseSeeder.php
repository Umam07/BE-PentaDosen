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
            ['name' => 'Kiki Aimar Wicaksana', 'role' => 'dosen', 'scholar_id' => 'V4Qtn5YAAAAJ&hl  ', 'scopus_id' => '60103952600', 'fakultas' => 'Fakultas Ekonomi dan Bisnis', 'program_studi' => 'Akuntansi', 'password' => bcrypt('password'), 'total_kpi_points' => 0]
        );
        User::updateOrCreate(
            ['email' => 'danis@univ.edu'],
            ['name' => 'Rafi Danis', 'role' => 'dosen', 'scholar_id' => 'ghULz5YAAAAJ&hl', 'scopus_id' => '57205016667', 'fakultas' => 'Fakultas Hukum', 'program_studi' => 'Hukum', 'password' => bcrypt('password'), 'total_kpi_points' => 0]
        );
        User::updateOrCreate(
            ['email' => 'umam@univ.edu'],
            ['name' => "Umamz", 'role' => 'dosen', 'scholar_id' => 'tBjAaI0AAAAJ&hl', 'scopus_id' => '57220091394', 'fakultas' => 'Fakultas Psikologi', 'program_studi' => 'Psikologi', 'password' => bcrypt('password'), 'total_kpi_points' => 0]
        );
        User::updateOrCreate(
            ['email' => 'dosen_fkg@univ.edu'],
            ['name' => 'Dr. drg. H. Anton Rahardjo', 'role' => 'dosen', 'scholar_id' => 'FRgV4kAAAAAJ&hl', 'scopus_id' => '57205060934', 'fakultas' => 'Fakultas Kedokteran Gigi', 'program_studi' => 'Kedokteran Gigi', 'password' => bcrypt('password'), 'total_kpi_points' => 0]
        );

        // Administration & Leadership
        // Update existing old accounts if they exist to keep their database relations intact
        User::where('email', 'admin@univ.edu')->update(['email' => 'penelitian@univ.edu', 'name' => 'Admin Penelitian']);
        User::where('email', 'prodi@univ.edu')->update(['email' => 'fakultas@univ.edu', 'name' => 'Admin Fakultas']);

        User::updateOrCreate(
            ['email' => 'penelitian@univ.edu'],
            ['name' => 'Admin Penelitian', 'role' => 'admin lppm', 'password' => bcrypt('password')]
        );
        User::updateOrCreate(
            ['email' => 'fakultas@univ.edu'],
            ['name' => 'Admin Fakultas', 'role' => 'admin fakultas', 'fakultas' => 'Fakultas Teknologi Informasi', 'program_studi' => 'Teknik Informatika', 'password' => bcrypt('password')]
        );
        User::updateOrCreate(
            ['email' => 'superadmin@univ.edu'],
            ['name' => 'Super Admin', 'role' => 'super admin', 'password' => bcrypt('password')]
        );

        // Seeding default system settings
        \App\Models\SystemSetting::updateOrCreate(['key' => 'kpi_period_start'], ['value' => '2025-01-01']);
        \App\Models\SystemSetting::updateOrCreate(['key' => 'kpi_period_end'], ['value' => '2027-12-31']);
        \App\Models\SystemSetting::updateOrCreate(['key' => 'kpi_period_label'], ['value' => '2025-2027']);
        // Scholar data will be synced via real API instead of initialized with dummy data
    }
}
