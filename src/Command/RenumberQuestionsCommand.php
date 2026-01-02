<?php

declare(strict_types=1);

namespace App\Command;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RenumberQuestionsCommand extends Command
{
    private const NOTION_API_VERSION = '2022-06-28';

    protected function configure(): void
    {
        $this
            ->setName('notion:renumber-questions')
            ->setDescription('Renumber questions in the Notion database')
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Start number', '1')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned updates without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token = $this->requireEnv('NOTION_TOKEN');
        $dbId = $this->requireEnv('NOTION_DB_QUESTIONS_ID');

        $orderProp = 'Number';
        $propCategory = 'категория';
        $propProb = 'вероятность встретить';
        $propDiff = 'сложность';
        $propName = 'вопрос';

        $start = max(1, (int)$input->getOption('start'));
        $dryRun = (bool)$input->getOption('dry-run');

        $http = new HttpClient([
            'base_uri' => 'https://api.notion.com/v1/',
            'timeout' => 30,
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Notion-Version' => self::NOTION_API_VERSION,
                'Content-Type' => 'application/json',
            ],
        ]);

        $sorts = [
            ['property' => $propCategory, 'direction' => 'ascending'],
            ['property' => $propDiff, 'direction' => 'ascending'],
            ['property' => $propProb, 'direction' => 'ascending'],
            ['property' => $propName, 'direction' => 'ascending'],
        ];

        $output->writeln("DB: {$dbId}");
        $output->writeln("Order prop: {$orderProp}");
        $output->writeln("Sort: {$propCategory} asc, {$propDiff} asc, {$propProb} asc");
        $output->writeln("Start: {$start}");
        $output->writeln($dryRun ? 'Mode: DRY RUN' : 'Mode: WRITE');
        $output->writeln('');

        $cursor = null;
        $pages = [];

        do {
            $payload = [
                'page_size' => 100,
                'sorts' => $sorts,
            ];
            if ($cursor) {
                $payload['start_cursor'] = $cursor;
            }

            $resp = $this->notionQueryDb($http, $dbId, $payload);
            foreach (($resp['results'] ?? []) as $page) {
                if (!empty($page['id'])) {
                    $pages[] = $page;
                }
            }

            $cursor = $resp['next_cursor'] ?? null;
        } while (!empty($resp['has_more']));

        $output->writeln('Fetched: ' . count($pages));

        $n = $start;
        $updated = 0;
        $skipped = 0;

        foreach ($pages as $page) {
            $pageId = $page['id'];
            $title = $this->getTitle($page);

            $current = $page['properties'][$orderProp]['number'] ?? null;

            if ($current !== null && (int)$current === $n) {
                $skipped++;
                $n++;
                continue;
            }

            $output->writeln(sprintf(
                '%4d  %-40s  (%s)',
                $n,
                mb_strimwidth($title, 0, 40, '…'),
                $pageId
            ));

            if (!$dryRun) {
                try {
                    $this->notionPatchPage($http, $pageId, [
                        'properties' => [
                            $orderProp => ['number' => $n],
                        ],
                    ]);
                    $updated++;
                    $this->sleepMs(120);
                } catch (RequestException $e) {
                    $code = $e->getResponse()?->getStatusCode();
                    $body = $e->getResponse() ? (string)$e->getResponse()->getBody() : '';
                    $output->writeln("ERROR {$code}: {$body}");
                    if ($code === 429) {
                        $this->sleepMs(1200);
                    }
                }
            }

            $n++;
        }

        $output->writeln('');
        $output->writeln("Done. Updated={$updated}, skipped={$skipped}");

        return Command::SUCCESS;
    }

    private function notionQueryDb(HttpClient $http, string $dbId, array $payload): array
    {
        $res = $http->post("databases/{$dbId}/query", ['json' => $payload]);
        return json_decode((string)$res->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function notionPatchPage(HttpClient $http, string $pageId, array $payload): array
    {
        $res = $http->patch("pages/{$pageId}", ['json' => $payload]);
        return json_decode((string)$res->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function getTitle(array $page): string
    {
        $props = $page['properties'] ?? [];
        foreach ($props as $prop) {
            if (($prop['type'] ?? '') === 'title') {
                $text = '';
                foreach (($prop['title'] ?? []) as $title) {
                    $text .= $title['plain_text'] ?? '';
                }
                return trim($text);
            }
        }
        return '';
    }

    private function sleepMs(int $ms): void
    {
        usleep($ms * 1000);
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
            throw new RuntimeException("Missing env: {$key}");
        }
        return $value;
    }
}
