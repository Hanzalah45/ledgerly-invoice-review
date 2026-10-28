<?php

namespace App\Services\Extraction;

use App\Services\Extraction\Contracts\ExtractionProvider;
use App\Services\Extraction\Providers\AnthropicProvider;
use App\Services\Extraction\Providers\DemoRuleProvider;
use App\Services\Extraction\Providers\OpenAiProvider;

class ExtractionManager
{
    public function provider(?string $name = null): ExtractionProvider
    {
        return match ($name ?? config('docai.provider')) {
            'openai' => new OpenAiProvider,
            'anthropic' => new AnthropicProvider,
            default => new DemoRuleProvider,
        };
    }

    /**
     * Provider that will actually run. A live provider without a key falls back to the demo
     * parser so uploads keep working; the UI shows which one was used.
     */
    public function active(): ExtractionProvider
    {
        $provider = $this->provider();

        return $provider->isConfigured() ? $provider : new DemoRuleProvider;
    }

    /** @return array<int,array{name:string,label:string,configured:bool,model:?string,active:bool}> */
    public function overview(): array
    {
        $active = $this->active()->name();
        $rows = [];
        foreach ([
            'demo' => 'Demo parser (rules, not AI)',
            'openai' => 'OpenAI',
            'anthropic' => 'Anthropic',
        ] as $name => $label) {
            $provider = $this->provider($name);
            $rows[] = [
                'name' => $name,
                'label' => $label,
                'configured' => $provider->isConfigured(),
                'model' => $provider->model(),
                'active' => $name === $active,
            ];
        }

        return $rows;
    }

    public function isLive(): bool
    {
        return ! $this->active()->isDemo();
    }
}
