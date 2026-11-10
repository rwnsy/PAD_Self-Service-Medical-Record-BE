<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneCalorieEstimatesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'calorie-estimates:prune {--hours=24 : Usia maksimal file dalam jam}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bersihkan file sementara foto estimasi kalori yang sudah kedaluwarsa dari storage privat';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = Carbon::now()->subHours($hours)->getTimestamp();
        $disk = Storage::disk('local');

        if (! $disk->exists('calorie-estimates')) {
            $this->info('Direktori calorie-estimates belum ada atau kosong.');

            return self::SUCCESS;
        }

        $files = $disk->files('calorie-estimates');
        $deletedCount = 0;

        foreach ($files as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deletedCount++;
            }
        }

        $this->info("Pembersihan selesai: {$deletedCount} file foto estimasi kalori berhasil dihapus.");

        return self::SUCCESS;
    }
}
