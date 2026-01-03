<?php

declare(strict_types=1);

namespace App\Command\Calendar;

use DateTimeImmutable;
use Symfony\Component\Console\Output\OutputInterface;

final class TimeEntriesSync
{
    private array $existingByEventKey = [];
    private bool $prefetched = false;

    public function __construct(
        private readonly NotionClient $notion,
        private readonly string $timeDbId,
        private readonly array $props,
        private readonly OutputInterface $output
    ) {}

    public function primeExistingByRange(string $timeMinIso, string $timeMaxIso): void
    {
        $this->existingByEventKey = [];
        $this->prefetched = true;

        $cursor = null;
        do {
            $payload = [
                'page_size' => 100,
                'filter' => [
                    'and' => [
                        [
                            'property' => $this->prop('start'),
                            'date' => ['on_or_after' => $timeMinIso],
                        ],
                        [
                            'property' => $this->prop('start'),
                            'date' => ['on_or_before' => $timeMaxIso],
                        ],
                    ],
                ],
            ];
            if ($cursor) {
                $payload['start_cursor'] = $cursor;
            }

            $resp = $this->notion->queryDatabase($this->timeDbId, $payload);
            foreach ($resp['results'] ?? [] as $page) {
                $eventKey = $this->extractRichText($page, $this->prop('eventKey'));
                if ($eventKey === null || $eventKey === '') {
                    continue;
                }
                $snapshot = $this->snapshotFromPage($page);
                if (!empty($snapshot['id'])) {
                    $this->existingByEventKey[$eventKey] = $snapshot;
                }
            }

            $cursor = $resp['next_cursor'] ?? null;
            $hasMore = (bool)($resp['has_more'] ?? false);
        } while ($hasMore);
    }

    public function upsert(array $data): void
    {
        $existing = $this->findByEventKey($data['eventKey']);
        $existingId = $existing['id'] ?? null;

        if ($data['cancelled'] === true) {
            if ($existingId) {
                if (!($existing['archived'] ?? false)) {
                    $this->notion->updatePage($existingId, ['archived' => true]);
                    $this->output->writeln("ARCHIVED {$data['eventKey']}");
                }
            }
            return;
        }

        $props = [
            $this->prop('name') => self::notionTitle($data['title']),
            $this->prop('start') => self::notionDate($data['startIso']),
            $this->prop('duration') => self::notionNumber($data['durationMin']),
            $this->prop('type') => self::notionSelect($data['type']),
            $this->prop('personRel') => self::notionRelation([$data['personId']]),
            $this->prop('source') => self::notionSelect('gcal'),
            $this->prop('eventKey') => self::notionRichText($data['eventKey']),
            $this->prop('calendar') => self::notionSelect($data['calendarLabel']),
            $this->prop('link') => self::notionUrl($data['link']),
        ];

        if (!empty($data['dealId'])) {
            $props[$this->prop('dealRel')] = self::notionRelation([$data['dealId']]);
        }

        if ($existingId) {
            if ($existing && $this->isUpToDate($existing, $data)) {
                $this->output->writeln("SKIP {$data['eventKey']}");
                return;
            }
            $this->notion->updatePage($existingId, ['properties' => $props]);
            $this->output->writeln("UPDATED {$data['eventKey']}");
        } else {
            $created = $this->notion->createPage([
                'parent' => ['database_id' => $this->timeDbId],
                'properties' => $props,
            ]);
            $createdId = $created['id'] ?? null;
            if ($createdId) {
                $this->existingByEventKey[$data['eventKey']] = [
                    'id' => $createdId,
                    'archived' => false,
                ];
            }
            $this->output->writeln("CREATED {$data['eventKey']}");
        }
    }

    private function findByEventKey(string $eventKey): ?array
    {
        if ($this->prefetched && array_key_exists($eventKey, $this->existingByEventKey)) {
            return $this->existingByEventKey[$eventKey];
        }

        $snapshot = $this->fetchByEventKey($eventKey);
        if ($this->prefetched) {
            $this->existingByEventKey[$eventKey] = $snapshot;
        }
        return $snapshot;
    }

    private function fetchByEventKey(string $eventKey): ?array
    {
        $resp = $this->notion->queryDatabase($this->timeDbId, [
            'page_size' => 1,
            'filter' => [
                'property' => $this->prop('eventKey'),
                'rich_text' => ['equals' => $eventKey],
            ],
        ]);
        $page = $resp['results'][0] ?? null;
        return $page ? $this->snapshotFromPage($page) : null;
    }

    private function isUpToDate(array $existing, array $data): bool
    {
        $expectedStartTs = self::toTimestamp($data['startIso']);
        if (($existing['title'] ?? null) !== $data['title']) {
            return false;
        }
        if (($existing['startTs'] ?? null) !== $expectedStartTs) {
            return false;
        }
        if ((int)($existing['durationMin'] ?? -1) !== (int)$data['durationMin']) {
            return false;
        }
        if (($existing['type'] ?? null) !== $data['type']) {
            return false;
        }
        if (($existing['source'] ?? null) !== 'gcal') {
            return false;
        }
        if (($existing['calendar'] ?? null) !== $data['calendarLabel']) {
            return false;
        }
        if ((string)($existing['link'] ?? '') !== (string)$data['link']) {
            return false;
        }
        if (!self::relationEquals($existing['personIds'] ?? [], [$data['personId']])) {
            return false;
        }
        if (!empty($data['dealId'])) {
            if (!self::relationEquals($existing['dealIds'] ?? [], [$data['dealId']])) {
                return false;
            }
        }
        return true;
    }

    private function prop(string $key): string
    {
        return $this->props[$key];
    }

    private static function notionTitle(string $text): array
    {
        return ['title' => [['type' => 'text', 'text' => ['content' => $text]]]];
    }

    private static function notionRichText(string $text): array
    {
        return ['rich_text' => [['type' => 'text', 'text' => ['content' => $text]]]];
    }

    private static function notionDate(string $iso): array
    {
        return ['date' => ['start' => $iso]];
    }

    private static function notionNumber(float|int $number): array
    {
        return ['number' => $number];
    }

    private static function notionSelect(string $name): array
    {
        return ['select' => ['name' => $name]];
    }

    private static function notionUrl(string $url): array
    {
        return ['url' => $url];
    }

    private static function notionRelation(array $pageIds): array
    {
        return ['relation' => array_map(static fn($id) => ['id' => $id], $pageIds)];
    }

    private function snapshotFromPage(array $page): array
    {
        return [
            'id' => $page['id'] ?? null,
            'archived' => (bool)($page['archived'] ?? false),
            'title' => $this->extractTitle($page, $this->prop('name')),
            'startTs' => self::toTimestamp($this->extractDateStart($page, $this->prop('start'))),
            'durationMin' => $this->extractNumber($page, $this->prop('duration')),
            'type' => $this->extractSelectName($page, $this->prop('type')),
            'personIds' => $this->extractRelationIds($page, $this->prop('personRel')),
            'dealIds' => $this->extractRelationIds($page, $this->prop('dealRel')),
            'source' => $this->extractSelectName($page, $this->prop('source')),
            'calendar' => $this->extractSelectName($page, $this->prop('calendar')),
            'link' => $this->extractUrl($page, $this->prop('link')),
        ];
    }

    private function extractTitle(array $page, string $prop): ?string
    {
        $title = $page['properties'][$prop]['title'] ?? null;
        if (!is_array($title)) {
            return null;
        }
        $text = '';
        foreach ($title as $item) {
            if (!is_array($item)) {
                continue;
            }
            $text .= $item['plain_text'] ?? '';
        }
        return $text === '' ? null : $text;
    }

    private function extractRichText(array $page, string $prop): ?string
    {
        $richText = $page['properties'][$prop]['rich_text'] ?? null;
        if (!is_array($richText)) {
            return null;
        }
        $text = '';
        foreach ($richText as $item) {
            if (!is_array($item)) {
                continue;
            }
            $text .= $item['plain_text'] ?? '';
        }
        return $text === '' ? null : $text;
    }

    private function extractDateStart(array $page, string $prop): ?string
    {
        return $page['properties'][$prop]['date']['start'] ?? null;
    }

    private function extractNumber(array $page, string $prop): ?float
    {
        $value = $page['properties'][$prop]['number'] ?? null;
        return is_numeric($value) ? (float)$value : null;
    }

    private function extractSelectName(array $page, string $prop): ?string
    {
        return $page['properties'][$prop]['select']['name'] ?? null;
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

    private function extractUrl(array $page, string $prop): ?string
    {
        $url = $page['properties'][$prop]['url'] ?? null;
        return $url ? (string)$url : null;
    }

    private static function toTimestamp(?string $iso): ?int
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($iso))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function relationEquals(array $existingIds, array $expectedIds): bool
    {
        $existing = self::normalizeRelationIds($existingIds);
        $expected = self::normalizeRelationIds($expectedIds);
        return $existing === $expected;
    }

    private static function normalizeRelationIds(array $ids): array
    {
        $normalized = array_values(array_unique(array_filter(array_map('strval', $ids))));
        sort($normalized);
        return $normalized;
    }
}
