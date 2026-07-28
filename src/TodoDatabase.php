<?php

declare(strict_types=1);

final class TodoDatabase
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

        $dbPath = $dataDir . '/todo.db';
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

    private static function migrate(): void
    {
        $schemaPath = dirname(__DIR__) . '/sql/todo_schema.sql';
        if (is_file($schemaPath)) {
            $schema = file_get_contents($schemaPath);
            if ($schema !== false) {
                self::$pdo->exec($schema);
            }
        }

        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY NOT NULL,
                value TEXT NOT NULL DEFAULT \'\'
            )'
        );
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL DEFAULT \'\',
                description TEXT NOT NULL DEFAULT \'\',
                status TEXT NOT NULL DEFAULT \'todo\' CHECK(status IN (\'todo\', \'in_progress\', \'done\')),
                due_date TEXT,
                assignee_id INTEGER,
                visibility TEXT NOT NULL DEFAULT \'private\' CHECK(visibility IN (\'private\', \'share\')),
                owner_id INTEGER NOT NULL,
                archived INTEGER NOT NULL DEFAULT 0 CHECK(archived IN (0, 1)),
                archived_at TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS task_groups (
                task_id INTEGER NOT NULL,
                group_id INTEGER NOT NULL,
                PRIMARY KEY (task_id, group_id),
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
            )'
        );
        self::$pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_owner ON tasks(owner_id)');
        self::$pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_assignee ON tasks(assignee_id)');
        self::$pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_status ON tasks(status)');
        self::$pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_due ON tasks(due_date)');
        self::$pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_updated ON tasks(updated_at)');
        self::$pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_visibility ON tasks(visibility)');

        $cols = self::$pdo->query('PRAGMA table_info(tasks)')->fetchAll();
        $names = array_map(static fn ($c) => $c['name'], $cols);
        if (!in_array('archived', $names, true)) {
            self::$pdo->exec(
                'ALTER TABLE tasks ADD COLUMN archived INTEGER NOT NULL DEFAULT 0 CHECK(archived IN (0, 1))'
            );
        }
        if (!in_array('archived_at', $names, true)) {
            self::$pdo->exec('ALTER TABLE tasks ADD COLUMN archived_at TEXT');
        }
        self::$pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_archived ON tasks(archived)');

        $stmt = self::$pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute(['enabled']);
        if ($stmt->fetchColumn() === false) {
            self::$pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)')
                ->execute(['enabled', '0']);
        }
    }

    private static function seed(): void
    {
        self::$pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)')
            ->execute(['enabled', '0']);
    }
}
