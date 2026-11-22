<?php
require_once __DIR__ . '/../includes/pdf_upload.php';
require_once __DIR__ . '/../fpdf/fpdf.php';
$checks = 0;
$paths = [];
function pdfCheck(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function pdfFixture(string $contents, string $name): array {
    global $paths;
    $path = tempnam(sys_get_temp_dir(), 'pdf_policy_');
    $paths[] = $path;
    file_put_contents($path, $contents);
    return ['name'=>$name,'tmp_name'=>$path,'size'=>strlen($contents),'error'=>UPLOAD_ERR_OK];
}
try {
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial','',12);
    $pdf->Cell(0,10,'PDF upload validation');
    $valid = pdfFixture($pdf->Output('S'), 'evidence.pdf');
    pdfCheck(pdfUploadError($valid) === null, 'Real PDF was rejected');
    pdfCheck(pdfUploadError(array_replace($valid,['name'=>'EVIDENCE.PDF'])) === null, 'Uppercase PDF extension was rejected');
    pdfCheck(pdfUploadError(array_replace($valid,['type'=>'image/jpeg'])) === null, 'Browser MIME overrode valid server-inspected PDF contents');
    foreach (['jpg','jpeg','png','webp','gif','doc','docx','xlsx','zip','txt','exe','php','pdf.exe',''] as $ext) {
        pdfCheck(pdfUploadError(array_replace($valid,['name'=>'evidence.' . $ext])) !== null, "Non-PDF extension {$ext} was accepted");
    }
    $fake = pdfFixture(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aXioAAAAASUVORK5CYII='), 'renamed.pdf');
    pdfCheck(pdfUploadError($fake) !== null, 'PNG renamed to PDF was accepted');
    $text = pdfFixture('Ordinary text, not a PDF.', 'text.pdf');
    pdfCheck(pdfUploadError(array_replace($text,['type'=>'application/pdf'])) !== null, 'Spoofed browser PDF MIME type was accepted');
    pdfCheck(pdfUploadError(pdfFixture('', 'empty.pdf')) !== null, 'Empty PDF was accepted');
    pdfCheck(pdfUploadError(array_replace($valid,['error'=>UPLOAD_ERR_PARTIAL])) !== null, 'Partial upload was accepted');
    pdfCheck(pdfUploadError(array_replace($valid,['tmp_name'=>$valid['tmp_name'] . '.missing'])) !== null, 'Missing temporary file was accepted');
    pdfCheck(pdfUploadError($valid,$valid['size']) === null, 'PDF at size limit was rejected');
    pdfCheck(pdfUploadError($valid,$valid['size'] - 1) !== null, 'Oversized PDF was accepted');
    pdfCheck(pdfUploadError(array_replace($valid,['size'=>5 * 1024 * 1024 + 1]),5 * 1024 * 1024) !== null, 'Appeal attachment exceeded 5 MB limit');
    pdfCheck(pdfUploadError(array_replace($valid,['size'=>50 * 1024 * 1024 + 1])) !== null, 'Evidence exceeded 50 MB limit');
    pdfCheck(pdfUploadBatchError($valid) === null, 'Single-file upload was rejected');
    pdfCheck(pdfUploadBatchError(['name'=>'','tmp_name'=>'','size'=>0,'error'=>UPLOAD_ERR_NO_FILE]) === null, 'An optional empty file input was rejected');
    $batch = [];
    foreach (['name','tmp_name','size','error'] as $key) $batch[$key] = [$valid[$key],$valid[$key]];
    pdfCheck(pdfUploadBatchError($batch) === null, 'Valid PDF batch was rejected');
    $batch['name'][1] = 'photo.jpeg';
    pdfCheck(pdfUploadBatchError($batch) !== null, 'Mixed batch accepted a JPEG');
    foreach (['name','tmp_name','size','error'] as $key) $batch[$key][1] = $fake[$key];
    pdfCheck(pdfUploadBatchError($batch) !== null, 'Mixed batch accepted an image renamed to PDF');
    pdfCheck(pdfUploadBatchError(['name'=>[['nested.pdf']]]) !== null, 'Malformed nested file input was accepted');
    echo "PDF upload policy: {$checks} checks passed.\n";
} finally {
    foreach ($paths as $path) if (is_file($path)) unlink($path);
}
