<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

class DemoReset extends Command
{
    protected $signature = 'docai:demo-reset {--force : Run even when DOCAI_PUBLIC_DEMO is off (wipes the database)}';

    protected $description = 'Wipe and re-seed the public demo workspace (database and uploaded files).';

    public function handle(): int
    {
        if (! config('docai.public_demo') && ! $this->option('force')) {
            $this->error('Refusing to reset: DOCAI_PUBLIC_DEMO is off. Pass --force to wipe this database.');

            return self::FAILURE;
        }

        $disk = Storage::disk('local');
        foreach ($disk->directories() as $directory) {
            $disk->deleteDirectory($directory);
        }

        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        $this->info('Demo workspace reset.');

        return self::SUCCESS;
    }
}
