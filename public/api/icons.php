<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$pdo = Database::connection();
$method = request_method();

$allowed = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'image/svg+xml' => 'svg',
];

if ($method === 'POST') {
    require_csrf();
    $user = Auth::requireUsableSession();

    if (empty($_FILES['icon']) || !is_uploaded_file($_FILES['icon']['tmp_name'])) {
        json_error('Please choose an icon file');
    }

    $file = $_FILES['icon'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_error('Upload failed');
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        json_error('Icon must be 2MB or smaller');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: '';
    if (!isset($allowed[$mime])) {
        json_error('Only PNG / JPG / GIF / WebP / SVG are supported');
    }

    $ext = $allowed[$mime];
    $userDir = uploads_dir() . '/' . (int) $user['id'];
    if (!is_dir($userDir)) {
        mkdir($userDir, 0755, true);
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $userDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        json_error('Failed to save icon', 500);
    }

    // basic SVG hardening: strip script tags if any
    if ($ext === 'svg') {
        $svg = file_get_contents($dest);
        if ($svg !== false) {
            $svg = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $svg) ?? $svg;
            $svg = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']/i', '', $svg) ?? $svg;
            file_put_contents($dest, $svg);
        }
    }

    $relative = 'uploads/icons/' . (int) $user['id'] . '/' . $filename;
    $bookmarkId = isset($_POST['bookmark_id']) ? (int) $_POST['bookmark_id'] : 0;

    if ($bookmarkId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM bookmarks WHERE id = ?');
        $stmt->execute([$bookmarkId]);
        $bm = $stmt->fetch();
        if (!$bm) {
            @unlink($dest);
            json_error('Bookmark not found', 404);
        }
        if (!can_edit_bookmark($bm, $user)) {
            @unlink($dest);
            json_error('You cannot modify this bookmark', 403);
        }
        delete_icon_file($bm['icon_path']);
        $pdo->prepare('UPDATE bookmarks SET icon_path = ?, updated_at = datetime(\'now\') WHERE id = ?')
            ->execute([$relative, $bookmarkId]);
    }

    json_ok([
        'icon_path' => $relative,
        'icon_src' => public_url_path($relative),
        'bookmark_id' => $bookmarkId ?: null,
    ]);
}

if ($method === 'DELETE') {
    require_csrf();
    $user = Auth::requireUsableSession();
    $body = request_json();

    $bookmarkId = (int) ($body['bookmark_id'] ?? $_GET['bookmark_id'] ?? 0);
    $iconPath = trim((string) ($body['icon_path'] ?? ''));

    if ($bookmarkId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM bookmarks WHERE id = ?');
        $stmt->execute([$bookmarkId]);
        $bm = $stmt->fetch();
        if (!$bm) {
            json_error('Bookmark not found', 404);
        }
        if (!can_edit_bookmark($bm, $user)) {
            json_error('You cannot modify this bookmark', 403);
        }
        delete_icon_file($bm['icon_path']);
        $pdo->prepare('UPDATE bookmarks SET icon_path = NULL, updated_at = datetime(\'now\') WHERE id = ?')
            ->execute([$bookmarkId]);
        json_ok(['bookmark_id' => $bookmarkId, 'icon_path' => null]);
    }

    if ($iconPath !== '') {
        $normalized = ltrim(str_replace(['..', '\\'], '', $iconPath), '/');
        $prefix = 'uploads/icons/' . (int) $user['id'] . '/';
        if ($user['role'] !== 'admin' && !str_starts_with($normalized, $prefix)) {
            json_error('You cannot delete this icon', 403);
        }
        // ensure not used
        $check = $pdo->prepare('SELECT COUNT(*) FROM bookmarks WHERE icon_path = ?');
        $check->execute([$normalized]);
        if ((int) $check->fetchColumn() > 0) {
            json_error('Icon is still used by a bookmark');
        }
        delete_icon_file($normalized);
        json_ok(['icon_path' => $normalized]);
    }

    json_error('Provide bookmark_id or icon_path');
}

json_error('Method not allowed', 405);
