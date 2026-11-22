<?php
/** Validate the filename and server-inspected contents; browser MIME types are untrusted. */
function pdfUploadError(array $file, int $maxBytes = 50 * 1024 * 1024): ?string {
    if (!is_string($file['name'] ?? null) || !is_string($file['tmp_name'] ?? null)) return 'Only PDF documents are accepted.';
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return 'The PDF could not be uploaded. Please select it again.';
    if (strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION)) !== 'pdf') return 'Only PDF documents are accepted.';
    $path = $file['tmp_name'] ?? '';
    if (!$path || !is_file($path) || !is_readable($path)) return 'The PDF could not be read. Please select it again.';
    $size = filesize($path);
    if ($size === false || $size === 0) return 'The PDF file is empty or unreadable.';
    if (max($size, (int)($file['size'] ?? 0)) > $maxBytes) return 'The PDF exceeds the ' . ($maxBytes / 1024 / 1024) . ' MB file size limit.';
    if (@file_get_contents($path, false, null, 0, 5) !== '%PDF-') return 'Only valid PDF documents are accepted. Renaming another file to .pdf does not convert it.';
    if (!class_exists('finfo') || (new finfo(FILEINFO_MIME_TYPE))->file($path) !== 'application/pdf') return 'Only valid PDF documents are accepted.';
    return null;
}

/** Check every file before moving any files or changing submission records. */
function pdfUploadBatchError(array $upload, int $maxBytes = 50 * 1024 * 1024): ?string {
    $names = (array)($upload['name'] ?? []);
    foreach ($names as $index => $name) {
        if (!is_string($name)) return 'Only PDF documents are accepted.';
        $file = [];
        foreach (['name','tmp_name','size','error'] as $key) {
            $value = $upload[$key] ?? ($key === 'error' ? UPLOAD_ERR_NO_FILE : '');
            $file[$key] = is_array($value) ? ($value[$index] ?? '') : $value;
        }
        if ($name === '' && $file['error'] === UPLOAD_ERR_NO_FILE) continue;
        if ($error = pdfUploadError($file, $maxBytes)) return $error;
    }
    return null;
}
