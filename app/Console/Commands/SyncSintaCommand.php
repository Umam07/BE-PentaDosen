<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Services\SintaSyncService;

class SyncSintaCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sinta:sync 
                            {--user= : Sync specific user ID} 
                            {--name= : Check matching for specific name} 
                            {--force : Force overwrite existing IDs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync lecturer Scopus ID, Google Scholar ID, Fakultas, and Prodi from SINTA Scraper API';

    /**
     * Execute the console command.
     */
    public function handle(SintaSyncService $sintaService)
    {
        $nameCheck = $this->option('name');
        if ($nameCheck) {
            $this->info("Checking SINTA match for: '{$nameCheck}'...");
            $match = $sintaService->findDosenByName($nameCheck);
            if ($match) {
                $d = $match['item'];
                $this->info("✓ MATCH FOUND (Score: {$match['score']}%, Type: {$match['type']})");
                $this->table(
                    ['Field', 'Value'],
                    [
                        ['SINTA ID', $d['id'] ?? '-'],
                        ['SINTA Name', $d['name'] ?? '-'],
                        ['Prodi', $d['prodi'] ?? '-'],
                        ['Fakultas', $d['faculty'] ?? '-'],
                        ['Google Scholar ID', $d['googleScholarId'] ?? '-'],
                        ['Scopus Author ID', $d['scopusAuthorId'] ?? '-'],
                    ]
                );
            } else {
                $this->error("✗ No match found for '{$nameCheck}' in SINTA API.");
            }
            return 0;
        }

        $userId = $this->option('user');
        $force = $this->option('force');

        if ($userId) {
            $user = User::find($userId);
            if (!$user) {
                $this->error("User ID {$userId} not found.");
                return 1;
            }

            $this->info("Syncing SINTA data for: {$user->name} (ID: {$user->id})...");
            $res = $sintaService->syncUser($user, $force);

            if ($res['success']) {
                $this->info("✓ {$res['message']}");
                $this->line("  - Scholar ID : " . ($user->scholar_id ?? '(empty)'));
                $this->line("  - Scopus ID  : " . ($user->scopus_id ?? '(empty)'));
                $this->line("  - Fakultas   : " . ($user->fakultas ?? '(empty)'));
                $this->line("  - Prodi      : " . ($user->program_studi ?? '(empty)'));
            } else {
                $this->warn("✗ {$res['message']}");
            }
            return 0;
        }

        $this->info("Fetching data from SINTA Scraper API...");
        $result = $sintaService->syncAllUsers($force);

        $this->info("\n=== SINTA Sync Summary ===");
        $this->info("Total Dosen : {$result['total']}");
        $this->info("Synced      : {$result['synced']}");
        $this->warn("Not Found   : {$result['not_found']}");

        $rows = [];
        foreach ($result['details'] as $item) {
            $rows[] = [
                $item['id'],
                $item['name'],
                $item['status'] === 'synced' ? '✓ Synced' : '✗ Not Found',
                $item['scholar_id'] ?? '-',
                $item['scopus_id'] ?? '-',
                $item['fakultas'] ?? '-',
            ];
        }

        $this->table(['ID', 'Nama', 'Status', 'Scholar ID', 'Scopus ID', 'Fakultas'], $rows);

        return 0;
    }
}
