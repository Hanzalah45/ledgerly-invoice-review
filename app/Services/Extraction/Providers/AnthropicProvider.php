<?php

namespace App\Services\Extraction\Providers;

class AnthropicProvider extends AbstractLlmProvider
{
    public function name(): string
    {
        return 'anthropic';
    }

    protected function config(): array
    {
        return config('docai.providers.anthropic');
    }

    protected function buildRequest(string $system, string $user): array
    {
        $config = $this->config();

        return [
            $config['endpoint'],
            [
                'x-api-key' => $config['key'],
                'anthropic-version' => $config['version'],
            ],
            [
                'model' => $config['model'],
                'max_tokens' => 4096,
                'temperature' => 0,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $user],
                ],
            ],
        ];
    }

    protected function buildFileRequest(string $system, string $user, string $pdfBase64): array
    {
        [$url, $headers, $body] = $this->buildRequest($system, $user);

        $body['messages'][0]['content'] = [
            ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $pdfBase64]],
            ['type' => 'text', 'text' => $user],
        ];

        return [$url, $headers, $body];
    }

    protected function readContent(array $json): ?string
    {
        foreach ($json['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                return $block['text'] ?? null;
            }
        }

        return null;
    }
}
