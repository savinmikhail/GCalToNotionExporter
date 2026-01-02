<?php

declare(strict_types=1);

namespace App\Command;

use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendarService;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class GetRefreshTokenCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('gcal:refresh-token')
            ->setDescription('Fetch a Google OAuth refresh token for Calendar read-only scope')
            ->addArgument('code', InputArgument::OPTIONAL, 'Authorization code from Google OAuth');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $clientId = $this->requireEnv('GOOGLE_CLIENT_ID');
        $clientSecret = $this->requireEnv('GOOGLE_CLIENT_SECRET');
        $redirectUri = $this->env('GOOGLE_REDIRECT_URI', 'http://127.0.0.1:8085/') ?? 'http://127.0.0.1:8085/';

        $client = new GoogleClient();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        $client->setScopes([GoogleCalendarService::CALENDAR_READONLY]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $code = $input->getArgument('code');

        if ($code === null) {
            $authUrl = $client->createAuthUrl();
            $output->writeln("Open URL:\n{$authUrl}\n");
            $output->writeln("Starting local server on {$redirectUri} ...");

            $parts = parse_url($redirectUri);
            if (!is_array($parts)) {
                $errorOutput->writeln('Failed to parse redirect URI.');
                return Command::FAILURE;
            }

            $host = $parts['host'] ?? '127.0.0.1';
            $port = $parts['port'] ?? 8085;

            $server = stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
            if (!$server) {
                $errorOutput->writeln("Failed to start local server: {$errstr} ({$errno})");
                return Command::FAILURE;
            }

            $this->openBrowser($authUrl, $output);

            $conn = @stream_socket_accept($server, 300);
            if (!$conn) {
                $errorOutput->writeln('No callback received within 300s.');
                fclose($server);
                return Command::FAILURE;
            }

            $request = '';
            while (!feof($conn)) {
                $line = fgets($conn);
                if ($line === false) {
                    break;
                }
                $request .= $line;
                if (rtrim($line) === '') {
                    break;
                }
            }

            if (!preg_match('#GET\s+([^ ]+)\s+HTTP#', $request, $m)) {
                $errorOutput->writeln("Could not parse request:\n{$request}");
                fclose($conn);
                fclose($server);
                return Command::FAILURE;
            }

            $path = $m[1];
            $url = "http://{$host}" . $path;
            $parsed = parse_url($url);
            parse_str($parsed['query'] ?? '', $query);
            $code = $query['code'] ?? null;

            $html = '<html><body><h3>OK</h3><p>You can close this tab.</p></body></html>';
            fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: " . strlen($html) . "\r\n\r\n" . $html);
            fclose($conn);
            fclose($server);

            if ($code === null) {
                $errorOutput->writeln('Callback received but no code param.');
                return Command::FAILURE;
            }
        }

        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (!empty($token['error'])) {
            $errorOutput->writeln(json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::FAILURE;
        }

        $output->writeln('REFRESH_TOKEN: ' . ($token['refresh_token'] ?? ''));
        $output->writeln('ACCESS_TOKEN: ' . ($token['access_token'] ?? ''));
        $output->writeln(json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }

    private function openBrowser(string $url, OutputInterface $output): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            @exec('open ' . escapeshellarg($url));
            return;
        }

        if (PHP_OS_FAMILY === 'Linux') {
            @exec('xdg-open ' . escapeshellarg($url));
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            @exec('cmd /c start "" ' . escapeshellarg($url));
            return;
        }

        $output->writeln("Open the URL in your browser:\n{$url}");
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
