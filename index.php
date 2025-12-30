<?php

/**
 * sync_gcal_to_notion.php
 *
 * One-way sync: Google Calendar events -> Notion database "Time entries".
 * - Identifies student by first @tg in event summary (or any @tg in summary/description).
 * - Skips slots unless explicitly billable.
 * - Upserts by unique event key (calendarId + ":" + eventId).
 * - Optionally links to an active Deal (if you set Deals DB + properties).
 *
 * Requirements:
 *   composer require google/apiclient:^2.0 guzzlehttp/guzzle
 *
 * Run:
 *   php sync_gcal_to_notion.php
 *   php sync_gcal_to_notion.php --days=90
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendarService;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;

// ----------------------------
// CONFIG (edit constants only)
// ----------------------------

// Time window to sync (days back from now)
const DEFAULT_DAYS_BACK = 90;

// Your timezone (used only for display; API uses ISO8601)
const TZ = 'Europe/Vilnius';

// Google OAuth (use ENV in real life)
const GOOGLE_CLIENT_ID     = 'PUT_HERE';
const GOOGLE_CLIENT_SECRET = 'PUT_HERE';
const GOOGLE_REFRESH_TOKEN = 'PUT_HERE';

// Calendars to sync: [calendarId => label]
// Example calendarId for shared calendars is like "...@group.calendar.google.com"
const CALENDARS = [
    'primary' => 'personal',
    'PUT_CROSS_MOCKS_CALENDAR_ID_HERE' => 'cross-mocks',
];

// Notion
const NOTION_TOKEN = 'secret_PUT_HERE';
const NOTION_API_VERSION = '2022-06-28';

// Notion DB IDs
const NOTION_DB_TIME_ENTRIES_ID = 'PUT_HERE';
const NOTION_DB_PEOPLE_ID       = 'PUT_HERE';

// Optional: Deals linkage (set empty string to disable)
const NOTION_DB_DEALS_ID        = ''; // e.g. 'PUT_HERE' or ''

// --- Notion property names (change to match your workspace) ---

// People DB
const PEOPLE_PROP_TG = 'tg'; // property holding telegram contact (text/rich_text). Put your actual property name.

// Deals DB (optional)
const DEALS_PROP_PERSON_REL = 'Person';      // relation to People
const DEALS_PROP_STAGE      = 'Sales stage'; // select
const DEALS_PROP_START_DATE = 'Start date';  // date

// Time entries DB
const TIME_PROP_NAME          = 'Name';           // title
const TIME_PROP_START         = 'Start';          // date
const TIME_PROP_DURATION_MIN  = 'Duration (min)'; // number
const TIME_PROP_TYPE          = 'Type';           // select
const TIME_PROP_PERSON_REL    = 'Person';         // relation -> People
const TIME_PROP_DEAL_REL      = 'Deal';           // relation -> Deals (optional; can exist even if disabled)
const TIME_PROP_SOURCE        = 'Source';         // select
const TIME_PROP_GCAL_EVENTKEY = 'GCal Event Key'; // rich_text
const TIME_PROP_CALENDAR      = 'Calendar';       // select
const TIME_PROP_LINK          = 'Link';           // url

// Business rules
const REQUIRE_TG_OR_BILLABLE = true; // if true: event must have @tg OR description contains billable=1
const SKIP_SLOTS_BY_DEFAULT  = true; // if true: skip events with slot=1 unless @tg present OR billable=1
const MIN_DURATION_MINUTES   = 3;    // ignore too-short artifacts

// Notion request pacing (Notion is strict with rate limits)
const NOTION_MIN_DELAY_MS = 350;

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
    $tg = strtolower($tg);
    return $tg;
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

function msleep(int $ms): void {
    usleep($ms * 1000);
}

function notionHeaders(): array {
    return [
        'Authorization'   => 'Bearer ' . NOTION_TOKEN,
        'Notion-Version'  => NOTION_API_VERSION,
        'Content-Type'    => 'application/json',
    ];
}

// ----------------------------
// Notion client (thin wrapper)
// ----------------------------
final class Notion {
    private HttpClient $http;

    public function __construct() {
        $this->http = new HttpClient([
            'base_uri' => 'https://api.notion.com/v1/',
            'timeout'  => 30,
        ]);
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
        // naive rate limit pacing
        msleep(NOTION_MIN_DELAY_MS);

        try {
            $res = $this->http->request($method, $path, [
                'headers' => notionHeaders(),
                'json'    => $jsonPayload,
            ]);
            $body = (string)$res->getBody();
            return $body !== '' ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : [];
        } catch (RequestException $e) {
            $code = $e->getResponse()?->getStatusCode();
            $body = $e->getResponse() ? (string)$e->getResponse()->getBody() : '';
            // simple retry on 429
            if ($code === 429) {
                msleep(1200);
                $res = $this->http->request($method, $path, [
                    'headers' => notionHeaders(),
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
function getGoogleCalendarService(): GoogleCalendarService {
    $client = new GoogleClient();
    $client->setClientId(GOOGLE_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CLIENT_SECRET);
    $client->setAccessType('offline');
    $client->setScopes([GoogleCalendarService::CALENDAR_READONLY]);

    // Use refresh token to obtain access token
    $token = $client->fetchAccessTokenWithRefreshToken(GOOGLE_REFRESH_TOKEN);
    if (!empty($token['error'])) {
        throw new RuntimeException('Failed to refresh Google token: ' . json_encode($token));
    }

    return new GoogleCalendarService($client);
}

// ----------------------------
// Notion property builders
// ----------------------------
function notionTitle(string $text): array {
    return ['title' => [['type' => 'text', 'text' => ['content' => $text]]]];
}

function notionRichText(string $text): array {
    return ['rich_text' => [['type' => 'text', 'text' => ['content' => $text]]]];
}

function notionDate(string $iso): array {
    return ['date' => ['start' => $iso]];
}

function notionNumber(float|int $n): array {
    return ['number' => $n];
}

function notionSelect(string $name): array {
    return ['select' => ['name' => $name]];
}

function notionUrl(string $url): array {
    return ['url' => $url];
}

function notionRelation(array $pageIds): array {
    return ['relation' => array_map(fn($id) => ['id' => $id], $pageIds)];
}

// ----------------------------
// People lookup (by tg)
// ----------------------------
final class PeopleResolver {
    private Notion $notion;
    /** @var array<string,string> tg_norm => personPageId */
    private array $cache = [];

    public function __construct(Notion $notion) {
        $this->notion = $notion;
    }

    public function resolvePersonIdByTg(string $tgNorm): ?string {
        if (isset($this->cache[$tgNorm])) return $this->cache[$tgNorm];

        // Try multiple variants, because People.tg might contain @ or extra text
        $variants = array_values(array_unique([
            $tgNorm,
            '@' . $tgNorm,
            strtoupper($tgNorm),
            '@' . strtoupper($tgNorm),
        ]));

        foreach ($variants as $v) {
            // Prefer "contains" to tolerate extra formatting in tg field
            $resp = $this->notion->queryDatabase(NOTION_DB_PEOPLE_ID, [
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

// ----------------------------
// Deal lookup (optional): pick latest active deal for person
// ----------------------------
final class DealResolver {
    private Notion $notion;

    public function __construct(Notion $notion) {
        $this->notion = $notion;
    }

    public function resolveActiveDealIdForPerson(string $personPageId): ?string {
        if (NOTION_DB_DEALS_ID === '') return null;

        // Find deals where Person relation contains person AND stage in Started/Active
        $resp = $this->notion->queryDatabase(NOTION_DB_DEALS_ID, [
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

        if (!empty($resp['results'][0]['id'])) {
            return $resp['results'][0]['id'];
        }
        return null;
    }
}

// ----------------------------
// Time entry upsert
// ----------------------------
final class TimeEntriesSync {
    private Notion $notion;

    public function __construct(Notion $notion) {
        $this->notion = $notion;
    }

    public function findByEventKey(string $eventKey): ?string {
        $resp = $this->notion->queryDatabase(NOTION_DB_TIME_ENTRIES_ID, [
            'page_size' => 1,
            'filter' => [
                'property' => TIME_PROP_GCAL_EVENTKEY,
                'rich_text' => ['equals' => $eventKey],
            ],
        ]);

        return $resp['results'][0]['id'] ?? null;
    }

    public function upsert(array $data): void {
        // $data keys:
        // eventKey, title, startIso, durationMin, type, personId, dealId|null, source, calendarLabel, link, cancelled(bool)

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
            TIME_PROP_SOURCE        => notionSelect($data['source']),
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
                'parent' => ['database_id' => NOTION_DB_TIME_ENTRIES_ID],
                'properties' => $props,
            ]);
            echo "CREATED {$data['eventKey']}\n";
        }
    }
}

// ----------------------------
// Main
// ----------------------------
$notion = new Notion();
$peopleResolver = new PeopleResolver($notion);
$dealResolver = new DealResolver($notion);
$timeSync = new TimeEntriesSync($notion);

$gcal = getGoogleCalendarService();

$timeMin = isoUtcMinusDays($daysBack);
$timeMax = isoNowUtc();

echo "Sync window: {$timeMin} .. {$timeMax}\n";
echo "Calendars: " . implode(', ', array_map(fn($id, $label) => "{$label}({$id})", array_keys(CALENDARS), array_values(CALENDARS))) . "\n";

foreach (CALENDARS as $calendarId => $calendarLabel) {
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

            $status = $event->getStatus(); // "confirmed", "cancelled"
            $cancelled = ($status === 'cancelled');

            $summary = (string)$event->getSummary();
            $description = (string)$event->getDescription();

            // Skip all-day events (no dateTime)
            $startObj = $event->getStart();
            $endObj   = $event->getEnd();

            $startIso = $startObj?->getDateTime();
            $endIso   = $endObj?->getDateTime();
            if (!$startIso || !$endIso) {
                continue;
            }

            // Identify person
            $tg = extractFirstTg($summary, $description);
            $billable = hasBillableFlag($description);
            $slot = isSlot($summary, $description);

            if (SKIP_SLOTS_BY_DEFAULT && $slot && !$billable && !$tg) {
                continue;
            }

            if (REQUIRE_TG_OR_BILLABLE && !$tg && !$billable) {
                continue;
            }

            if (!$tg) {
                // billable=1 but no tg -> cannot attribute to a person; skip to avoid garbage
                continue;
            }

            $personId = $peopleResolver->resolvePersonIdByTg($tg);
            if (!$personId) {
                echo "SKIP (unknown tg=@{$tg}) eventId={$event->getId()} summary=\"{$summary}\"\n";
                continue;
            }

            $type = detectType($summary, $description);

            // Duration
            $startTs = (new DateTimeImmutable($startIso))->getTimestamp();
            $endTs   = (new DateTimeImmutable($endIso))->getTimestamp();
            $durationMin = (int)round(($endTs - $startTs) / 60);

            if ($durationMin < MIN_DURATION_MINUTES) {
                continue;
            }

            $eventId = (string)$event->getId();
            $eventKey = $calendarId . ':' . $eventId;

            $link = (string)$event->getHtmlLink();
            $title = '@' . $tg . ' — ' . $type;

            $dealId = $dealResolver->resolveActiveDealIdForPerson($personId);

            $timeSync->upsert([
                'eventKey'      => $eventKey,
                'title'         => $title,
                'startIso'      => $startIso,
                'durationMin'   => $durationMin,
                'type'          => $type,
                'personId'      => $personId,
                'dealId'        => $dealId,
                'source'        => 'gcal',
                'calendarLabel' => $calendarLabel,
                'link'          => $link,
                'cancelled'     => $cancelled,
            ]);
        }
    } while ($pageToken);

    echo "Fetched events (raw): {$seen}\n";
}

echo "\nDone.\n";
