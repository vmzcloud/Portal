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
            'p', 'br', 'div', 'span', 'b', 'strong', 'i', 'em', 'u', 's',
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
                if ($lname === 'class' || $lname === 'style') {
                    $child->removeAttribute($name);
                    continue;
                }
                if (!($tag === 'a' && ($lname === 'href' || $lname === 'rel' || $lname === 'target'))) {
                    $child->removeAttribute($name);
                }
            }

            self::sanitizeNode($child, $allowed);
        }

        foreach ($toRemove as $dead) {
            if ($dead->parentNode) {
                $dead->parentNode->removeChild($dead);
            }
        }
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
        $row['title'] = (string) ($row['title'] ?? '');
        $row['body_html'] = (string) ($row['body_html'] ?? '');
        $row['preview'] = self::plainPreview($row['body_html']);

        if ($userMap === null) {
            $userMap = TeamCal::portalUserMap();
        }
        $row['owner_name'] = $userMap[$row['owner_id']] ?? ('user#' . $row['owner_id']);

        return $row;
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
        $out = [];
        foreach ($rows as $row) {
            $note = self::enrichNote($row, $userMap);
            if (!self::canViewNote($note, $user, $userGroupIds)) {
                continue;
            }
            if ($q !== '') {
                $hay = mb_strtolower($note['title'] . ' ' . $note['preview'] . ' ' . strip_tags($note['body_html']));
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
