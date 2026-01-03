<?php

declare(strict_types=1);

namespace App\Command;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendarService;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SyncGCalToNotionCommand extends Command
{
    private const DEFAULT_DAYS_BACK = 90;
    private const TZ = 'Europe/Vilnius';

    private const NOTION_API_VERSION = '2022-06-28';
    private const NOTION_MIN_DELAY_MS = 350;

    private const REQUIRE_TG_OR_BILLABLE = true;
    private const SKIP_SLOTS_BY_DEFAULT = true;
    private const MIN_DURATION_MINUTES = 3;

    private const PEOPLE_PROP_TG = 'tg';

    private const DEALS_PROP_PERSON_REL = 'Person';
    private const DEALS_PROP_STAGE = 'Sales stage';
    private const DEALS_PROP_START_DATE = 'Start date';

    private const TIME_PROP_NAME = 'Name';
    private const TIME_PROP_START = 'Start';
    private const TIME_PROP_DURATION_MIN = 'Duration (min)';
    private const TIME_PROP_TYPE = 'Type';
    private const TIME_PROP_PERSON_REL = 'Person';
    private const TIME_PROP_DEAL_REL = 'Deal';
    private const TIME_PROP_SOURCE = 'Source';
    private const TIME_PROP_GCAL_EVENTKEY = 'GCal Event Key';
    private const TIME_PROP_CALENDAR = 'Calendar';
    private const TIME_PROP_LINK = 'Link';

    protected function configure(): void
    {
        $this
            ->setName('gcal:sync')
            ->setDescription('Sync Google Calendar events into Notion time entries')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Days back to sync', (string)self::DEFAULT_DAYS_BACK);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $daysBack = (int)$input->getOption('days');
        $daysBack = max(1, $daysBack > 0 ? $daysBack : self::DEFAULT_DAYS_BACK);

        date_default_timezone_set(self::TZ);

        $googleClientId = $this->requireEnv('GOOGLE_CLIENT_ID');
        $googleClientSecret = $this->requireEnv('GOOGLE_CLIENT_SECRET');
        $googleRefreshToken = $this->requireEnv('GOOGLE_REFRESH_TOKEN');

        $notionToken = $this->requireEnv('NOTION_TOKEN');
        $notionTimeDbId = $this->requireEnv('NOTION_DB_TIME_ENTRIES_ID');
        $notionPeopleDbId = $this->requireEnv('NOTION_DB_PEOPLE_ID');
        $notionDealsDbId = $this->env('NOTION_DB_DEALS_ID', '') ?? '';

        $calendarIdPersonal = $this->env('CALENDAR_ID_PERSONAL', 'primary') ?? 'primary';
        $calendarIdCrossMocks = $this->env('CALENDAR_ID_CROSS_MOCKS', '') ?? '';

        $calendars = [$calendarIdPersonal => 'personal'];
        if ($calendarIdCrossMocks !== '') {
            $calendars[$calendarIdCrossMocks] = 'cross-mocks';
        }

        $notion = new NotionClient($notionToken, self::NOTION_API_VERSION, self::NOTION_MIN_DELAY_MS);
        $peopleResolver = new PeopleResolver($notion, $notionPeopleDbId, self::PEOPLE_PROP_TG);
        $dealResolver = new DealResolver(
            $notion,
            $notionDealsDbId,
            self::DEALS_PROP_PERSON_REL,
            self::DEALS_PROP_STAGE,
            self::DEALS_PROP_START_DATE
        );
        $timeSync = new TimeEntriesSync(
            $notion,
            $notionTimeDbId,
            [
                'name' => self::TIME_PROP_NAME,
                'start' => self::TIME_PROP_START,
                'duration' => self::TIME_PROP_DURATION_MIN,
                'type' => self::TIME_PROP_TYPE,
                'personRel' => self::TIME_PROP_PERSON_REL,
                'dealRel' => self::TIME_PROP_DEAL_REL,
                'source' => self::TIME_PROP_SOURCE,
                'eventKey' => self::TIME_PROP_GCAL_EVENTKEY,
                'calendar' => self::TIME_PROP_CALENDAR,
                'link' => self::TIME_PROP_LINK,
            ],
            $output
        );

        $gcal = $this->getGoogleCalendarService($googleClientId, $googleClientSecret, $googleRefreshToken);

        $timeMin = $this->isoUtcMinusDays($daysBack);
        $timeMax = $this->isoNowUtc();

        $peopleResolver->warmCache();
        $timeSync->primeExistingByRange($timeMin, $timeMax);

        $output->writeln("Sync window: {$timeMin} .. {$timeMax}");
        $output->writeln('Calendars: ' . implode(', ', array_map(
            static fn($id, $label) => "{$label}({$id})",
            array_keys($calendars),
            array_values($calendars)
        )));

        foreach ($calendars as $calendarId => $calendarLabel) {
            $output->writeln('');
            $output->writeln("--- Calendar: {$calendarLabel} ({$calendarId}) ---");

            $pageToken = null;
            $seen = 0;

            do {
                $opt = [
                    'timeMin' => $timeMin,
                    'timeMax' => $timeMax,
                    'singleEvents' => true,
                    'orderBy' => 'startTime',
                    'maxResults' => 2500,
                    'showDeleted' => true,
                ];
                if ($pageToken) {
                    $opt['pageToken'] = $pageToken;
                }

                $events = $gcal->events->listEvents($calendarId, $opt);
                $pageToken = $events->getNextPageToken();

                foreach ($events->getItems() as $event) {
                    $seen++;

                    $cancelled = ((string)$event->getStatus() === 'cancelled');

                    $summary = (string)$event->getSummary();
                    $description = (string)$event->getDescription();

                    $startIso = $event->getStart()?->getDateTime();
                    $endIso = $event->getEnd()?->getDateTime();

                    if (!$startIso || !$endIso) {
                        continue;
                    }

                    $tg = $this->extractFirstTg($summary, $description);
                    $billable = $this->hasBillableFlag($description);
                    $slot = $this->isSlot($summary, $description);

                    if (self::SKIP_SLOTS_BY_DEFAULT && $slot && !$billable && !$tg) {
                        continue;
                    }
                    if (self::REQUIRE_TG_OR_BILLABLE && !$tg && !$billable) {
                        continue;
                    }
                    if (!$tg) {
                        continue;
                    }

                    $personId = $peopleResolver->resolvePersonIdByTg($tg);
                    if (!$personId) {
                        $output->writeln("SKIP (unknown tg=@{$tg}) eventId={$event->getId()} summary=\"{$summary}\"");
                        continue;
                    }

                    $type = $this->detectType($summary, $description);

                    $startTs = (new DateTimeImmutable($startIso))->getTimestamp();
                    $endTs = (new DateTimeImmutable($endIso))->getTimestamp();
                    $durationMin = (int)round(($endTs - $startTs) / 60);

                    if ($durationMin < self::MIN_DURATION_MINUTES) {
                        continue;
                    }

                    $eventKey = $calendarId . ':' . (string)$event->getId();
                    $dealId = $dealResolver->resolveActiveDealIdForPerson($personId);

                    $timeSync->upsert([
                        'eventKey' => $eventKey,
                        'title' => '@' . $tg . ' — ' . $type,
                        'startIso' => $startIso,
                        'durationMin' => $durationMin,
                        'type' => $type,
                        'personId' => $personId,
                        'dealId' => $dealId,
                        'calendarLabel' => $calendarLabel,
                        'link' => (string)$event->getHtmlLink(),
                        'cancelled' => $cancelled,
                    ]);
                }
            } while ($pageToken);

            $output->writeln("Fetched events (raw): {$seen}");
        }

        $output->writeln('');
        $output->writeln('Done.');

        return Command::SUCCESS;
    }

    private function isoNowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }

    private function isoUtcMinusDays(int $days): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->sub(new DateInterval('P' . $days . 'D'))
            ->format(DateTimeInterface::ATOM);
    }

    private function normalizeTg(string $tg): string
    {
        $tg = trim($tg);
        $tg = ltrim($tg, '@') ?? $tg;
        return strtolower($tg);
    }

    private function extractFirstTg(string $summary, string $description): ?string
    {
        $text = $summary . "\n" . $description;
        if (preg_match('/@([a-zA-Z0-9_]{3,})/', $text, $m)) {
            return $this->normalizeTg($m[1]);
        }
        return null;
    }

    private function hasBillableFlag(string $description): bool
    {
        return (bool)preg_match('/\bbillable\s*=\s*1\b/i', $description);
    }

    private function isSlot(string $summary, string $description): bool
    {
        return (bool)preg_match('/\bslot\s*=\s*1\b/i', $description)
            || (bool)preg_match('/\bslot\b/i', $summary);
    }

    private function detectType(string $summary, string $description): string
    {
        if (preg_match('/\btype\s*=\s*([a-zA-Z0-9_-]+)\b/i', $description, $m)) {
            return strtolower($m[1]);
        }
        $s = strtolower($summary . ' ' . $description);
        $map = [
            'review' => ['review', 'ревью', 'code review', 'pr'],
            'session' => ['session', 'сессия', 'занятие', 'менторинг'],
            'mock' => ['mock', 'мок', 'interview', 'интервью', 'собес'],
            'call' => ['call', 'созвон', 'sync', 'синк'],
            'group' => ['group', 'групп', 'группа'],
            'admin' => ['admin', 'организац', 'орг', 'invoice', 'счет', 'договор'],
            'prep' => ['prep', 'подготов', 'plan', 'план'],
            'chat' => ['chat', 'чат', 'перепис'],
        ];
        foreach ($map as $type => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($s, $needle)) {
                    return $type;
                }
            }
        }
        return 'session';
    }

    private function getGoogleCalendarService(
        string $clientId,
        string $clientSecret,
        string $refreshToken
    ): GoogleCalendarService {
        $client = new GoogleClient();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setAccessType('offline');
        $client->setScopes([GoogleCalendarService::CALENDAR_READONLY]);

        $token = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        if (!empty($token['error'])) {
            throw new RuntimeException('Failed to refresh Google token: ' . json_encode($token));
        }
        return new GoogleCalendarService($client);
    }

    private function env(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }
        $value = trim((string)$value);
        return $value === '' ? $default : $value;
    }

    private function requireEnv(string $key): string
    {
        $value = $this->env($key);
        if ($value === null) {
            throw new RuntimeException("Missing required env: {$key}");
        }
        return $value;
    }
}

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
