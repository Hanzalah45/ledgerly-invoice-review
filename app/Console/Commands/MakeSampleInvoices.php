<?php

namespace App\Console\Commands;

use App\Services\Samples\SampleInvoices;
use Illuminate\Console\Command;

class MakeSampleInvoices extends Command
{
    protected $signature = 'docai:make-samples {--path= : Output directory (defaults to storage/app/samples)}';

    protected $description = 'Generate synthetic sample invoice PDFs you can upload to try the application.';

    public function handle(): int
    {
        $directory = $this->option('path') ?: storage_path('app/samples');
        $paths = SampleInvoices::writeAll($directory);

        $this->info(count($paths).' sample invoices written to '.$directory);
        foreach ($paths as $path) {
            $this->line('  '.basename($path));
        }

        return self::SUCCESS;
    }
}
