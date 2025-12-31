<?php
/**
 * sync_gcal_to_notion.php
 * Google Calendar -> Notion Time entries
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendarService;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;

// ----------------------------
// Load .env
// ----------------------------
Dotenv::createImmutable(__DIR__)->safeLoad();

function envs(string $key, ?string $default = null): ?string {
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($v === false || $v === null) return $default;
    $v = trim((string)$v);
    return $v === '' ? $default : $v;
}

function requireEnv(string $key): string {
    $v = envs($key);
    if ($v === null) {
        throw new RuntimeException("Missing required env: {$key}");
    }
    return $v;
}

// ----------------------------
// CONFIG (non-secret)
// ----------------------------
const DEFAULT_DAYS_BACK = 90;
const TZ = 'Europe/Vilnius';

const NOTION_API_VERSION = '2022-06-28';
const NOTION_MIN_DELAY_MS = 350;

const REQUIRE_TG_OR_BILLABLE = true;
const SKIP_SLOTS_BY_DEFAULT  = true;
const MIN_DURATION_MINUTES   = 3;

// --- Notion property names (change if needed) ---
const PEOPLE_PROP_TG = 'tg';

const DEALS_PROP_PERSON_REL = 'Person';
const DEALS_PROP_STAGE      = 'Sales stage';
const DEALS_PROP_START_DATE = 'Start date';

const TIME_PROP_NAME          = 'Name';
const TIME_PROP_START         = 'Start';
const TIME_PROP_DURATION_MIN  = 'Duration (min)';
const TIME_PROP_TYPE          = 'Type';
const TIME_PROP_PERSON_REL    = 'Person';
const TIME_PROP_DEAL_REL      = 'Deal';
const TIME_PROP_SOURCE        = 'Source';
const TIME_PROP_GCAL_EVENTKEY = 'GCal Event Key';
const TIME_PROP_CALENDAR      = 'Calendar';
const TIME_PROP_LINK          = 'Link';

// ----------------------------
// CLI args
// ----------------------------
$daysBack = DEFAULT_DAYS_BACK;
foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $daysBack = max(1, (int)$m[1]);
    }
}
date_default_timezone_set(TZ);

// ----------------------------
// Secrets from env
// ----------------------------
$GOOGLE_CLIENT_ID     = requireEnv('GOOGLE_CLIENT_ID');
$GOOGLE_CLIENT_SECRET = requireEnv('GOOGLE_CLIENT_SECRET');
$GOOGLE_REFRESH_TOKEN = requireEnv('GOOGLE_REFRESH_TOKEN');

$NOTION_TOKEN = requireEnv('NOTION_TOKEN');
$NOTION_DB_TIME_ENTRIES_ID = requireEnv('NOTION_DB_TIME_ENTRIES_ID');
$NOTION_DB_PEOPLE_ID       = requireEnv('NOTION_DB_PEOPLE_ID');
$NOTION_DB_DEALS_ID        = envs('NOTION_DB_DEALS_ID', ''); // optional

$CALENDAR_ID_PERSONAL   = envs('CALENDAR_ID_PERSONAL', 'primary');
$CALENDAR_ID_CROSS_MOCKS = envs('CALENDAR_ID_CROSS_MOCKS', '');

// Calendars to sync
$CALENDARS = [
    $CALENDAR_ID_PERSONAL => 'personal',
];
if ($CALENDAR_ID_CROSS_MOCKS) {
    $CALENDARS[$CALENDAR_ID_CROSS_MOCKS] = 'cross-mocks';
}

// ----------------------------
// Helpers
// ----------------------------
function isoNowUtc(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
}
function isoUtcMinusDays(int $days): string {
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->sub(new DateInterval('P' . $days . 'D'))
        ->format(DateTimeInterface::ATOM);
}
function normalizeTg(string $tg): string {
    $tg = trim($tg);
    $tg = preg_replace('/^@+/', '', $tg) ?? $tg;
    return strtolower($tg);
}
function extractFirstTg(string $summary, string $description): ?string {
    $text = $summary . "\n" . $description;
    if (preg_match('/@([a-zA-Z0-9_]{3,})/', $text, $m)) {
        return normalizeTg($m[1]);
    }
    return null;
}
function hasBillableFlag(string $description): bool {
    return (bool)preg_match('/\bbillable\s*=\s*1\b/i', $description);
}
function isSlot(string $summary, string $description): bool {
    return (bool)preg_match('/\bslot\s*=\s*1\b/i', $description)
        || (bool)preg_match('/\bslot\b/i', $summary);
}
function detectType(string $summary, string $description): string {
    if (preg_match('/\btype\s*=\s*([a-zA-Z0-9_-]+)\b/i', $description, $m)) {
        return strtolower($m[1]);
    }
    $s = strtolower($summary . ' ' . $description);
    $map = [
        'review'   => ['review', 'ревью', 'code review', 'pr'],
        'session'  => ['session', 'сессия', 'занятие', 'менторинг'],
        'mock'     => ['mock', 'мок', 'interview', 'интервью', 'собес'],
        'call'     => ['call', 'созвон', 'sync', 'синк'],
        'group'    => ['group', 'групп', 'группа'],
        'admin'    => ['admin', 'организац', 'орг', 'invoice', 'счет', 'договор'],
        'prep'     => ['prep', 'подготов', 'plan', 'план'],
        'chat'     => ['chat', 'чат', 'перепис'],
    ];
    foreach ($map as $type => $needles) {
        foreach ($needles as $needle) {
            if (str_contains($s, $needle)) return $type;
        }
    }
    return 'session';
}
function msleep(int $ms): void { usleep($ms * 1000); }

// Notion property builders
function notionTitle(string $text): array {
    return ['title' => [['type' => 'text', 'text' => ['content' => $text]]]];
}
function notionRichText(string $text): array {
    return ['rich_text' => [['type' => 'text', 'text' => ['content' => $text]]]];
}
function notionDate(string $iso): array { return ['date' => ['start' => $iso]]; }
function notionNumber(float|int $n): array { return ['number' => $n]; }
function notionSelect(string $name): array { return ['select' => ['name' => $name]]; }
function notionUrl(string $url): array { return ['url' => $url]; }
function notionRelation(array $pageIds): array {
    return ['relation' => array_map(fn($id) => ['id' => $id], $pageIds)];
}

// ----------------------------
// Notion client
// ----------------------------
final class Notion {
    private HttpClient $http;
    public function __construct(private readonly string $token) {
        $this->http = new HttpClient([
            'base_uri' => 'https://api.notion.com/v1/',
            'timeout'  => 30,
        ]);
    }
    private function headers(): array {
        return [
            'Authorization'  => 'Bearer ' . $this->token,
            'Notion-Version' => NOTION_API_VERSION,
            'Content-Type'   => 'application/json',
        ];
    }
    public function queryDatabase(string $dbId, array $payload): array {
        return $this->request('POST', "databases/{$dbId}/query", $payload);
    }
    public function createPage(array $payload): array {
        return $this->request('POST', 'pages', $payload);
    }
    public function updatePage(string $pageId, array $payload): array {
        return $this->request('PATCH', "pages/{$pageId}", $payload);
    }
    private function request(string $method, string $path, array $jsonPayload): array {
        msleep(NOTION_MIN_DELAY_MS);
        try {
            $res = $this->http->request($method, $path, [
                'headers' => $this->headers(),
                'json'    => $jsonPayload,
            ]);
            $body = (string)$res->getBody();
            return $body !== '' ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : [];
        } catch (RequestException $e) {
            $code = $e->getResponse()?->getStatusCode();
            $body = $e->getResponse() ? (string)$e->getResponse()->getBody() : '';
            if ($code === 429) {
                msleep(1200);
                $res = $this->http->request($method, $path, [
                    'headers' => $this->headers(),
                    'json'    => $jsonPayload,
                ]);
                $body = (string)$res->getBody();
                return $body !== '' ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : [];
            }
            throw new RuntimeException("Notion API error {$code} on {$method} {$path}: {$body}", 0, $e);
        }
    }
}

// ----------------------------
// Google Calendar client
// ----------------------------
function getGoogleCalendarService(string $clientId, string $clientSecret, string $refreshToken): GoogleCalendarService {
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

// ----------------------------
// Resolvers
// ----------------------------
final class PeopleResolver {
    private array $cache = [];
    public function __construct(private readonly Notion $notion, private readonly string $peopleDbId) {}
    public function resolvePersonIdByTg(string $tgNorm): ?string {
        if (isset($this->cache[$tgNorm])) return $this->cache[$tgNorm];

        $variants = array_values(array_unique([$tgNorm, '@'.$tgNorm, strtoupper($tgNorm), '@'.strtoupper($tgNorm)]));

        foreach ($variants as $v) {
            $resp = $this->notion->queryDatabase($this->peopleDbId, [
                'page_size' => 5,
                'filter' => [
                    'property' => PEOPLE_PROP_TG,
                    'rich_text' => ['contains' => $v],
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

final class DealResolver {
    public function __construct(
        private readonly Notion $notion,
        private readonly string $dealsDbId
    ) {}
    public function resolveActiveDealIdForPerson(string $personPageId): ?string {
        if ($this->dealsDbId === '') return null;

        $resp = $this->notion->queryDatabase($this->dealsDbId, [
            'page_size' => 20,
            'filter' => [
                'and' => [
                    [
                        'property' => DEALS_PROP_PERSON_REL,
                        'relation' => ['contains' => $personPageId],
                    ],
                    [
                        'or' => [
                            ['property' => DEALS_PROP_STAGE, 'select' => ['equals' => 'Started']],
                            ['property' => DEALS_PROP_STAGE, 'select' => ['equals' => 'Active']],
                        ],
                    ],
                ],
            ],
            'sorts' => [
                ['property' => DEALS_PROP_START_DATE, 'direction' => 'descending'],
            ],
        ]);

        return $resp['results'][0]['id'] ?? null;
    }
}

final class TimeEntriesSync {
    public function __construct(private readonly Notion $notion, private readonly string $timeDbId) {}

    public function findByEventKey(string $eventKey): ?string {
        $resp = $this->notion->queryDatabase($this->timeDbId, [
            'page_size' => 1,
            'filter' => [
                'property' => TIME_PROP_GCAL_EVENTKEY,
                'rich_text' => ['equals' => $eventKey],
            ],
        ]);
        return $resp['results'][0]['id'] ?? null;
    }

    public function upsert(array $data): void {
        $existingId = $this->findByEventKey($data['eventKey']);

        if ($data['cancelled'] === true) {
            if ($existingId) {
                $this->notion->updatePage($existingId, ['archived' => true]);
                echo "ARCHIVED {$data['eventKey']}\n";
            }
            return;
        }

        $props = [
            TIME_PROP_NAME          => notionTitle($data['title']),
            TIME_PROP_START         => notionDate($data['startIso']),
            TIME_PROP_DURATION_MIN  => notionNumber($data['durationMin']),
            TIME_PROP_TYPE          => notionSelect($data['type']),
            TIME_PROP_PERSON_REL    => notionRelation([$data['personId']]),
            TIME_PROP_SOURCE        => notionSelect('gcal'),
            TIME_PROP_GCAL_EVENTKEY => notionRichText($data['eventKey']),
            TIME_PROP_CALENDAR      => notionSelect($data['calendarLabel']),
            TIME_PROP_LINK          => notionUrl($data['link']),
        ];

        if (!empty($data['dealId'])) {
            $props[TIME_PROP_DEAL_REL] = notionRelation([$data['dealId']]);
        }

        if ($existingId) {
            $this->notion->updatePage($existingId, ['properties' => $props]);
            echo "UPDATED {$data['eventKey']}\n";
        } else {
            $this->notion->createPage([
                'parent' => ['database_id' => $this->timeDbId],
                'properties' => $props,
            ]);
            echo "CREATED {$data['eventKey']}\n";
        }
    }
}

// ----------------------------
// Main
// ----------------------------
$notion = new Notion($NOTION_TOKEN);
$peopleResolver = new PeopleResolver($notion, $NOTION_DB_PEOPLE_ID);
$dealResolver = new DealResolver($notion, $NOTION_DB_DEALS_ID);
$timeSync = new TimeEntriesSync($notion, $NOTION_DB_TIME_ENTRIES_ID);

$gcal = getGoogleCalendarService($GOOGLE_CLIENT_ID, $GOOGLE_CLIENT_SECRET, $GOOGLE_REFRESH_TOKEN);

$timeMin = isoUtcMinusDays($daysBack);
$timeMax = isoNowUtc();

echo "Sync window: {$timeMin} .. {$timeMax}\n";
echo "Calendars: " . implode(', ', array_map(
        fn($id, $label) => "{$label}({$id})",
        array_keys($CALENDARS),
        array_values($CALENDARS)
    )) . "\n";

foreach ($CALENDARS as $calendarId => $calendarLabel) {
    echo "\n--- Calendar: {$calendarLabel} ({$calendarId}) ---\n";

    $pageToken = null;
    $seen = 0;

    do {
        $opt = [
            'timeMin'      => $timeMin,
            'timeMax'      => $timeMax,
            'singleEvents' => true,
            'orderBy'      => 'startTime',
            'maxResults'   => 2500,
            'showDeleted'  => true,
        ];
        if ($pageToken) $opt['pageToken'] = $pageToken;

        $events = $gcal->events->listEvents($calendarId, $opt);
        $pageToken = $events->getNextPageToken();

        foreach ($events->getItems() as $event) {
            $seen++;

            $cancelled = ((string)$event->getStatus() === 'cancelled');

            $summary = (string)$event->getSummary();
            $description = (string)$event->getDescription();

            $startIso = $event->getStart()?->getDateTime();
            $endIso   = $event->getEnd()?->getDateTime();

            // skip all-day
            if (!$startIso || !$endIso) continue;

            $tg = extractFirstTg($summary, $description);
            $billable = hasBillableFlag($description);
            $slot = isSlot($summary, $description);

            if (SKIP_SLOTS_BY_DEFAULT && $slot && !$billable && !$tg) continue;
            if (REQUIRE_TG_OR_BILLABLE && !$tg && !$billable) continue;
            if (!$tg) continue; // billable without tg -> ignore (no attribution)

            $personId = $peopleResolver->resolvePersonIdByTg($tg);
            if (!$personId) {
                echo "SKIP (unknown tg=@{$tg}) eventId={$event->getId()} summary=\"{$summary}\"\n";
                continue;
            }

            $type = detectType($summary, $description);

            $startTs = (new DateTimeImmutable($startIso))->getTimestamp();
            $endTs   = (new DateTimeImmutable($endIso))->getTimestamp();
            $durationMin = (int)round(($endTs - $startTs) / 60);

            if ($durationMin < MIN_DURATION_MINUTES) continue;

            $eventKey = $calendarId . ':' . (string)$event->getId();
            $dealId = $dealResolver->resolveActiveDealIdForPerson($personId);

            $timeSync->upsert([
                'eventKey'      => $eventKey,
                'title'         => '@' . $tg . ' — ' . $type,
                'startIso'      => $startIso,
                'durationMin'   => $durationMin,
                'type'          => $type,
                'personId'      => $personId,
                'dealId'        => $dealId,
                'calendarLabel' => $calendarLabel,
                'link'          => (string)$event->getHtmlLink(),
                'cancelled'     => $cancelled,
            ]);
        }
    } while ($pageToken);

    echo "Fetched events (raw): {$seen}\n";
}

echo "\nDone.\n";
