<?php
ensureAppealTables($pdo);

$uid = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';
$appeal_id = (int)($_GET['id'] ?? 0);
$categories = kraAssignmentCategories();

function canFacultyAppeal(PDO $pdo, array $cycle): bool {
    if (($cycle['cycle_status'] ?? $cycle['status'] ?? '') !== 'open') return false;
    if (!empty($cycle['appeal_deadline']) && time() > strtotime($cycle['appeal_deadline'] . ' 23:59:59')) return false;
    return true;
}

function appealUploadFiles(PDO $pdo, int $message_id, string $field = 'attachments'): void {
    if (empty($_FILES[$field]['name']) || !is_array($_FILES[$field]['name'])) return;
    $dir = __DIR__ . '/../uploads/appeals/';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $allowed = ['pdf','jpg','jpeg','png','webp','doc','docx'];
    $finfo = class_exists('finfo') ? new finfo(FILEINFO_MIME_TYPE) : null;
    for ($i = 0; $i < count($_FILES[$field]['name']); $i++) {
        if (($_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        if (($_FILES[$field]['size'][$i] ?? 0) > 5 * 1024 * 1024) continue;
        $orig = basename($_FILES[$field]['name'][$i]);
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;
        $mime = $finfo ? ($finfo->file($_FILES[$field]['tmp_name'][$i]) ?: '') : '';
        if ($mime && !preg_match('/^(application\/pdf|image\/|application\/msword|application\/vnd\.openxmlformats)/', $mime)) continue;
        $name = 'appeal_' . $message_id . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = $dir . $name;
        if (move_uploaded_file($_FILES[$field]['tmp_name'][$i], $dest)) {
            $rel = 'uploads/appeals/' . $name;
            $pdo->prepare("INSERT INTO appeal_attachments (message_id, file_path, original_filename, file_size_bytes) VALUES (?,?,?,?)")
                ->execute([$message_id, $rel, $orig, (int)$_FILES[$field]['size'][$i]]);
        }
    }
}

function fetchAppeal(PDO $pdo, int $appeal_id): array|false {
    $stmt = $pdo->prepare("
        SELECT ap.*, a.status AS app_status, c.cycle_name, c.status AS cycle_status, c.appeal_deadline,
               ks.kra_category, ks.revision_status, ks.revision_note, ks.checker_note,
               f.full_name AS faculty_name, f.first_name AS faculty_first, f.middle_name AS faculty_middle, f.last_name AS faculty_last,
               ch.checker_label, ch.first_name AS checker_first, ch.middle_name AS checker_middle, ch.last_name AS checker_last
        FROM appeals ap
        JOIN applications a ON a.application_id = ap.application_id
        JOIN cycles c ON c.cycle_id = ap.cycle_id
        JOIN kra_submissions ks ON ks.submission_id = ap.submission_id
        JOIN users f ON f.user_id = ap.faculty_id
        JOIN users ch ON ch.user_id = ap.checker_id
        WHERE ap.appeal_id=?
    ");
    $stmt->execute([$appeal_id]);
    return $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'file_appeal' && isFaculty()) {
        $sub_id = (int)($_POST['submission_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $row = $pdo->prepare("
            SELECT ks.*, a.application_id, a.user_id, a.cycle_id, c.status AS cycle_status, c.appeal_deadline
            FROM kra_submissions ks
            JOIN applications a ON a.application_id = ks.application_id
            JOIN cycles c ON c.cycle_id = a.cycle_id
            WHERE ks.submission_id=? AND a.user_id=?
        ");
        $row->execute([$sub_id, $uid]);
        $row = $row->fetch();
        $checker_id = (int)($row['revision_by'] ?: $row['verified_by'] ?: 0);
        $score_changed = isset($row['faculty_original_score']) && $row['faculty_original_score'] !== null && (float)$row['faculty_original_score'] !== (float)$row['computed_points'];
        $flagged = ($row['revision_status'] ?? '') === 'needs_revision';
        $open = $pdo->prepare("SELECT appeal_id FROM appeals WHERE submission_id=? AND status IN ('open','under_review') LIMIT 1");
        $open->execute([$sub_id]);
        if (!$row || !$reason || !$checker_id) {
            flashMessage('danger', 'Unable to file appeal for this item.');
        } elseif (!canFacultyAppeal($pdo, $row)) {
            flashMessage('danger', 'Appeals are closed for this cycle.');
        } elseif (!$score_changed && !$flagged) {
            flashMessage('danger', 'Only flagged or score-changed items can be appealed.');
        } elseif ($open->fetch()) {
            flashMessage('danger', 'This item already has an open appeal.');
        } else {
            $type = $flagged ? 'flag' : 'score_change';
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO appeals (application_id, submission_id, faculty_id, checker_id, cycle_id, appeal_type, original_score, changed_score, checker_remark, reason)
                VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$row['application_id'], $sub_id, $uid, $checker_id, $row['cycle_id'], $type, $row['faculty_original_score'], $row['computed_points'], $row['revision_note'] ?: $row['checker_note'], $reason]);
            $new_id = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO appeal_messages (appeal_id, sender_id, message) VALUES (?,?,?)")->execute([$new_id, $uid, $reason]);
            $msg_id = (int)$pdo->lastInsertId();
            appealUploadFiles($pdo, $msg_id);
            $pdo->commit();
            createNotif($pdo, $checker_id, 'appeal_opened', "New appeal filed for {$row['kra_category']}. Reason: {$reason}", (int)$row['application_id'], $sub_id);
            logAudit($pdo, $uid, 'Appeal Filed', "Appeal #{$new_id} filed for submission #{$sub_id}.");
            flashMessage('success', 'Appeal submitted.');
            echo "<script>window.location.href='index.php?page=appeals&id={$new_id}';</script>"; exit;
        }
    } elseif ($action === 'reply_appeal') {
        $appeal = fetchAppeal($pdo, (int)($_POST['appeal_id'] ?? 0));
        $message = trim($_POST['message'] ?? '');
        $allowed = $appeal && $appeal['status'] !== 'resolved'
            && ((isFaculty() && (int)$appeal['faculty_id'] === $uid) || (isChecker() && (int)$appeal['checker_id'] === $uid));
        if ($allowed && $message) {
            $side_col = isFaculty() ? 'faculty_id' : 'checker_id';
            $count = $pdo->prepare("SELECT COUNT(*) FROM appeal_messages WHERE appeal_id=? AND sender_id=?");
            $count->execute([$appeal['appeal_id'], $uid]);
            if ((int)$count->fetchColumn() >= 5) {
                flashMessage('danger', 'Message limit reached for this appeal.');
            } else {
                $pdo->prepare("INSERT INTO appeal_messages (appeal_id, sender_id, message) VALUES (?,?,?)")->execute([$appeal['appeal_id'], $uid, $message]);
                $msg_id = (int)$pdo->lastInsertId();
                appealUploadFiles($pdo, $msg_id);
                $read_sql = isFaculty() ? "is_read_by_checker=0,is_read_by_faculty=1" : "is_read_by_faculty=0,is_read_by_checker=1,status='under_review'";
                $pdo->prepare("UPDATE appeals SET {$read_sql}, updated_at=NOW() WHERE appeal_id=?")->execute([$appeal['appeal_id']]);
                $to = isFaculty() ? (int)$appeal['checker_id'] : (int)$appeal['faculty_id'];
                createNotif($pdo, $to, 'appeal_message', "New appeal reply for {$appeal['kra_category']}.", (int)$appeal['application_id'], (int)$appeal['submission_id']);
                logAudit($pdo, $uid, 'Appeal Reply', "Reply added to appeal #{$appeal['appeal_id']}.");
            }
        }
    } elseif ($action === 'resolve_appeal' && isChecker()) {
        $appeal = fetchAppeal($pdo, (int)($_POST['appeal_id'] ?? 0));
        $outcome = $_POST['outcome'] ?? '';
        $new_score = trim($_POST['new_score'] ?? '');
        $clear_flag = !empty($_POST['clear_flag']);
        if ($appeal && (int)$appeal['checker_id'] === $uid && in_array($outcome, ['upheld','revised','dismissed'], true)) {
            if ($outcome === 'revised' && $new_score !== '') {
                $pdo->prepare("UPDATE kra_submissions SET computed_points=?, verified=1, verified_by=?, verified_at=NOW(), checker_note=? WHERE submission_id=?")
                    ->execute([(float)$new_score, $uid, 'Score revised after appeal.', $appeal['submission_id']]);
                recalcApplicationScore($pdo, (int)$appeal['application_id']);
                logAudit($pdo, $uid, 'Appeal Score Revised', "Appeal #{$appeal['appeal_id']} revised score to {$new_score}.");
            }
            if ($clear_flag || $outcome === 'upheld') {
                $pdo->prepare("UPDATE kra_submissions SET revision_status='ok', revision_note=NULL, revision_by=NULL, revision_at=NULL WHERE submission_id=?")
                    ->execute([$appeal['submission_id']]);
                logAudit($pdo, $uid, 'Appeal Flag Cleared', "Appeal #{$appeal['appeal_id']} cleared revision flag.");
            }
            $pdo->prepare("UPDATE appeals SET status='resolved', outcome=?, is_read_by_faculty=0, is_read_by_checker=1, resolved_at=NOW(), resolved_by=?, updated_at=NOW() WHERE appeal_id=?")
                ->execute([$outcome, $uid, $appeal['appeal_id']]);
            createNotif($pdo, (int)$appeal['faculty_id'], 'appeal_resolved', "Your appeal for {$appeal['kra_category']} was resolved: " . ucfirst($outcome) . ".", (int)$appeal['application_id'], (int)$appeal['submission_id']);
            flashMessage('success', 'Appeal resolved.');
        }
    }
    $back_id = (int)($_POST['appeal_id'] ?? 0);
    echo "<script>window.location.href='index.php?page=appeals" . ($back_id ? "&id={$back_id}" : '') . "';</script>"; exit;
}

$where = '1=1';
$params = [];
if (isFaculty()) { $where = 'ap.faculty_id=?'; $params[] = $uid; }
elseif (isChecker()) { $where = 'ap.checker_id=?'; $params[] = $uid; }
$list_stmt = $pdo->prepare("
    SELECT ap.*, ks.kra_category, c.cycle_name,
           f.full_name AS faculty_name, f.first_name AS faculty_first, f.middle_name AS faculty_middle, f.last_name AS faculty_last,
           ch.checker_label, ch.first_name AS checker_first, ch.middle_name AS checker_middle, ch.last_name AS checker_last
    FROM appeals ap
    JOIN kra_submissions ks ON ks.submission_id=ap.submission_id
    JOIN cycles c ON c.cycle_id=ap.cycle_id
    JOIN users f ON f.user_id=ap.faculty_id
    JOIN users ch ON ch.user_id=ap.checker_id
    WHERE {$where}
    ORDER BY ap.updated_at DESC
");
$list_stmt->execute($params);
$appeals = $list_stmt->fetchAll();
$selected = $appeal_id ? fetchAppeal($pdo, $appeal_id) : ($appeals[0] ?? false);
if ($selected) {
    $can_view = isAdmin() || (isFaculty() && (int)$selected['faculty_id'] === $uid) || (isChecker() && (int)$selected['checker_id'] === $uid);
    if (!$can_view) $selected = false;
}
if ($selected) {
    if (isFaculty()) $pdo->prepare("UPDATE appeals SET is_read_by_faculty=1 WHERE appeal_id=?")->execute([$selected['appeal_id']]);
    if (isChecker()) $pdo->prepare("UPDATE appeals SET is_read_by_checker=1, status=IF(status='open','under_review',status) WHERE appeal_id=?")->execute([$selected['appeal_id']]);
    $msg_stmt = $pdo->prepare("SELECT m.*, u.role, u.full_name, u.first_name, u.middle_name, u.last_name, u.checker_label FROM appeal_messages m JOIN users u ON u.user_id=m.sender_id WHERE m.appeal_id=? ORDER BY m.created_at ASC");
    $msg_stmt->execute([$selected['appeal_id']]);
    $messages = $msg_stmt->fetchAll();
} else {
    $messages = [];
}
?>

<?php showFlash(); ?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="neon-card p-0" style="overflow:hidden;">
            <div style="padding:0.85rem 1rem;background:#1a3a6b;color:#fff;font-weight:700;font-size:0.9rem;">
                <i class="bi bi-chat-square-text me-2"></i>Appeals
            </div>
            <?php if ($appeals): foreach ($appeals as $ap): ?>
            <a href="index.php?page=appeals&id=<?= (int)$ap['appeal_id'] ?>" style="display:block;text-decoration:none;padding:0.85rem 1rem;border-bottom:1px solid #f1f5f9;background:<?= $selected && (int)$selected['appeal_id']===(int)$ap['appeal_id'] ? '#f0f4fb' : '#fff' ?>;">
                <div style="display:flex;justify-content:space-between;gap:0.5rem;">
                    <strong style="color:#1a3a6b;font-size:0.82rem;"><?= sanitize($categories[$ap['kra_category']] ?? $ap['kra_category']) ?> Appeal</strong>
                    <?php $unread = (isFaculty() && empty($ap['is_read_by_faculty'])) || (isChecker() && empty($ap['is_read_by_checker'])); ?>
                    <?php if ($unread): ?><span class="badge" style="background:#1e4d8c;">New</span><?php endif; ?>
                </div>
                <div style="font-size:0.74rem;color:#64748b;margin-top:2px;"><?= sanitize($ap['cycle_name']) ?></div>
                <div style="font-size:0.72rem;color:#94a3b8;margin-top:2px;"><?= sanitize(appealStatusLabel($ap)) ?></div>
            </a>
            <?php endforeach; else: ?>
            <div class="p-4 text-center text-muted small">No appeals yet.</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-8">
        <?php if ($selected): ?>
        <div class="neon-card mb-3">
            <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                <div>
                    <h6 style="color:#1a3a6b;font-weight:700;margin-bottom:0.25rem;"><?= sanitize($categories[$selected['kra_category']] ?? $selected['kra_category']) ?> Appeal</h6>
                    <div class="text-muted small"><?= sanitize($selected['cycle_name']) ?> | <?= sanitize(appealStatusLabel($selected)) ?></div>
                </div>
                <a href="index.php?page=<?= isFaculty() ? 'my_application' : 'review_application&id=' . (int)$selected['application_id'] ?>#kra-verification" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Open Item
                </a>
            </div>
            <div class="row g-2 mt-3" style="font-size:0.82rem;">
                <div class="col-md-6"><span class="text-muted">Faculty:</span> <strong><?= sanitize(formatDisplayName([
                    'first_name' => $selected['faculty_first'] ?? '',
                    'middle_name' => $selected['faculty_middle'] ?? '',
                    'last_name' => $selected['faculty_last'] ?? '',
                    'faculty_name' => $selected['faculty_name'] ?? ''
                ], 'faculty_name')) ?></strong></div>
                <div class="col-md-6"><span class="text-muted">Evaluator:</span> <strong><?= sanitize(checkerDisplayLabel($selected)) ?></strong></div>
                <div class="col-md-6"><span class="text-muted">Original Score:</span> <?= $selected['original_score'] !== null ? number_format((float)$selected['original_score'], 2) : 'N/A' ?></div>
                <div class="col-md-6"><span class="text-muted">Changed Score:</span> <?= $selected['changed_score'] !== null ? number_format((float)$selected['changed_score'], 2) : 'N/A' ?></div>
                <?php if (!empty($selected['checker_remark'])): ?><div class="col-12"><span class="text-muted">Evaluator Remark:</span> <?= sanitize($selected['checker_remark']) ?></div><?php endif; ?>
            </div>
        </div>

        <div class="neon-card mb-3">
            <h6 style="color:#1a3a6b;font-weight:700;font-size:0.88rem;">Thread</h6>
            <?php foreach ($messages as $m): ?>
            <div style="padding:0.75rem 0;border-bottom:1px solid #f1f5f9;">
                <div style="font-size:0.78rem;font-weight:700;color:#1e293b;"><?= sanitize($m['role']==='checker' ? checkerDisplayLabel($m) : formatDisplayName($m)) ?> <span style="font-weight:400;color:#94a3b8;"><?= date('M d, Y g:i A', strtotime($m['created_at'])) ?></span></div>
                <div style="font-size:0.84rem;color:#475569;margin-top:0.25rem;white-space:pre-wrap;"><?= sanitize($m['message']) ?></div>
                <?php
                $att = $pdo->prepare("SELECT * FROM appeal_attachments WHERE message_id=?");
                $att->execute([$m['message_id']]);
                $atts = $att->fetchAll();
                ?>
                <?php if ($atts): ?><div class="mt-2 d-flex gap-1 flex-wrap"><?php foreach ($atts as $a): ?>
                    <a href="pages/view_file.php?file=<?= urlencode($a['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-paperclip"></i><?= sanitize($a['original_filename']) ?></a>
                <?php endforeach; ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($selected['status'] !== 'resolved' && !isAdmin()): ?>
        <div class="neon-card mb-3">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="reply_appeal">
                <input type="hidden" name="appeal_id" value="<?= (int)$selected['appeal_id'] ?>">
                <label class="form-label">Reply</label>
                <textarea name="message" class="form-control" rows="3" required></textarea>
                <input type="file" name="attachments[]" class="form-control mt-2" multiple>
                <button class="btn btn-primary mt-2"><i class="bi bi-send me-1"></i>Send Reply</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($selected['status'] !== 'resolved' && isChecker() && (int)$selected['checker_id'] === $uid): ?>
        <div class="neon-card">
            <form method="POST">
                <input type="hidden" name="action" value="resolve_appeal">
                <input type="hidden" name="appeal_id" value="<?= (int)$selected['appeal_id'] ?>">
                <div class="row g-2">
                    <div class="col-md-4"><label class="form-label">Outcome</label><select name="outcome" class="form-select" required><option value="upheld">Upheld</option><option value="revised">Revised</option><option value="dismissed">Dismissed</option></select></div>
                    <div class="col-md-4"><label class="form-label">New Score</label><input type="number" step="0.01" min="0" name="new_score" class="form-control"></div>
                    <div class="col-md-4 d-flex align-items-end"><label class="form-check"><input type="checkbox" name="clear_flag" value="1" class="form-check-input"> Clear flag</label></div>
                </div>
                <button class="btn btn-primary mt-3"><i class="bi bi-check-circle me-1"></i>Resolve Appeal</button>
            </form>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="neon-card text-center py-5 text-muted">Select an appeal to view the thread.</div>
        <?php endif; ?>
    </div>
</div>
