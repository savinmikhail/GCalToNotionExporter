<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Google\Client;
use Google\Service\Calendar;

Dotenv::createImmutable(__DIR__)->safeLoad();

function envs(string $key, ?string $default = null): ?string {
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($v === false || $v === null) return $default;
    $v = trim((string)$v);
    return $v === '' ? $default : $v;
}
function requireEnv(string $key): string {
    $v = envs($key);
    if ($v === null) throw new RuntimeException("Missing required env: {$key}");
    return $v;
}

$clientId = requireEnv('GOOGLE_CLIENT_ID');
$clientSecret = requireEnv('GOOGLE_CLIENT_SECRET');
$redirectUri = envs('GOOGLE_REDIRECT_URI', 'http://127.0.0.1:8085/');

$client = new Client();
$client->setClientId($clientId);
$client->setClientSecret($clientSecret);
$client->setRedirectUri($redirectUri);
$client->setScopes([Calendar::CALENDAR_READONLY]);
$client->setAccessType('offline');
$client->setPrompt('consent');

if (php_sapi_name() !== 'cli') {
    http_response_code(400);
    echo "Run via CLI.\n";
    exit;
}

$code = $argv[1] ?? null;

if ($code === null) {
    $authUrl = $client->createAuthUrl();
    echo "Open URL:\n$authUrl\n\n";
    echo "Starting local server on {$redirectUri} ...\n";

    $parts = parse_url($redirectUri);
    $host = $parts['host'] ?? '127.0.0.1';
    $port = $parts['port'] ?? 8085;

    $server = stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
    if (!$server) {
        fwrite(STDERR, "Failed to start local server: $errstr ($errno)\n");
        exit(1);
    }

    @exec('open ' . escapeshellarg($authUrl)); // macOS

    $conn = @stream_socket_accept($server, 300);
    if (!$conn) {
        fwrite(STDERR, "No callback received within 300s.\n");
        exit(1);
    }

    $request = '';
    while (!feof($conn)) {
        $line = fgets($conn);
        if ($line === false) break;
        $request .= $line;
        if (rtrim($line) === '') break;
    }

    if (!preg_match('#GET\s+([^ ]+)\s+HTTP#', $request, $m)) {
        fwrite(STDERR, "Could not parse request:\n$request\n");
        exit(1);
    }

    $path = $m[1];
    $url = "http://{$host}" . $path;
    $p = parse_url($url);
    parse_str($p['query'] ?? '', $q);
    $code = $q['code'] ?? null;

    $html = "<html><body><h3>OK</h3><p>You can close this tab.</p></body></html>";
    fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: " . strlen($html) . "\r\n\r\n" . $html);
    fclose($conn);
    fclose($server);

    if ($code === null) {
        fwrite(STDERR, "Callback received but no code param.\n");
        exit(1);
    }
}

$token = $client->fetchAccessTokenWithAuthCode($code);

if (!empty($token['error'])) {
    fwrite(STDERR, json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}

echo "REFRESH_TOKEN: " . ($token['refresh_token'] ?? '') . "\n";
echo "ACCESS_TOKEN: " . ($token['access_token'] ?? '') . "\n";
echo json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
