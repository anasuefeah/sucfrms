<?php
ob_start();
session_start();
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

// Must be logged in
if (!isLoggedIn()) {
    http_response_code(403);
    die('Access denied');
}

$file = $_GET['file'] ?? '';

// Prevent directory traversal
if (empty($file) || strpos($file, '..') !== false || strpos($file, "\0") !== false) {
    http_response_code(400);
    die('Invalid file path');
}

// Security: only allow files from known upload directories
if (!preg_match('#^(uploads/(kra[1-4]|avatars|pre_eval/kra[1-4])|includes/apply/uploads/kra[1-4])/.+$#', $file)) {
    http_response_code(400);
    die('Invalid file path');
}

// Build absolute path from project root
$root     = realpath(__DIR__ . '/../');
$abs_path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);

if (!file_exists($abs_path)) {
    http_response_code(404);
    die('File not found');
}

// Final traversal guard
$real = realpath($abs_path);
if (!$real || strpos($real, $root) !== 0) {
    http_response_code(400);
    die('Invalid file path');
}

// Get MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $real);
finfo_close($finfo);

// Look up original filename from DB (kra_evidence_files or pre_eval_files)
$original_filename = basename($real); // fallback to stored name
$rel_file = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(str_replace($root, '', $real), DIRECTORY_SEPARATOR));
try {
    $fn = $pdo->prepare("SELECT original_filename FROM kra_evidence_files WHERE file_path = ? LIMIT 1");
    $fn->execute([$rel_file]);
    $row = $fn->fetch();
    if (!$row) {
        $fn2 = $pdo->prepare("SELECT original_filename FROM pre_eval_files WHERE file_path = ? LIMIT 1");
        $fn2->execute([$rel_file]);
        $row = $fn2->fetch();
    }
    if ($row && !empty($row['original_filename'])) {
        $original_filename = $row['original_filename'];
    }
} catch (\Exception $e) {}

$is_image = in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
$is_pdf   = $mime === 'application/pdf';

// For PDFs and non-image files, serve directly inline
if ($is_pdf || !$is_image) {
    $display_name = $original_filename;
    ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($real));
    header('Content-Disposition: inline; filename="' . addslashes($display_name) . '"; filename*=UTF-8\'\'' . rawurlencode($display_name));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($real);
    exit;
}

// For images, wrap in a viewer page
$fname    = $original_filename;
$img_url  = '?file=' . urlencode($file) . '&raw=1';
$filesize = round(filesize($real) / 1024, 1) . ' KB';

// If raw=1 is requested, serve the image directly
if (isset($_GET['raw'])) {
    ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($real));
    header('Content-Disposition: inline; filename="' . addslashes($original_filename) . '"');
    header('Cache-Control: private, max-age=3600');
    readfile($real);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($fname) ?> &mdash; Evidence Viewer</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      background: #0f172a;
      color: #e2e8f0;
      font-family: system-ui, sans-serif;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    .toolbar {
      background: #1e293b;
      border-bottom: 1px solid #334155;
      padding: 0.75rem 1.25rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      flex-wrap: wrap;
    }
    .toolbar-left {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }
    .file-icon {
      width: 36px; height: 36px;
      background: #2563b0;
      border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.1rem;
    }
    .file-name { font-weight: 600; font-size: 0.95rem; color: #f1f5f9; }
    .file-meta { font-size: 0.75rem; color: #94a3b8; margin-top: 2px; }
    .toolbar-actions { display: flex; gap: 0.5rem; }
    .btn {
      padding: 0.45rem 1rem;
      border-radius: 6px;
      border: none;
      font-size: 0.82rem;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
    }
    .btn-primary { background: #2563b0; color: #fff; }
    .btn-secondary { background: #334155; color: #e2e8f0; }
    .btn:hover { opacity: 0.88; }
    .viewer {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2rem;
      overflow: auto;
    }
    .viewer img {
      max-width: 100%;
      max-height: calc(100vh - 100px);
      border-radius: 8px;
      box-shadow: 0 8px 32px rgba(0,0,0,0.5);
      background: #fff;
      display: block;
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <div class="toolbar-left">
      <div class="file-icon">&#128444;</div>
      <div>
        <div class="file-name"><?= htmlspecialchars($fname) ?></div>
        <div class="file-meta"><?= strtoupper(pathinfo($fname, PATHINFO_EXTENSION)) ?> &nbsp;&middot;&nbsp; <?= $filesize ?></div>
      </div>
    </div>
    <div class="toolbar-actions">
      <a href="<?= htmlspecialchars($img_url) ?>" download="<?= htmlspecialchars($fname) ?>" class="btn btn-secondary">
        â¬‡ Download
      </a>
      <button onclick="window.close()" class="btn btn-secondary">&#10005; Close</button>
    </div>
  </div>
  <div class="viewer">
    <img src="<?= htmlspecialchars($img_url) ?>" alt="<?= htmlspecialchars($fname) ?>">
  </div>
</body>
</html>
