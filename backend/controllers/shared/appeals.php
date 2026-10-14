<?php
// Request handling and data loading for frontend/views/shared/appeals.php
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
    $dir = __DIR__ . '/../../../uploads/appeals/';
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
            && ((isFaculty() && (int)$appeal['faculty_id'] === $uid) || (isEvaluator() && (int)$appeal['checker_id'] === $uid));
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
    } elseif ($action === 'resolve_appeal' && isEvaluator()) {
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
elseif (isEvaluator()) { $where = 'ap.checker_id=?'; $params[] = $uid; }
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
    $can_view = isAdmin() || (isFaculty() && (int)$selected['faculty_id'] === $uid) || (isEvaluator() && (int)$selected['checker_id'] === $uid);
    if (!$can_view) $selected = false;
}
if ($selected) {
    if (isFaculty()) $pdo->prepare("UPDATE appeals SET is_read_by_faculty=1 WHERE appeal_id=?")->execute([$selected['appeal_id']]);
    if (isEvaluator()) $pdo->prepare("UPDATE appeals SET is_read_by_checker=1, status=IF(status='open','under_review',status) WHERE appeal_id=?")->execute([$selected['appeal_id']]);
    $msg_stmt = $pdo->prepare("SELECT m.*, u.role, u.full_name, u.first_name, u.middle_name, u.last_name, u.checker_label FROM appeal_messages m JOIN users u ON u.user_id=m.sender_id WHERE m.appeal_id=? ORDER BY m.created_at ASC");
    $msg_stmt->execute([$selected['appeal_id']]);
    $messages = $msg_stmt->fetchAll();
} else {
    $messages = [];
}
