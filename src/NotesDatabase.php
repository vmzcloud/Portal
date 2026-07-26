<?php

declare(strict_types=1);

final class NotesDatabase
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

        $dbPath = $dataDir . '/notes.db';
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
        $schemaPath = dirname(__DIR__) . '/sql/notes_schema.sql';
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
            'CREATE TABLE IF NOT EXISTS notes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL DEFAULT \'\',
                body_html TEXT NOT NULL DEFAULT \'\',
                visibility TEXT NOT NULL DEFAULT \'private\' CHECK(visibility IN (\'private\', \'share\')),
                owner_id INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS note_groups (
                note_id INTEGER NOT NULL,
                group_id INTEGER NOT NULL,
                PRIMARY KEY (note_id, group_id),
                FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
            )'
        );
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE
            )'
        );
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS note_tags (
                note_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                PRIMARY KEY (note_id, tag_id),
                FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
                FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
            )'
        );
        self::$pdo->exec('CREATE INDEX IF NOT EXISTS idx_notes_owner ON notes(owner_id)');
        self::$pdo->exec('CREATE INDEX IF NOT EXISTS idx_notes_visibility ON notes(visibility)');
        self::$pdo->exec('CREATE INDEX IF NOT EXISTS idx_notes_updated ON notes(updated_at)');
        self::$pdo->exec('CREATE INDEX IF NOT EXISTS idx_note_tags_tag ON note_tags(tag_id)');

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
