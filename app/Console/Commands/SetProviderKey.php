<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Stores a provider API key in .env without it ever appearing on screen, in shell history or in chat.
 */
class SetProviderKey extends Command
{
    protected $signature = 'docai:set-key {provider : openai or anthropic} {--env-file= : Path to the env file (defaults to .env)}';

    protected $description = 'Securely save an OpenAI or Anthropic API key to .env and switch extraction to that provider.';

    private const MAP = [
        'openai' => ['DOCAI_OPENAI_API_KEY', 'OpenAI'],
        'anthropic' => ['DOCAI_ANTHROPIC_API_KEY', 'Anthropic'],
    ];

    public function handle(): int
    {
        $provider = strtolower((string) $this->argument('provider'));
        if (! isset(self::MAP[$provider])) {
            $this->error('Provider must be "openai" or "anthropic".');

            return self::FAILURE;
        }
        [$variable, $label] = self::MAP[$provider];

        $file = $this->option('env-file') ?: base_path('.env');
        if (! is_file($file) || ! is_writable($file)) {
            $this->error("Cannot write to {$file}. Copy .env.example to .env first.");

            return self::FAILURE;
        }

        $key = trim((string) $this->secret("Paste your {$label} API key (input is hidden)"));
        if ($key === '' || preg_match('/\s/', $key) || strlen($key) < 20 || strlen($key) > 300) {
            $this->error('That does not look like an API key (empty, contains spaces, or the wrong length). Nothing was saved.');

            return self::FAILURE;
        }
        if ($provider === 'openai' && ! str_starts_with($key, 'sk-')) {
            $this->warn('OpenAI keys normally start with "sk-". Saving anyway; run docai:check to verify.');
        }

        $contents = (string) file_get_contents($file);
        $contents = $this->set($contents, $variable, $key);
        $contents = $this->set($contents, 'DOCAI_PROVIDER', $provider);
        file_put_contents($file, $contents);

        $this->info("{$label} key saved to ".basename($file)." and DOCAI_PROVIDER set to {$provider}. The key was not displayed.");
        $this->line('Next: <comment>php artisan config:clear</comment> then <comment>php artisan docai:check</comment> to test it.');
        $this->newLine();
        $this->warn('Never paste an API key into chat, tickets or screenshots. If one was shared, revoke it and create a new one.');

        return self::SUCCESS;
    }

    private function set(string $contents, string $name, string $value): string
    {
        $line = $name.'='.$value;
        $pattern = '/^'.preg_quote($name, '/').'=.*$/m';

        if (preg_match($pattern, $contents)) {
            return preg_replace_callback($pattern, fn () => $line, $contents, 1) ?? $contents;
        }

        return rtrim($contents, "\r\n").PHP_EOL.$line.PHP_EOL;
    }
}
