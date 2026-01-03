<?php

declare(strict_types=1);

namespace App\Command\Calendar;

final class DealResolver
{
    private array $cache = [];
    private bool $warmed = false;

    public function __construct(
        private readonly NotionClient $notion,
        private readonly string $dealsDbId,
        private readonly string $personRelationProperty,
        private readonly string $startDateProperty
    ) {}

    public function warmCache(): void
    {
        if ($this->warmed) {
            return;
        }
        if ($this->dealsDbId === '') {
            $this->warmed = true;
            return;
        }

        $cursor = null;
        do {
            $payload = [
                'page_size' => 100,
                'sorts' => [
                    ['property' => $this->startDateProperty, 'direction' => 'descending'],
                ],
            ];
            if ($cursor) {
                $payload['start_cursor'] = $cursor;
            }

            $resp = $this->notion->queryDatabase($this->dealsDbId, $payload);
            foreach ($resp['results'] ?? [] as $page) {
                $dealId = $page['id'] ?? null;
                if (!$dealId) {
                    continue;
                }
                foreach ($this->extractRelationIds($page, $this->personRelationProperty) as $personId) {
                    if (!array_key_exists($personId, $this->cache)) {
                        $this->cache[$personId] = $dealId;
                    }
                }
            }

            $cursor = $resp['next_cursor'] ?? null;
            $hasMore = (bool)($resp['has_more'] ?? false);
        } while ($hasMore);

        $this->warmed = true;
    }

    public function resolveActiveDealIdForPerson(string $personPageId): ?string
    {
        if (array_key_exists($personPageId, $this->cache)) {
            return $this->cache[$personPageId];
        }

        if ($this->warmed) {
            return null;
        }

        if ($this->dealsDbId === '') {
            $this->cache[$personPageId] = null;
            return null;
        }

        $resp = $this->notion->queryDatabase($this->dealsDbId, [
            'page_size' => 20,
            'filter' => [
                'and' => [
                    [
                        'property' => $this->personRelationProperty,
                        'relation' => ['contains' => $personPageId],
                    ],
                ],
            ],
            'sorts' => [
                ['property' => $this->startDateProperty, 'direction' => 'descending'],
            ],
        ]);

        $dealId = $resp['results'][0]['id'] ?? null;
        $this->cache[$personPageId] = $dealId;
        return $dealId;
    }

    private function extractRelationIds(array $page, string $prop): array
    {
        $relation = $page['properties'][$prop]['relation'] ?? null;
        if (!is_array($relation)) {
            return [];
        }
        $ids = [];
        foreach ($relation as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = $item['id'] ?? null;
            if ($id) {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}
