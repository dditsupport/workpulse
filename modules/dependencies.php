<?php
// =========================================================
// Dependencies — Download page for files under /Dependency/
// =========================================================

function dependencyDir(): string {
    $root = dirname(__DIR__);
    // Try common casings — Linux filesystems are case-sensitive
    foreach (['Dependency', 'dependency', 'Dependencies', 'dependencies', 'DEPENDENCY'] as $name) {
        $p = $root . DIRECTORY_SEPARATOR . $name;
        if (is_dir($p)) return $p;
    }
    // Case-insensitive fallback: scan parent for any matching folder
    $items = @scandir($root);
    if ($items) {
        foreach ($items as $f) {
            if ($f === '.' || $f === '..') continue;
            if (is_dir($root . DIRECTORY_SEPARATOR . $f) && strcasecmp($f, 'dependency') === 0) {
                return $root . DIRECTORY_SEPARATOR . $f;
            }
        }
    }
    // Default (may not exist) — return canonical path for error messages
    return $root . DIRECTORY_SEPARATOR . 'Dependency';
}

function listDependencyFiles(): array {
    $dir = dependencyDir();
    if (!is_dir($dir)) return [];
    $out = [];
    $items = @scandir($dir);
    if (!$items) return [];
    foreach ($items as $f) {
        if ($f === '.' || $f === '..') continue;
        $full = $dir . DIRECTORY_SEPARATOR . $f;
        if (!is_file($full)) continue;
        $out[] = [
            'name'     => $f,
            'size'     => filesize($full),
            'modified' => filemtime($full),
        ];
    }
    usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

function formatDependencySize(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return number_format($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return number_format($bytes / 1048576, 1) . ' MB';
    return number_format($bytes / 1073741824, 2) . ' GB';
}

function pageDependencies(): void {
    if (!isSuperadmin() && !hasTxn('dependencies')) {
        flash('error', 'Access denied.');
        header('Location: index.php'); exit;
    }
    $files  = listDependencyFiles();
    $depDir = dependencyDir();
    $dirOk  = is_dir($depDir);
?>
<div class="page-header"><h2>Dependencies</h2></div>
<p class="text-muted" style="margin:-8px 0 14px;font-size:13px">
    Download drivers and redistributables required for biometric devices and related tooling.
</p>

<?php if (!$dirOk): ?>
<div class="alert alert-error" style="margin-bottom:12px">
    <strong>Dependency folder missing.</strong>
    Expected at: <code><?= h($depDir) ?></code><br>
    Create this folder on the server and upload files (drivers, redistributables, etc.) into it.
</div>
<?php elseif (empty($files)): ?>
<div class="alert alert-error" style="margin-bottom:12px">
    Folder found but empty: <code><?= h($depDir) ?></code>
</div>
<?php endif; ?>

<div class="table-wrap" data-stack>
    <table class="table">
        <thead>
            <tr>
                <th style="width:50px">#</th>
                <th>File Name</th>
                <th style="width:120px">Size</th>
                <th style="width:180px">Last Modified</th>
                <th style="width:140px">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($files)): ?>
            <tr><td colspan="5" class="empty-row">No dependency files found.</td></tr>
        <?php else: $i = 0; foreach ($files as $f): $i++; ?>
            <tr>
                <td><?= $i ?></td>
                <td><code><?= h($f['name']) ?></code></td>
                <td><?= formatDependencySize((int)$f['size']) ?></td>
                <td class="text-muted" style="font-size:12px"><?=
                    (new DateTime('@' . $f['modified']))
                        ->setTimezone(new DateTimeZone('Asia/Kolkata'))
                        ->format('d M Y H:i')
                ?> IST</td>
                <td>
                    <a href="?page=download_dependency&file=<?= urlencode($f['name']) ?>"
                       class="btn btn-sm btn-primary">Download</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<div class="table-count"><?= count($files) ?> file(s)</div>
<?php
}

// ── Download a single dependency file ────────────────────
function downloadDependency(): void {
    if (!isSuperadmin() && !hasTxn('dependencies')) {
        http_response_code(403);
        exit('Access denied.');
    }
    $requested = $_GET['file'] ?? '';
    // Strict filename: disallow path traversal
    $safe = basename($requested);
    if ($safe === '' || $safe !== $requested || strpos($safe, '..') !== false) {
        http_response_code(400);
        exit('Invalid file.');
    }
    $full = dependencyDir() . DIRECTORY_SEPARATOR . $safe;
    if (!is_file($full)) {
        http_response_code(404);
        exit('File not found.');
    }

    // Stream file
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Content-Length: ' . filesize($full));
    header('Cache-Control: private, must-revalidate');
    readfile($full);
    exit;
}
