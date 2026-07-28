<?php

declare(strict_types=1);

final class Database
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

        $dbPath = $dataDir . '/portal.db';
        $isNew = !file_exists($dbPath);

        self::$pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$pdo->exec('PRAGMA foreign_keys = ON');

        if ($isNew) {
            self::migrate();
            self::seed();
        } else {
            self::migrateExisting();
        }

        return self::$pdo;
    }

    private static function migrate(): void
    {
        $schema = file_get_contents(dirname(__DIR__) . '/sql/schema.sql');
        if ($schema === false) {
            throw new RuntimeException('Unable to read schema.sql');
        }
        self::$pdo->exec($schema);
        self::ensureNotificationsTable();
    }

    private static function migrateExisting(): void
    {
        $cols = self::$pdo->query('PRAGMA table_info(users)')->fetchAll();
        $names = array_map(static fn ($c) => $c['name'], $cols);
        if (!in_array('is_active', $names, true)) {
            self::$pdo->exec('ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1');
        }
        if (!in_array('must_change_password', $names, true)) {
            self::$pdo->exec('ALTER TABLE users ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0');
        }
        self::ensureNotificationsTable();
    }

    private static function ensureNotificationsTable(): void
    {
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                title TEXT NOT NULL DEFAULT \'\',
                body TEXT NOT NULL DEFAULT \'\',
                link_url TEXT,
                ref_type TEXT,
                ref_id INTEGER,
                actor_id INTEGER,
                is_read INTEGER NOT NULL DEFAULT 0 CHECK(is_read IN (0, 1)),
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )'
        );
        self::$pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, created_at DESC)'
        );
        self::$pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_notifications_unread ON notifications(user_id, is_read)'
        );
    }

    private static function seed(): void
    {
        $pdo = self::$pdo;

        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
        $stmt->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'admin']);
        $adminId = (int) $pdo->lastInsertId();

        $stmt->execute(['demo', password_hash('demo123', PASSWORD_DEFAULT), 'user']);
        $demoId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO groups (name, description) VALUES (?, ?)')
            ->execute(['Default Group', 'Admin-managed sharing group']);
        $groupId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO group_members (group_id, user_id) VALUES (?, ?)')
            ->execute([$groupId, $demoId]);
        $pdo->prepare('INSERT INTO group_members (group_id, user_id) VALUES (?, ?)')
            ->execute([$groupId, $adminId]);

        $tabs = [
            ['Home', 0],
            ['Favorites', 1],
            ['Entertainment', 2],
            ['Social', 3],
            ['Tools', 4],
            ['Shopping', 5],
        ];
        $tabIds = [];
        $tabStmt = $pdo->prepare(
            'INSERT INTO tabs (name, sort_order, owner_id, is_global) VALUES (?, ?, NULL, 1)'
        );
        foreach ($tabs as [$name, $order]) {
            $tabStmt->execute([$name, $order]);
            $tabIds[$name] = (int) $pdo->lastInsertId();
        }

        $categories = [
            ['Favorites', '#4fc3f7', 0, 'Home'],
            ['AI', '#ab47bc', 1, 'Home'],
            ['Social', '#42a5f5', 2, 'Home'],
            ['Entertainment', '#ef5350', 3, 'Home'],
            ['Tools', '#66bb6a', 4, 'Home'],
            ['Shopping', '#ffa726', 5, 'Home'],
            ['News', '#26c6da', 6, 'Home'],
            ['Other', '#bdbdbd', 7, 'Home'],
        ];
        $catIds = [];
        $catStmt = $pdo->prepare(
            'INSERT INTO categories (name, color, sort_order, tab_id, owner_id, is_global) VALUES (?, ?, ?, ?, NULL, 1)'
        );
        foreach ($categories as [$name, $color, $order, $tabName]) {
            $catStmt->execute([$name, $color, $order, $tabIds[$tabName]]);
            $catIds[$name] = (int) $pdo->lastInsertId();
        }

        // Titles may be any language (e.g. Chinese); sample includes both EN and ZH
        $bookmarks = [
            ['Gmail', 'https://mail.google.com', $catIds['Favorites'], 'public'],
            ['Google 地圖', 'https://maps.google.com', $catIds['Favorites'], 'public'],
            ['ChatGPT', 'https://chatgpt.com', $catIds['AI'], 'public'],
            ['Claude', 'https://claude.ai', $catIds['AI'], 'public'],
            ['Gemini', 'https://gemini.google.com', $catIds['AI'], 'public'],
            ['Facebook', 'https://www.facebook.com', $catIds['Social'], 'public'],
            ['Instagram', 'https://www.instagram.com', $catIds['Social'], 'public'],
            ['YouTube', 'https://www.youtube.com', $catIds['Entertainment'], 'public'],
            ['Netflix', 'https://www.netflix.com', $catIds['Entertainment'], 'public'],
            ['GitHub', 'https://github.com', $catIds['Tools'], 'public'],
            ['Notion', 'https://www.notion.so', $catIds['Tools'], 'public'],
            ['Amazon', 'https://www.amazon.com', $catIds['Shopping'], 'public'],
            ['BBC', 'https://www.bbc.com', $catIds['News'], 'public'],
            ['Wikipedia', 'https://www.wikipedia.org', $catIds['Other'], 'public'],
            ['Private Note', 'https://example.com/private', $catIds['Other'], 'private'],
            ['Shared Link', 'https://example.com/share', $catIds['Tools'], 'share'],
        ];

        $bmStmt = $pdo->prepare(
            'INSERT INTO bookmarks (title, url, icon_path, category_id, visibility, owner_id, sort_order)
             VALUES (?, ?, NULL, ?, ?, ?, ?)'
        );
        $order = 0;
        $shareBookmarkId = null;
        foreach ($bookmarks as [$title, $url, $catId, $visibility]) {
            $owner = $visibility === 'private' || $visibility === 'share' ? $adminId : $adminId;
            $bmStmt->execute([$title, $url, $catId, $visibility, $owner, $order++]);
            if ($visibility === 'share') {
                $shareBookmarkId = (int) $pdo->lastInsertId();
            }
        }

        if ($shareBookmarkId !== null) {
            $pdo->prepare('INSERT INTO bookmark_groups (bookmark_id, group_id) VALUES (?, ?)')
                ->execute([$shareBookmarkId, $groupId]);
        }
    }
}
