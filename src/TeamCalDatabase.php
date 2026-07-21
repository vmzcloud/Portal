<?php

declare(strict_types=1);

final class TeamCalDatabase
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dataDir = dirname(__DIR__) . '/data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $configDir = $dataDir . '/teamcal';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        self::ensureDefaultJsonFiles($configDir);

        $dbPath = $dataDir . '/teamcal.db';
        $isNew = !file_exists($dbPath);

        self::$pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$pdo->exec('PRAGMA foreign_keys = ON');

        self::migrate();
        if ($isNew) {
            self::seed();
        }

        return self::$pdo;
    }

    public static function configDir(): string
    {
        $dir = dirname(__DIR__) . '/data/teamcal';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function migrate(): void
    {
        $schemaPath = dirname(__DIR__) . '/sql/teamcal_schema.sql';
        if (is_file($schemaPath)) {
            $schema = file_get_contents($schemaPath);
            if ($schema !== false) {
                self::$pdo->exec($schema);
            }
        }

        // Ensure tables exist even if schema file is missing (e.g. not volume-mounted)
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY NOT NULL,
                value TEXT NOT NULL DEFAULT \'\'
            )'
        );
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                type TEXT NOT NULL DEFAULT \'\',
                description TEXT NOT NULL DEFAULT \'\',
                location TEXT NOT NULL DEFAULT \'\',
                color TEXT NOT NULL DEFAULT \'#4fc3f7\',
                starts_at TEXT NOT NULL,
                ends_at TEXT NOT NULL,
                all_day INTEGER NOT NULL DEFAULT 0 CHECK(all_day IN (0, 1)),
                period TEXT NOT NULL DEFAULT \'none\' CHECK(period IN (\'none\', \'am\', \'pm\')),
                visibility TEXT NOT NULL DEFAULT \'public\' CHECK(visibility IN (\'public\', \'share\', \'private\')),
                owner_id INTEGER,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS event_persons (
                event_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                PRIMARY KEY (event_id, user_id),
                FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
            )'
        );
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS event_groups (
                event_id INTEGER NOT NULL,
                group_id INTEGER NOT NULL,
                PRIMARY KEY (event_id, group_id),
                FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
            )'
        );
        self::$pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_starts ON events(starts_at)');
        self::$pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_ends ON events(ends_at)');
        self::$pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_visibility ON events(visibility)');
        self::$pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_owner ON events(owner_id)');

        $cols = self::$pdo->query('PRAGMA table_info(events)')->fetchAll();
        $names = array_map(static fn ($c) => $c['name'], $cols);
        if (!in_array('ics_uid', $names, true)) {
            self::$pdo->exec('ALTER TABLE events ADD COLUMN ics_uid TEXT');
        }
        self::$pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_ics_uid ON events(ics_uid)');

        $stmt = self::$pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute(['enabled']);
        if ($stmt->fetchColumn() === false) {
            self::$pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)')
                ->execute(['enabled', '0']);
        }
    }

    private static function seed(): void
    {
        // enabled default already inserted in migrate when missing
    }

    private static function ensureDefaultJsonFiles(string $configDir): void
    {
        $typesPath = $configDir . '/event_types.json';
        if (!is_file($typesPath)) {
            file_put_contents(
                $typesPath,
                json_encode(['Meeting', 'Leave', 'Holiday', 'Other'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
            );
        }
        $locPath = $configDir . '/locations.json';
        if (!is_file($locPath)) {
            file_put_contents(
                $locPath,
                json_encode(['Office', 'Remote', 'Room A', 'Room B'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
            );
        }
        $holPath = $configDir . '/holidays.json';
        if (!is_file($holPath)) {
            file_put_contents($holPath, "{}\n");
        }
    }
}
