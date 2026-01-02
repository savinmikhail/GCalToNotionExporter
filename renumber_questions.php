<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

Dotenv::createImmutable(__DIR__)->safeLoad();

function envs(string $k, ?string $d=null): ?string {
    $v = $_ENV[$k] ?? $_SERVER[$k] ?? getenv($k);
    if ($v === false || $v === null) return $d;
    $v = trim((string)$v);
    return $v === '' ? $d : $v;
}
function requireEnv(string $k): string {
    $v = envs($k);
    if ($v === null) throw new RuntimeException("Missing env: {$k}");
    return $v;
}
function msleep(int $ms): void { usleep($ms * 1000); }

$token = requireEnv('NOTION_TOKEN');
$dbId  = requireEnv('NOTION_DB_QUESTIONS_ID');

$orderProp = 'Number';
$propCategory =  'категория';
$propProb ='вероятность встретить';
$propDiff =  'сложность';
$propName =  'вопрос';

$start = 1;
$dryRun = false;

foreach ($argv as $arg) {
    if (preg_match('/^--start=(\d+)$/', $arg, $m)) $start = max(1, (int)$m[1]);
    if ($arg === '--dry-run') $dryRun = true;
}

$http = new Client([
    'base_uri' => 'https://api.notion.com/v1/',
    'timeout' => 30,
    'headers' => [
        'Authorization' => "Bearer {$token}",
        'Notion-Version' => '2022-06-28',
        'Content-Type' => 'application/json',
    ],
]);

function notionQueryDb(Client $http, string $dbId, array $payload): array {
    $res = $http->post("databases/{$dbId}/query", ['json' => $payload]);
    return json_decode((string)$res->getBody(), true, 512, JSON_THROW_ON_ERROR);
}
function notionPatchPage(Client $http, string $pageId, array $payload): array {
    $res = $http->patch("pages/{$pageId}", ['json' => $payload]);
    return json_decode((string)$res->getBody(), true, 512, JSON_THROW_ON_ERROR);
}
function getTitle(array $page): string {
    $props = $page['properties'] ?? [];
    foreach ($props as $p) {
        if (($p['type'] ?? '') === 'title') {
            $txt = '';
            foreach (($p['title'] ?? []) as $t) $txt .= $t['plain_text'] ?? '';
            return trim($txt);
        }
    }
    return '';
}

$sorts = [
    ['property' => $propCategory, 'direction' => 'ascending'],
    ['property' => $propDiff,     'direction' => 'ascending'],
    ['property' => $propProb,     'direction' => 'ascending'],
    ['property' => $propName,     'direction' => 'ascending'],
];

echo "DB: {$dbId}\n";
echo "Order prop: {$orderProp}\n";
echo "Sort: {$propProb} desc, {$propDiff} asc, {$propCategory} asc\n";
echo "Start: {$start}\n";
echo $dryRun ? "Mode: DRY RUN\n\n" : "Mode: WRITE\n\n";

$cursor = null;
$pages = [];

do {
    $payload = [
        'page_size' => 100,
        'sorts' => $sorts,
    ];
    if ($cursor) $payload['start_cursor'] = $cursor;

    $resp = notionQueryDb($http, $dbId, $payload);
    foreach (($resp['results'] ?? []) as $p) {
        if (!empty($p['id'])) $pages[] = $p;
    }

    $cursor = $resp['next_cursor'] ?? null;
} while (!empty($resp['has_more']));

echo "Fetched: " . count($pages) . "\n";

$n = $start;
$updated = 0;
$skipped = 0;

foreach ($pages as $p) {
    $pageId = $p['id'];
    $title  = getTitle($p);

    $current = $p['properties'][$orderProp]['number'] ?? null;

    // если уже совпадает — пропустим (ускоряет повторный прогон)
    if ($current !== null && (int)$current === $n) {
        $skipped++;
        $n++;
        continue;
    }

    echo sprintf("%4d  %-40s  (%s)\n", $n, mb_strimwidth($title, 0, 40, '…'), $pageId);

    if (!$dryRun) {
        try {
            notionPatchPage($http, $pageId, [
                'properties' => [
                    $orderProp => ['number' => $n],
                ],
            ]);
            $updated++;
            msleep(120); // лёгкий троттлинг под Notion
        } catch (RequestException $e) {
            $code = $e->getResponse()?->getStatusCode();
            $body = $e->getResponse() ? (string)$e->getResponse()->getBody() : '';
            echo "ERROR {$code}: {$body}\n";
            if ($code === 429) { msleep(1200); } // backoff
        }
    }

    $n++;
}

echo "\nDone. Updated={$updated}, skipped={$skipped}\n";
