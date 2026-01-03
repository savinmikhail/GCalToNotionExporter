<?php

declare(strict_types=1);

namespace App\Command;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

final class NotionClient
{
    private HttpClient $http;

    public function __construct(
        private readonly string $token,
        private readonly string $apiVersion,
        private readonly int $minDelayMs
    ) {
        $this->http = new HttpClient([
            'base_uri' => 'https://api.notion.com/v1/',
            'timeout' => 30,
        ]);
    }

    public function queryDatabase(string $dbId, array $payload): array
    {
        return $this->request('POST', "databases/{$dbId}/query", $payload);
    }

    public function createPage(array $payload): array
    {
        return $this->request('POST', 'pages', $payload);
    }

    public function updatePage(string $pageId, array $payload): array
    {
        return $this->request('PATCH', "pages/{$pageId}", $payload);
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->token,
            'Notion-Version' => $this->apiVersion,
            'Content-Type' => 'application/json',
        ];
    }

    private function request(string $method, string $path, array $jsonPayload): array
    {
        $this->sleepMs($this->minDelayMs);
        try {
            $res = $this->http->request($method, $path, [
                'headers' => $this->headers(),
                'json' => $jsonPayload,
            ]);
            $body = (string)$res->getBody();
            return $body !== '' ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : [];
        } catch (RequestException $e) {
            $code = $e->getResponse()?->getStatusCode();
            $body = $e->getResponse() ? (string)$e->getResponse()->getBody() : '';
            if ($code === 429) {
                $this->sleepMs(1200);
                $res = $this->http->request($method, $path, [
                    'headers' => $this->headers(),
                    'json' => $jsonPayload,
                ]);
                $body = (string)$res->getBody();
                return $body !== '' ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : [];
            }
            throw new RuntimeException("Notion API error {$code} on {$method} {$path}: {$body}", 0, $e);
        }
    }

    private function sleepMs(int $ms): void
    {
        usleep($ms * 1000);
    }
}
