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

        // Super Admin Account (Maintained as required)
        User::updateOrCreate(
            ['email' => 'superadmin@univ.edu'],
            ['name' => 'Super Admin', 'role' => 'super admin', 'password' => bcrypt('P3nt4D0s3nSuper@2026!')]
        );

        // Lecturers (Dosen)
        User::updateOrCreate(
            ['email' => 'dosen1@univ.edu'],
            ['name' => 'Chandra Prasetyo Utomo, S.Kom, M.Kom.', 'role' => 'dosen', 'scholar_id' => '86JsILAAAAAJ', 'scopus_id' => '36656758200', 'fakultas' => 'Fakultas Teknologi Informasi', 'program_studi' => 'Teknik Informatika', 'password' => bcrypt('password'), 'total_kpi_points' => 0]
        );
        User::updateOrCreate(
            ['email' => 'dosen2@univ.edu'],
            ['name' => 'Kholis Ernawati, Dr. S.Si., M.Kes.', 'role' => 'dosen', 'scholar_id' => 'kvM1yXcAAAAJ', 'scopus_id' => '57210110753', 'fakultas' => 'Fakultas Kedokteran', 'program_studi' => 'Kedokteran', 'password' => bcrypt('password'), 'total_kpi_points' => 0]
        );
        User::updateOrCreate(
            ['email' => 'kiki@univ.edu'],
            ['name' => 'Kiki Aimar Wicaksana', 'role' => 'dosen', 'scholar_id' => '', 'scopus_id' => '', 'fakultas' => 'Fakultas Ekonomi dan Bisnis', 'program_studi' => 'Akuntansi', 'password' => bcrypt('password'), 'total_kpi_points' => 0]
        );
        User::updateOrCreate(
            ['email' => 'danis@univ.edu'],
            ['name' => 'Rafi Danis', 'role' => 'dosen', 'scholar_id' => 'ghULz5YAAAAJ', 'scopus_id' => '57205016667', 'fakultas' => 'Fakultas Hukum', 'program_studi' => 'Hukum', 'password' => bcrypt('password'), 'total_kpi_points' => 0]
        );
        User::updateOrCreate(
            ['email' => 'umam@univ.edu'],
            ['name' => "Umamz", 'role' => 'dosen', 'scholar_id' => 'tBjAaI0AAAAJ', 'scopus_id' => '57220091394', 'fakultas' => 'Fakultas Psikologi', 'program_studi' => 'Psikologi', 'password' => bcrypt('password'), 'total_kpi_points' => 0]
        );
        User::updateOrCreate(
            ['email' => 'dosen_fkg@univ.edu'],
            ['name' => 'Dr. drg. H. Anton Rahardjo', 'role' => 'dosen', 'scholar_id' => 'FRgV4kAAAAAJ', 'scopus_id' => '57205060934', 'fakultas' => 'Fakultas Kedokteran Gigi', 'program_studi' => 'Kedokteran Gigi', 'password' => bcrypt('password'), 'total_kpi_points' => 0]
        );

        // Administration & Leadership
        // Update existing old accounts if they exist to keep their database relations intact
        User::where('email', 'admin@univ.edu')->update(['email' => 'penelitian@univ.edu', 'name' => 'Admin Penelitian']);
        User::where('email', 'prodi@univ.edu')->update(['email' => 'fakultas@univ.edu', 'name' => 'Admin Fakultas']);

        User::updateOrCreate(
            ['email' => 'penelitian@univ.edu'],
            ['name' => 'Admin Penelitian', 'role' => 'admin penelitian', 'password' => bcrypt('password')]
        );
        User::updateOrCreate(
            ['email' => 'fakultas@univ.edu'],
            ['name' => 'Admin Fakultas', 'role' => 'admin fakultas', 'fakultas' => 'Fakultas Teknologi Informasi', 'program_studi' => 'Teknik Informatika', 'password' => bcrypt('password')]
        );

        // Seeding default system settings
        \App\Models\SystemSetting::updateOrCreate(['key' => 'kpi_period_start'], ['value' => '2026-01-01']);
        \App\Models\SystemSetting::updateOrCreate(['key' => 'kpi_period_end'], ['value' => '2026-12-31']);
        \App\Models\SystemSetting::updateOrCreate(['key' => 'kpi_period_label'], ['value' => '2026']);
        // Scholar data will be synced via real API instead of initialized with dummy data
    }
}
