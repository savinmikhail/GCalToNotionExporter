<?php

declare(strict_types=1);

namespace App\Command\Calendar;

final class PeopleResolver
{
    private array $cache = [];
    private bool $warmed = false;

    public function __construct(
        private readonly NotionClient $notion,
        private readonly string $peopleDbId,
        private readonly string $tgProperty
    ) {}

    public function warmCache(): void
    {
        if ($this->warmed) {
            return;
        }

        $cursor = null;
        do {
            $payload = ['page_size' => 100];
            if ($cursor) {
                $payload['start_cursor'] = $cursor;
            }

            $resp = $this->notion->queryDatabase($this->peopleDbId, $payload);
            foreach ($resp['results'] ?? [] as $page) {
                $pageId = $page['id'] ?? null;
                if (!$pageId) {
                    continue;
                }
                foreach ($this->extractTgHandlesFromPage($page) as $handle) {
                    $this->cache[$handle] = $this->cache[$handle] ?? $pageId;
                }
            }

            $cursor = $resp['next_cursor'] ?? null;
            $hasMore = (bool)($resp['has_more'] ?? false);
        } while ($hasMore);

        $this->warmed = true;
    }

    public function resolvePersonIdByTg(string $tgNorm): ?string
    {
        if (array_key_exists($tgNorm, $this->cache)) {
            return $this->cache[$tgNorm];
        }

        $variants = array_values(array_unique([
            $tgNorm,
            '@' . $tgNorm,
            strtoupper($tgNorm),
            '@' . strtoupper($tgNorm),
        ]));

        foreach ($variants as $variant) {
            $resp = $this->notion->queryDatabase($this->peopleDbId, [
                'page_size' => 5,
                'filter' => [
                    'property' => $this->tgProperty,
                    'rich_text' => ['contains' => $variant],
                ],
            ]);
            if (!empty($resp['results'][0]['id'])) {
                $id = $resp['results'][0]['id'];
                $this->cache[$tgNorm] = $id;
                return $id;
            }
        }
        $this->cache[$tgNorm] = null;
        return null;
    }

    private function extractTgHandlesFromPage(array $page): array
    {
        $prop = $page['properties'][$this->tgProperty] ?? null;
        if (!is_array($prop)) {
            return [];
        }

        $richText = $prop['rich_text'] ?? null;
        if (!is_array($richText)) {
            return [];
        }

        $text = '';
        foreach ($richText as $item) {
            if (!is_array($item)) {
                continue;
            }
            $text .= $item['plain_text'] ?? '';
        }
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        preg_match_all('/@([a-zA-Z0-9_]{3,})/', $text, $m);
        $handles = [];
        if (!empty($m[1])) {
            foreach ($m[1] as $candidate) {
                $normalized = $this->normalizeTg($candidate);
                if ($normalized) {
                    $handles[] = $normalized;
                }
            }
        } else {
            foreach (preg_split('/[\s,;]+/', $text) as $candidate) {
                if ($candidate === '') {
                    continue;
                }
                $normalized = $this->normalizeTg($candidate);
                if ($normalized) {
                    $handles[] = $normalized;
                }
            }
        }

        return array_values(array_unique($handles));
    }

    private function normalizeTg(string $tg): ?string
    {
        $tg = trim($tg);
        if ($tg === '') {
            return null;
        }
        $tg = preg_replace('/^@+/', '', $tg) ?? $tg;
        $tg = strtolower($tg);
        if (!preg_match('/^[a-z0-9_]{3,}$/', $tg)) {
            return null;
        }
        return $tg;
    }
}
