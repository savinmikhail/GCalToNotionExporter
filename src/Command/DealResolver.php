<?php

declare(strict_types=1);

namespace App\Command;

final class DealResolver
{
    private array $cache = [];

    public function __construct(
        private readonly NotionClient $notion,
        private readonly string $dealsDbId,
        private readonly string $personRelationProperty,
        private readonly string $stageProperty,
        private readonly string $startDateProperty
    ) {}

    public function resolveActiveDealIdForPerson(string $personPageId): ?string
    {
        if (array_key_exists($personPageId, $this->cache)) {
            return $this->cache[$personPageId];
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
                    [
                        'or' => [
                            ['property' => $this->stageProperty, 'select' => ['equals' => 'Started']],
                            ['property' => $this->stageProperty, 'select' => ['equals' => 'Active']],
                        ],
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
}
