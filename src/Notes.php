<?php

declare(strict_types=1);

final class Notes
{
    public static function pdo(): PDO
    {
        return NotesDatabase::connection();
    }

    public static function getSetting(string $key, string $default = ''): string
    {
        $stmt = self::pdo()->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val === false ? $default : (string) $val;
    }

    public static function setSetting(string $key, string $value): void
    {
        self::pdo()->prepare(
            'INSERT INTO settings (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        )->execute([$key, $value]);
    }

    public static function isEnabled(): bool
    {
        return self::getSetting('enabled', '0') === '1';
    }

    public static function requireEnabled(): void
    {
        if (!self::isEnabled()) {
            json_error('Notes is disabled', 403);
        }
    }

    /** @return list<int> */
    public static function fetchGroupIds(int $noteId): array
    {
        $stmt = self::pdo()->prepare('SELECT group_id FROM note_groups WHERE note_id = ?');
        $stmt->execute([$noteId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function syncGroups(int $noteId, array $groupIds): void
    {
        $pdo = self::pdo();
        $pdo->prepare('DELETE FROM note_groups WHERE note_id = ?')->execute([$noteId]);
        $ins = $pdo->prepare('INSERT INTO note_groups (note_id, group_id) VALUES (?, ?)');
        foreach (array_unique(array_map('intval', $groupIds)) as $gid) {
            if ($gid > 0) {
                $ins->execute([$noteId, $gid]);
            }
        }
    }

    public static function normalizeTagName(string $raw): ?string
    {
        $name = mb_strtolower(trim($raw));
        if (str_starts_with($name, '#')) {
            $name = ltrim($name, '#');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 40) {
            return null;
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/u', $name)) {
            return null;
        }
        return $name;
    }

    /**
     * @param list<mixed> $raw
     * @return list<string>
     */
    public static function normalizeTagList(array $raw): array
    {
        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item) && !is_numeric($item)) {
                continue;
            }
            $name = self::normalizeTagName((string) $item);
            if ($name === null) {
                continue;
            }
            $out[$name] = $name;
            if (count($out) >= 20) {
                break;
            }
        }
        return array_values($out);
    }

    /** @return list<string> */
    public static function fetchTags(int $noteId): array
    {
        $stmt = self::pdo()->prepare(
            'SELECT t.name FROM tags t
             INNER JOIN note_tags nt ON nt.tag_id = t.id
             WHERE nt.note_id = ?
             ORDER BY t.name COLLATE NOCASE'
        );
        $stmt->execute([$noteId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<string> $names Already normalized tag names
     */
    public static function syncTags(int $noteId, array $names): void
    {
        $pdo = self::pdo();
        $pdo->prepare('DELETE FROM note_tags WHERE note_id = ?')->execute([$noteId]);
        if ($names === []) {
            return;
        }

        $find = $pdo->prepare('SELECT id FROM tags WHERE name = ?');
        $create = $pdo->prepare('INSERT INTO tags (name) VALUES (?)');
        $link = $pdo->prepare('INSERT OR IGNORE INTO note_tags (note_id, tag_id) VALUES (?, ?)');

        foreach ($names as $name) {
            $find->execute([$name]);
            $tagId = $find->fetchColumn();
            if ($tagId === false) {
                $create->execute([$name]);
                $tagId = (int) $pdo->lastInsertId();
            } else {
                $tagId = (int) $tagId;
            }
            $link->execute([$noteId, $tagId]);
        }
    }

    public static function canViewNote(array $note, ?array $user, array $userGroupIds): bool
    {
        if (!$user) {
            return false;
        }
        if (($user['role'] ?? '') === 'admin') {
            return true;
        }
        $ownerId = $note['owner_id'] ?? null;
        if ($ownerId !== null && (int) $ownerId === (int) $user['id']) {
            return true;
        }
        if (($note['visibility'] ?? '') === 'share') {
            $groups = $note['group_ids'] ?? [];
            if (!is_array($groups)) {
                $groups = [];
            }
            foreach ($groups as $gid) {
                if (in_array((int) $gid, $userGroupIds, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function canEditNote(array $note, ?array $user): bool
    {
        if (!$user) {
            return false;
        }
        if (($user['role'] ?? '') === 'admin') {
            return true;
        }
        $ownerId = $note['owner_id'] ?? null;
        return $ownerId !== null && (int) $ownerId === (int) $user['id'];
    }

    /**
     * Allowlist HTML for note bodies.
     */
    public static function sanitizeHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $allowed = [
            'p', 'br', 'div', 'span', 'font', 'b', 'strong', 'i', 'em', 'u', 's',
            'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'blockquote', 'code', 'pre', 'a',
        ];

        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"><div id="notes-root">' . $html . '</div>';
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $root = $doc->getElementById('notes-root');
        if (!$root) {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            return '';
        }

        self::sanitizeNode($root, $allowed);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $out;
    }

    /** @param list<string> $allowed */
    private static function sanitizeNode(DOMNode $node, array $allowed): void
    {
        if (!($node instanceof DOMElement)) {
            return;
        }

        $toRemove = [];
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $toRemove[] = $child;
                continue;
            }
            if ($child instanceof DOMText) {
                continue;
            }
            if (!($child instanceof DOMElement)) {
                $toRemove[] = $child;
                continue;
            }

            $tag = strtolower($child->tagName);
            if ($tag === 'script' || $tag === 'style' || $tag === 'iframe' || $tag === 'object' || $tag === 'embed') {
                $toRemove[] = $child;
                continue;
            }

            if (!in_array($tag, $allowed, true)) {
                // unwrap: keep children
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $toRemove[] = $child;
                continue;
            }

            // Strip all attributes except safe ones
            $attrs = [];
            if ($child->hasAttributes()) {
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $attrs[] = $attr->name;
                }
            }
            foreach ($attrs as $name) {
                $lname = strtolower($name);
                if (str_starts_with($lname, 'on')) {
                    $child->removeAttribute($name);
                    continue;
                }
                if ($tag === 'a' && $lname === 'href') {
                    $href = trim($child->getAttribute('href'));
                    if ($href === '' || preg_match('/^\s*javascript:/i', $href)) {
                        $child->removeAttribute('href');
                    } else {
                        $child->setAttribute('href', $href);
                        $child->setAttribute('rel', 'noopener noreferrer');
                        $child->setAttribute('target', '_blank');
                    }
                    continue;
                }
                if (($tag === 'span' || $tag === 'font') && $lname === 'style') {
                    $safe = self::sanitizeStyleColorOnly($child->getAttribute('style'));
                    if ($safe !== null) {
                        $child->setAttribute('style', $safe);
                    } else {
                        $child->removeAttribute('style');
                    }
                    continue;
                }
                if ($tag === 'font' && $lname === 'color') {
                    $safeColor = self::sanitizeColorValue($child->getAttribute('color'));
                    if ($safeColor !== null) {
                        $child->setAttribute('color', $safeColor);
                    } else {
                        $child->removeAttribute('color');
                    }
                    continue;
                }
                if ($lname === 'class' || $lname === 'style') {
                    $child->removeAttribute($name);
                    continue;
                }
                if ($tag === 'a' && ($lname === 'href' || $lname === 'rel' || $lname === 'target')) {
                    continue;
                }
                if ($tag === 'font' && $lname === 'color') {
                    continue;
                }
                $child->removeAttribute($name);
            }

            self::sanitizeNode($child, $allowed);
        }

        foreach ($toRemove as $dead) {
            if ($dead->parentNode) {
                $dead->parentNode->removeChild($dead);
            }
        }
    }

    public static function sanitizeColorValue(string $raw): ?string
    {
        $v = trim($raw);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $v)) {
            return strtolower($v);
        }
        if (preg_match(
            '/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*(0|0?\.\d+|1(?:\.0+)?))?\s*\)$/i',
            $v,
            $m
        )) {
            $r = (int) $m[1];
            $g = (int) $m[2];
            $b = (int) $m[3];
            if ($r > 255 || $g > 255 || $b > 255) {
                return null;
            }
            if (isset($m[4]) && $m[4] !== '') {
                return sprintf('rgba(%d, %d, %d, %s)', $r, $g, $b, $m[4]);
            }
            return sprintf('rgb(%d, %d, %d)', $r, $g, $b);
        }
        return null;
    }

    public static function sanitizeStyleColorOnly(string $style): ?string
    {
        $parts = preg_split('/\s*;\s*/', trim($style)) ?: [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (!preg_match('/^color\s*:\s*(.+)$/i', $part, $m)) {
                continue;
            }
            $color = self::sanitizeColorValue(trim($m[1]));
            if ($color !== null) {
                return 'color: ' . $color;
            }
        }
        return null;
    }

    public static function plainPreview(string $html, int $max = 120): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return mb_substr($text, 0, $max - 1) . '…';
    }

    public static function enrichNote(array $row, ?array $userMap = null): array
    {
        $id = (int) $row['id'];
        $row['id'] = $id;
        $row['owner_id'] = (int) $row['owner_id'];
        $row['group_ids'] = self::fetchGroupIds($id);
        $row['tags'] = self::fetchTags($id);
        $row['title'] = (string) ($row['title'] ?? '');
        $row['body_html'] = (string) ($row['body_html'] ?? '');
        $row['preview'] = self::plainPreview($row['body_html']);

        if ($userMap === null) {
            $userMap = TeamCal::portalUserMap();
        }
        $row['owner_name'] = $userMap[$row['owner_id']] ?? 'Deleted user';

        return $row;
    }

    public static function countByOwner(int $userId): int
    {
        $stmt = self::pdo()->prepare('SELECT COUNT(*) FROM notes WHERE owner_id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public static function deleteByOwner(int $userId): int
    {
        $stmt = self::pdo()->prepare('DELETE FROM notes WHERE owner_id = ?');
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    public static function reassignOwner(int $fromUserId, int $toUserId): int
    {
        if ($fromUserId <= 0 || $toUserId <= 0 || $fromUserId === $toUserId) {
            return 0;
        }
        $stmt = self::pdo()->prepare('UPDATE notes SET owner_id = ? WHERE owner_id = ?');
        $stmt->execute([$toUserId, $fromUserId]);
        return $stmt->rowCount();
    }

    public static function loadNote(int $id): ?array
    {
        $stmt = self::pdo()->prepare('SELECT * FROM notes WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return self::enrichNote($row);
    }

    /**
     * @return list<array>
     */
    public static function listVisibleNotes(array $user, array $userGroupIds, string $q = ''): array
    {
        $stmt = self::pdo()->query(
            'SELECT * FROM notes ORDER BY updated_at DESC, id DESC'
        );
        $rows = $stmt->fetchAll();
        $userMap = TeamCal::portalUserMap();
        $q = mb_strtolower(trim($q));
        if (str_starts_with($q, '#')) {
            $q = ltrim($q, '#');
        }
        $out = [];
        foreach ($rows as $row) {
            $note = self::enrichNote($row, $userMap);
            if (!self::canViewNote($note, $user, $userGroupIds)) {
                continue;
            }
            if ($q !== '') {
                $tags = is_array($note['tags'] ?? null) ? implode(' ', $note['tags']) : '';
                $hay = mb_strtolower(
                    $note['title'] . ' ' . $note['preview'] . ' ' . strip_tags($note['body_html']) . ' ' . $tags
                );
                if (!str_contains($hay, $q)) {
                    continue;
                }
            }
            $note['can_edit'] = self::canEditNote($note, $user);
            $out[] = $note;
        }
        return $out;
    }
}
