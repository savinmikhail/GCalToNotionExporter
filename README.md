# GCalToNotionExporter

## Usage

1) Install dependencies:

```bash
composer install
```

2) Create `.env` from `.env.dist` and fill values.

3) Run commands via the console entrypoint:

```bash
php index.php list
```

### Sync Google Calendar to Notion

```bash
php index.php gcal:sync --days=90
```

### Renumber questions in Notion

```bash
php index.php notion:renumber-questions --start=1 --dry-run
php index.php notion:renumber-questions --start=1
```

### Get Google refresh token

```bash
php index.php gcal:refresh-token
# or if you already have the auth code
php index.php gcal:refresh-token <code>
```
