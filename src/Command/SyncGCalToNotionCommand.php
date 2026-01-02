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
        $tg = preg_replace('/^@+/', '', $tg) ?? $tg;
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

    public function __construct(
        private readonly NotionClient $notion,
        private readonly string $peopleDbId,
        private readonly string $tgProperty
    ) {}

    public function resolvePersonIdByTg(string $tgNorm): ?string
    {
        if (isset($this->cache[$tgNorm])) {
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
        return null;
    }
}

final class DealResolver
{
    public function __construct(
        private readonly NotionClient $notion,
        private readonly string $dealsDbId,
        private readonly string $personRelationProperty,
        private readonly string $stageProperty,
        private readonly string $startDateProperty
    ) {}

    public function resolveActiveDealIdForPerson(string $personPageId): ?string
    {
        if ($this->dealsDbId === '') {
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

        return $resp['results'][0]['id'] ?? null;
    }
}

final class TimeEntriesSync
{
    public function __construct(
        private readonly NotionClient $notion,
        private readonly string $timeDbId,
        private readonly array $props,
        private readonly OutputInterface $output
    ) {}

    public function upsert(array $data): void
    {
        $existingId = $this->findByEventKey($data['eventKey']);

        if ($data['cancelled'] === true) {
            if ($existingId) {
                $this->notion->updatePage($existingId, ['archived' => true]);
                $this->output->writeln("ARCHIVED {$data['eventKey']}");
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
            $this->notion->updatePage($existingId, ['properties' => $props]);
            $this->output->writeln("UPDATED {$data['eventKey']}");
        } else {
            $this->notion->createPage([
                'parent' => ['database_id' => $this->timeDbId],
                'properties' => $props,
            ]);
            $this->output->writeln("CREATED {$data['eventKey']}");
        }
    }

    private function findByEventKey(string $eventKey): ?string
    {
        $resp = $this->notion->queryDatabase($this->timeDbId, [
            'page_size' => 1,
            'filter' => [
                'property' => $this->prop('eventKey'),
                'rich_text' => ['equals' => $eventKey],
            ],
        ]);
        return $resp['results'][0]['id'] ?? null;
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
}
