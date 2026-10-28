<?php

namespace App\Services\Extraction\Providers;

class OpenAiProvider extends AbstractLlmProvider
{
    public function name(): string
    {
        return 'openai';
    }

    protected function config(): array
    {
        return config('docai.providers.openai');
    }

    protected function buildRequest(string $system, string $user): array
    {
        $config = $this->config();

        return [
            $config['endpoint'],
            ['Authorization' => 'Bearer '.$config['key']],
            [
                'model' => $config['model'],
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ],
        ];
    }

    protected function buildFileRequest(string $system, string $user, string $pdfBase64): array
    {
        [$url, $headers, $body] = $this->buildRequest($system, $user);

        $body['messages'][1]['content'] = [
            ['type' => 'text', 'text' => $user],
            ['type' => 'file', 'file' => ['filename' => 'invoice.pdf', 'file_data' => 'data:application/pdf;base64,'.$pdfBase64]],
        ];

        return [$url, $headers, $body];
    }

    protected function readContent(array $json): ?string
    {
        return $json['choices'][0]['message']['content'] ?? null;
    }
}
