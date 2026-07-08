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

        // Seeding default system settings
        \App\Models\SystemSetting::updateOrCreate(['key' => 'kpi_period_start'], ['value' => '2025-01-01']);
        \App\Models\SystemSetting::updateOrCreate(['key' => 'kpi_period_end'], ['value' => '2027-12-31']);
        \App\Models\SystemSetting::updateOrCreate(['key' => 'kpi_period_label'], ['value' => '2025-2027']);
        // Scholar data will be synced via real API instead of initialized with dummy data
    }
}
