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

        // HKI Specific Categories
        \App\Models\PointWeight::updateOrCreate(['category' => 'HKI Paten'], ['weight_value' => 40]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'HKI Paten Sederhana'], ['weight_value' => 28]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'HKI Merk'], ['weight_value' => 12]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'HKI Merek'], ['weight_value' => 12]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'HKI Hak Cipta'], ['weight_value' => 5]);

        // Buku Categories
        \App\Models\PointWeight::updateOrCreate(['category' => 'Buku Referensi'], ['weight_value' => 40]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'Buku Ajar'], ['weight_value' => 20]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'Buku Monograf'], ['weight_value' => 20]);

        // SINTA Scopus Point Weights (Single Author, First Author, Member Author)
        \App\Models\PointWeight::updateOrCreate(['category' => 'Scopus Article (Single Author)'], ['weight_value' => 40]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'Scopus Article Q1 (First Author)'], ['weight_value' => 24]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'Scopus Article Q2 (First Author)'], ['weight_value' => 22]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'Scopus Article Q3 (First Author)'], ['weight_value' => 20]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'Scopus Article Q4 (First Author)'], ['weight_value' => 18]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'Scopus Article Hyperauthor (First Author)'], ['weight_value' => 24]);

        \App\Models\PointWeight::updateOrCreate(['category' => 'Scopus Article Q1 (Member Author)'], ['weight_value' => 16]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'Scopus Article Q2 (Member Author)'], ['weight_value' => 14]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'Scopus Article Q3 (Member Author)'], ['weight_value' => 12]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'Scopus Article Q4 (Member Author)'], ['weight_value' => 10]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'Scopus Article Hyperauthor (Member Author)'], ['weight_value' => 1]);

        \App\Models\PointWeight::updateOrCreate(['category' => 'Scopus Non Article (Single Author)'], ['weight_value' => 30]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'Scopus Non Article (First Author)'], ['weight_value' => 18]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'Scopus Non Article (Member Author)'], ['weight_value' => 12]);

        \App\Models\PointWeight::updateOrCreate(['category' => 'Scopus Citation Per Author Number'], ['weight_value' => 1]);
        \App\Models\PointWeight::updateOrCreate(['category' => 'Scopus Document Tersitasi'], ['weight_value' => 5]);

        // Seed Sample SJR Journals for auto-lookup
        $sampleJournals = [
            ['issn' => '1664-462X', 'title' => 'Frontiers in Plant Science', 'quartile' => 'Q1'],
            ['issn' => '1664462X', 'title' => 'Frontiers in Plant Science', 'quartile' => 'Q1'],
            ['issn' => '1438-8871', 'title' => 'Journal of Medical Internet Research', 'quartile' => 'Q1'],
            ['issn' => '14388871', 'title' => 'Journal of Medical Internet Research', 'quartile' => 'Q1'],
            ['issn' => '1367-4803', 'title' => 'Bioinformatics', 'quartile' => 'Q1'],
            ['issn' => '2169-3536', 'title' => 'IEEE Access', 'quartile' => 'Q2'],
            ['issn' => '2073-8994', 'title' => 'Symmetry', 'quartile' => 'Q2'],
            ['issn' => '1742-6596', 'title' => 'Journal of Physics: Conference Series', 'quartile' => 'Q4'],
            ['issn' => '2088-8708', 'title' => 'International Journal of Electrical and Computer Engineering', 'quartile' => 'Q2'],
            ['issn' => '1693-6930', 'title' => 'Telkomnika', 'quartile' => 'Q3'],
        ];

        foreach ($sampleJournals as $sj) {
            \Illuminate\Support\Facades\DB::table('sjr_journals')->updateOrInsert(
                ['issn' => $sj['issn']],
                ['title' => $sj['title'], 'quartile' => $sj['quartile'], 'created_at' => now(), 'updated_at' => now()]
            );
        }

        // Main Admin Account (Admin Penelitian)
        User::updateOrCreate(
            ['email' => 'superadmin@univ.edu'],
            ['name' => 'Admin Penelitian (Master)', 'role' => 'admin penelitian', 'password' => bcrypt('P3nt4D0s3nSuper@2026!')]
        );

        // 10 Dummy Lecturers (Dosen) - initialized with Name, Email, Password, and Role only.
        // Other attributes (scholar_id, scopus_id, fakultas, program_studi) will be populated automatically via SINTA Scraper API.
        $dummyDosen = [
            ['name' => 'Chandra Prasetyo Utomo', 'email' => 'chandra.prasetyo@univ.edu'],
            ['name' => 'Nurul Huda', 'email' => 'nurul.huda@univ.edu'],
            ['name' => 'Kholis Ernawati', 'email' => 'kholis.ernawati@univ.edu'],
            ['name' => 'Endang Purwaningsih', 'email' => 'endang.purwaningsih@univ.edu'],
            ['name' => 'nurmaya', 'email' => 'nurmaya@univ.edu'],
            ['name' => 'muhammad fathurrachman', 'email' => 'fathurrachman@univ.edu'],
            ['name' => 'Paramaresthi Windriyani', 'email' => 'paramaresthi.windriyani@univ.edu'],
            ['name' => 'Herika Hayurani', 'email' => 'herika.hayurani@univ.edu'],
            ['name' => 'sari zakiah akmal', 'email' => 'sari.zakiah@univ.edu'],
            ['name' => 'wening sari', 'email' => 'wening.sari@univ.edu'],
        ];

        foreach ($dummyDosen as $dosenData) {
            User::updateOrCreate(
                ['email' => $dosenData['email']],
                [
                    'name' => $dosenData['name'],
                    'role' => 'dosen',
                    'scholar_id' => null,
                    'scopus_id' => null,
                    'fakultas' => null,
                    'program_studi' => null,
                    'avatar' => null,
                    'password' => bcrypt('password'),
                    'total_kpi_points' => 0
                ]
            );
        }

        // Administration & Leadership
        User::updateOrCreate(
            ['email' => 'penelitian@univ.edu'],
            ['name' => 'Admin Penelitian', 'role' => 'admin penelitian', 'password' => bcrypt('password')]
        );
        User::updateOrCreate(
            ['email' => 'fakultas@univ.edu'],
            ['name' => 'Admin Fakultas Teknologi Informasi', 'role' => 'admin fakultas', 'fakultas' => 'Fakultas Teknologi Informasi', 'program_studi' => 'Teknik Informatika', 'password' => bcrypt('password')]
        );
        User::updateOrCreate(
            ['email' => 'fakultas.feb@univ.edu'],
            ['name' => 'Admin Fakultas Ekonomi dan Bisnis', 'role' => 'admin fakultas', 'fakultas' => 'Fakultas Ekonomi dan Bisnis', 'program_studi' => 'Manajemen', 'password' => bcrypt('password')]
        );
        User::updateOrCreate(
            ['email' => 'fakultas.fk@univ.edu'],
            ['name' => 'Admin Fakultas Kedokteran', 'role' => 'admin fakultas', 'fakultas' => 'Fakultas Kedokteran', 'program_studi' => 'Kedokteran', 'password' => bcrypt('password')]
        );
        User::updateOrCreate(
            ['email' => 'fakultas.fh@univ.edu'],
            ['name' => 'Admin Fakultas Hukum', 'role' => 'admin fakultas', 'fakultas' => 'Fakultas Hukum', 'program_studi' => 'Hukum', 'password' => bcrypt('password')]
        );

        // Seeding default system settings
        \App\Models\SystemSetting::updateOrCreate(['key' => 'kpi_period_start'], ['value' => '2026-01-01']);
        \App\Models\SystemSetting::updateOrCreate(['key' => 'kpi_period_end'], ['value' => '2026-12-31']);
        \App\Models\SystemSetting::updateOrCreate(['key' => 'kpi_period_label'], ['value' => '2026']);
        // Scholar data will be synced via real API instead of initialized with dummy data
    }
}
