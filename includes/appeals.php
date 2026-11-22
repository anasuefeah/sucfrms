<?php
ensureAppealTables($pdo);

$uid  = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';
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
    for ($i = 0; $i < count($_FILES[$field]['name']); $i++) {
        if (($_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        if (($_FILES[$field]['size'][$i] ?? 0) > 5 * 1024 * 1024) continue;
        $orig = basename($_FILES[$field]['name'][$i]);
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (pdfUploadError([
            'name'=>$orig, 'tmp_name'=>$_FILES[$field]['tmp_name'][$i],
            'size'=>$_FILES[$field]['size'][$i], 'error'=>$_FILES[$field]['error'][$i],
        ], 5 * 1024 * 1024) !== null) continue;
        $name = 'appeal_' . $message_id . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (move_uploaded_file($_FILES[$field]['tmp_name'][$i], $dir . $name)) {
            $pdo->prepare("INSERT INTO appeal_attachments (message_id, file_path, original_filename, file_size_bytes) VALUES (?,?,?,?)")
                ->execute([$message_id, 'uploads/appeals/' . $name, $orig, (int)$_FILES[$field]['size'][$i]]);
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
        JOIN applications a  ON a.application_id  = ap.application_id
        JOIN cycles c        ON c.cycle_id         = ap.cycle_id
        JOIN kra_submissions ks ON ks.submission_id= ap.submission_id
        JOIN users f         ON f.user_id          = ap.faculty_id
        JOIN users ch        ON ch.user_id         = ap.checker_id
        WHERE ap.appeal_id = ?
    ");
    $stmt->execute([$appeal_id]);
    return $stmt->fetch();
}

// ── POST handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['attachments']) && ($error = pdfUploadBatchError($_FILES['attachments'], 5 * 1024 * 1024))) {
        flashMessage('danger', $error);
        echo "<script>window.location.href='index.php?page=appeals';</script>"; exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'file_appeal' && isFaculty()) {
        $sub_id = (int)($_POST['submission_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $row = $pdo->prepare("
            SELECT ks.*, a.application_id, a.user_id, a.cycle_id, c.status AS cycle_status, c.appeal_deadline
            FROM kra_submissions ks
            JOIN applications a ON a.application_id = ks.application_id
            JOIN cycles c       ON c.cycle_id        = a.cycle_id
            WHERE ks.submission_id=? AND a.user_id=?
        ");
        $row->execute([$sub_id, $uid]);
        $row = $row->fetch();
        $checker_id  = (int)($row['revision_by'] ?: $row['verified_by'] ?: 0);
        $score_changed = isset($row['faculty_original_score']) && $row['faculty_original_score'] !== null
                         && (float)$row['faculty_original_score'] !== (float)$row['computed_points'];
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
                ->execute([$row['application_id'], $sub_id, $uid, $checker_id, $row['cycle_id'], $type,
                           $row['faculty_original_score'], $row['computed_points'],
                           $row['revision_note'] ?: $row['checker_note'], $reason]);
            $new_id = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO appeal_messages (appeal_id, sender_id, message) VALUES (?,?,?)")
                ->execute([$new_id, $uid, $reason]);
            $msg_id = (int)$pdo->lastInsertId();
            appealUploadFiles($pdo, $msg_id);
            $pdo->commit();
            createNotif($pdo, $checker_id, 'appeal_opened', "New appeal filed for {$row['kra_category']}. Reason: {$reason}", (int)$row['application_id'], $sub_id);
            logAudit($pdo, $uid, 'Appeal Filed', "Appeal #{$new_id} filed for submission #{$sub_id}.");
            flashMessage('success', 'Appeal submitted.');
        }
    } elseif ($action === 'reply_appeal') {
        $appeal  = fetchAppeal($pdo, (int)($_POST['appeal_id'] ?? 0));
        $message = trim($_POST['message'] ?? '');
        $allowed = $appeal && $appeal['status'] !== 'resolved'
            && ((isFaculty() && (int)$appeal['faculty_id'] === $uid)
                || (isEvaluator() && (int)$appeal['checker_id'] === $uid));
        if ($allowed && $message) {
            $count = $pdo->prepare("SELECT COUNT(*) FROM appeal_messages WHERE appeal_id=? AND sender_id=?");
            $count->execute([$appeal['appeal_id'], $uid]);
            if ((int)$count->fetchColumn() >= 5) {
                flashMessage('danger', 'Message limit reached for this appeal.');
            } else {
                $pdo->prepare("INSERT INTO appeal_messages (appeal_id, sender_id, message) VALUES (?,?,?)")
                    ->execute([$appeal['appeal_id'], $uid, $message]);
                $msg_id  = (int)$pdo->lastInsertId();
                appealUploadFiles($pdo, $msg_id);
                $read_sql = isFaculty()
                    ? "is_read_by_checker=0,is_read_by_faculty=1"
                    : "is_read_by_faculty=0,is_read_by_checker=1,status='under_review'";
                $pdo->prepare("UPDATE appeals SET {$read_sql}, updated_at=NOW() WHERE appeal_id=?")
                    ->execute([$appeal['appeal_id']]);
                $to = isFaculty() ? (int)$appeal['checker_id'] : (int)$appeal['faculty_id'];
                createNotif($pdo, $to, 'appeal_message', "New appeal reply for {$appeal['kra_category']}.", (int)$appeal['application_id'], (int)$appeal['submission_id']);
                logAudit($pdo, $uid, 'Appeal Reply', "Reply added to appeal #{$appeal['appeal_id']}.");
                flashMessage('success', 'Reply sent.');
            }
        }
    } elseif ($action === 'resolve_appeal' && isEvaluator()) {
        $appeal    = fetchAppeal($pdo, (int)($_POST['appeal_id'] ?? 0));
        $outcome   = $_POST['outcome'] ?? '';
        $new_score = trim($_POST['new_score'] ?? '');
        $clear_flag= !empty($_POST['clear_flag']);
        if ($appeal && (int)$appeal['checker_id'] === $uid && in_array($outcome, ['upheld','revised','dismissed'], true)) {
            if ($outcome === 'revised' && $new_score !== '') {
                $pdo->prepare("UPDATE kra_submissions SET computed_points=?, verified=1, verified_by=?, verified_at=NOW(), checker_note='Score revised after appeal.' WHERE submission_id=?")
                    ->execute([(float)$new_score, $uid, $appeal['submission_id']]);
                recalcApplicationScore($pdo, (int)$appeal['application_id']);
                logAudit($pdo, $uid, 'Appeal Score Revised', "Appeal #{$appeal['appeal_id']} revised score to {$new_score}.");
            }
            if ($clear_flag || $outcome === 'upheld') {
                KraRevisionWorkflow::clearFlag($pdo, (int)$appeal['application_id'], (int)$appeal['submission_id']);
            }
            $pdo->prepare("UPDATE appeals SET status='resolved', outcome=?, is_read_by_faculty=0, is_read_by_checker=1, resolved_at=NOW(), resolved_by=?, updated_at=NOW() WHERE appeal_id=?")
                ->execute([$outcome, $uid, $appeal['appeal_id']]);
            createNotif($pdo, (int)$appeal['faculty_id'], 'appeal_resolved',
                "Your appeal for {$appeal['kra_category']} was resolved: " . ucfirst($outcome) . ".",
                (int)$appeal['application_id'], (int)$appeal['submission_id']);
            flashMessage('success', 'Appeal resolved.');
        }
    }
    echo "<script>window.location.href='index.php?page=appeals';</script>"; exit;
}

// ── Fetch appeals list ─────────────────────────────────────────────────────
$where  = '1=1'; $params = [];
if (isFaculty())   { $where = 'ap.faculty_id=?';  $params[] = $uid; }
elseif (isEvaluator()) { $where = 'ap.checker_id=?'; $params[] = $uid; }

$list_stmt = $pdo->prepare("
    SELECT ap.*, ks.kra_category, c.cycle_name,
           f.full_name AS faculty_name, f.first_name AS faculty_first, f.middle_name AS faculty_middle, f.last_name AS faculty_last,
           ch.checker_label, ch.first_name AS checker_first, ch.middle_name AS checker_middle, ch.last_name AS checker_last
    FROM appeals ap
    JOIN kra_submissions ks ON ks.submission_id = ap.submission_id
    JOIN cycles c           ON c.cycle_id        = ap.cycle_id
    JOIN users f            ON f.user_id          = ap.faculty_id
    JOIN users ch           ON ch.user_id         = ap.checker_id
    WHERE {$where}
    ORDER BY ap.updated_at DESC
");
$list_stmt->execute($params);
$appeals = $list_stmt->fetchAll();

// Pre-load messages for all appeals so the modal can render them
$appeal_messages = [];
$appeal_atts     = [];
foreach ($appeals as $ap) {
    $aid = (int)$ap['appeal_id'];
    // Mark as read
    if (isFaculty())   $pdo->prepare("UPDATE appeals SET is_read_by_faculty=1 WHERE appeal_id=?")->execute([$aid]);
    if (isEvaluator()) $pdo->prepare("UPDATE appeals SET is_read_by_checker=1, status=IF(status='open','under_review',status) WHERE appeal_id=?")->execute([$aid]);
    // Messages
    $ms = $pdo->prepare("SELECT m.*, u.role, u.full_name, u.first_name, u.middle_name, u.last_name, u.checker_label
        FROM appeal_messages m JOIN users u ON u.user_id=m.sender_id WHERE m.appeal_id=? ORDER BY m.created_at ASC");
    $ms->execute([$aid]);
    $msgs = $ms->fetchAll();
    // Attachments per message
    foreach ($msgs as &$m) {
        $att = $pdo->prepare("SELECT * FROM appeal_attachments WHERE message_id=?");
        $att->execute([$m['message_id']]);
        $m['attachments'] = $att->fetchAll();
    }
    unset($m);
    $appeal_messages[$aid] = $msgs;
}
?>

<?php showFlash(); ?>

<!-- ── Page header ────────────────────────────────────────── -->
<?php
// Quick stats for the header
$total_appeals  = count($appeals);
$open_count     = count(array_filter($appeals, fn($a) => in_array($a['status'], ['open','under_review'])));
$resolved_count = count(array_filter($appeals, fn($a) => $a['status'] === 'resolved'));
$unread_count   = count(array_filter($appeals, fn($a) =>
    (isFaculty()   && empty($a['is_read_by_faculty'])) ||
    (isEvaluator() && empty($a['is_read_by_checker']))
));
?>
<div style="background:#fff;border:1px solid #e2e8f0;padding:0.85rem 1.25rem;margin-bottom:1rem;
            display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
    <!-- Left: icon + title -->
    <div style="display:flex;align-items:center;gap:0.75rem;">
        <div style="width:38px;height:38px;background:#1a3a6b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi bi-chat-square-text" style="color:#fff;font-size:1rem;"></i>
        </div>
        <div>
            <div style="font-size:0.95rem;font-weight:700;color:#0f172a;line-height:1.2;">Appeals</div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:2px;">
                <?= isFaculty() ? 'Disputes you have filed' : (isEvaluator() ? 'Appeals assigned to you' : 'All appeals') ?>
            </div>
        </div>
    </div>

    <!-- Right: stat pills -->
    <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:0.35rem;padding:0.3rem 0.75rem;border:1px solid #e2e8f0;background:#f8fafc;">
            <span style="font-size:0.62rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.06em;">Total</span>
            <span style="font-size:0.85rem;font-weight:800;color:#1a3a6b;"><?= $total_appeals ?></span>
        </div>
        <?php if ($open_count > 0): ?>
        <div style="display:flex;align-items:center;gap:0.35rem;padding:0.3rem 0.75rem;border:1px solid #fde68a;background:#fffbeb;">
            <span style="width:6px;height:6px;border-radius:50%;background:#d97706;flex-shrink:0;"></span>
            <span style="font-size:0.62rem;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:0.06em;">Open</span>
            <span style="font-size:0.85rem;font-weight:800;color:#d97706;"><?= $open_count ?></span>
        </div>
        <?php endif; ?>
        <?php if ($resolved_count > 0): ?>
        <div style="display:flex;align-items:center;gap:0.35rem;padding:0.3rem 0.75rem;border:1px solid #bbf7d0;background:#f0fdf4;">
            <span style="font-size:0.62rem;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:0.06em;">Resolved</span>
            <span style="font-size:0.85rem;font-weight:800;color:#16a34a;"><?= $resolved_count ?></span>
        </div>
        <?php endif; ?>
        <?php if ($unread_count > 0): ?>
        <div style="display:flex;align-items:center;gap:0.35rem;padding:0.3rem 0.75rem;border:1px solid #bfdbfe;background:#eff6ff;">
            <span style="width:6px;height:6px;border-radius:50%;background:#1e4d8c;animation:dotPulse 1.8s ease-in-out infinite;flex-shrink:0;"></span>
            <span style="font-size:0.62rem;font-weight:700;color:#1e4d8c;text-transform:uppercase;letter-spacing:0.06em;">Unread</span>
            <span style="font-size:0.85rem;font-weight:800;color:#1e4d8c;"><?= $unread_count ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>
<style>
@keyframes dotPulse {
    0%,100%{opacity:1;transform:scale(1)} 50%{opacity:0.4;transform:scale(1.4)}
}
</style>

<!-- ── Appeals table ──────────────────────────────────────── -->
<?php if ($appeals): ?>
<div style="border:1px solid #e2e8f0;background:#fff;overflow:hidden;">
    <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
        <thead>
        <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
            <th style="padding:0.55rem 1rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">Appeal</th>
            <?php if (isAdmin()): ?>
            <th style="padding:0.55rem 0.75rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">Faculty</th>
            <?php endif; ?>
            <th style="padding:0.55rem 0.75rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">Cycle</th>
            <th style="padding:0.55rem 0.75rem;text-align:center;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">Status</th>
            <th style="padding:0.55rem 0.75rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;">Updated</th>
            <th style="padding:0.55rem 0.75rem;text-align:center;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#94a3b8;width:90px;"></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($appeals as $ap):
            $aid     = (int)$ap['appeal_id'];
            $unread  = (isFaculty() && empty($ap['is_read_by_faculty']))
                    || (isEvaluator() && empty($ap['is_read_by_checker']));
            $status  = $ap['status'] ?? 'open';
            $outcome = $ap['outcome'] ?? null;
            // Status badge
            if ($status === 'resolved') {
                $s_bg = '#f0fdf4'; $s_col = '#16a34a'; $s_label = 'Resolved · ' . ucfirst($outcome ?? '');
            } elseif ($status === 'under_review') {
                $s_bg = '#eff6ff'; $s_col = '#1e4d8c'; $s_label = 'Under Review';
            } else {
                $s_bg = '#fffbeb'; $s_col = '#d97706'; $s_label = 'Open';
            }
            $fac_name = formatDisplayName(['first_name'=>$ap['faculty_first']??'','middle_name'=>$ap['faculty_middle']??'','last_name'=>$ap['faculty_last']??'','faculty_name'=>$ap['faculty_name']??''], 'faculty_name');
            $msgs     = $appeal_messages[$aid] ?? [];
            $msg_count = count($msgs);
        ?>
        <tr style="border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">

            <!-- Appeal title -->
            <td style="padding:0.75rem 1rem;vertical-align:middle;">
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    <?php if ($unread): ?>
                    <span style="width:7px;height:7px;border-radius:50%;background:#1a3a6b;flex-shrink:0;"></span>
                    <?php endif; ?>
                    <div>
                        <div style="font-weight:600;color:#1e293b;font-size:0.82rem;">
                            <?= sanitize($categories[$ap['kra_category']] ?? $ap['kra_category']) ?> Appeal
                        </div>
                        <div style="font-size:0.68rem;color:#94a3b8;margin-top:1px;">
                            <?= $msg_count ?> message<?= $msg_count!==1?'s':'' ?>
                            <?php if ($ap['appeal_type'] === 'flag'): ?>
                            · <span style="color:#d97706;">Flag dispute</span>
                            <?php else: ?>
                            · <span style="color:#475569;">Score dispute</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </td>

            <?php if (isAdmin()): ?>
            <td style="padding:0.75rem 0.75rem;vertical-align:middle;font-size:0.8rem;color:#475569;"><?= sanitize($fac_name) ?></td>
            <?php endif; ?>

            <!-- Cycle -->
            <td style="padding:0.75rem 0.75rem;vertical-align:middle;font-size:0.78rem;color:#64748b;max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= sanitize($ap['cycle_name']) ?>"><?= sanitize($ap['cycle_name']) ?></td>

            <!-- Status -->
            <td style="padding:0.75rem 0.75rem;text-align:center;vertical-align:middle;">
                <span style="background:<?= $s_bg ?>;color:<?= $s_col ?>;border:1px solid <?= $s_col ?>30;font-size:0.65rem;font-weight:700;padding:2px 8px;">
                    <?= $s_label ?>
                </span>
            </td>

            <!-- Updated -->
            <td style="padding:0.75rem 0.75rem;vertical-align:middle;font-size:0.72rem;color:#94a3b8;white-space:nowrap;">
                <?= date('M d, Y', strtotime($ap['updated_at'])) ?>
            </td>

            <!-- Action -->
            <td style="padding:0.75rem 0.75rem;text-align:center;vertical-align:middle;">
                <button type="button"
                        onclick="openAppealModal(<?= $aid ?>)"
                        style="display:inline-flex;align-items:center;gap:4px;padding:0.32rem 0.75rem;
                               border:1px solid #1a3a6b;background:#fff;color:#1a3a6b;
                               font-size:0.72rem;font-weight:600;cursor:pointer;white-space:nowrap;"
                        onmouseover="this.style.background='#1a3a6b';this.style.color='#fff'"
                        onmouseout="this.style.background='#fff';this.style.color='#1a3a6b'">
                    <i class="bi bi-chat-text" style="font-size:0.72rem;"></i> View
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php else: ?>
<div style="border:1px solid #e2e8f0;background:#fff;padding:3rem 1rem;text-align:center;color:#94a3b8;">
    <i class="bi bi-chat-square-text" style="font-size:1.75rem;display:block;margin-bottom:0.5rem;color:#e2e8f0;"></i>
    <div style="font-size:0.82rem;font-weight:600;color:#64748b;">No appeals yet</div>
    <div style="font-size:0.72rem;margin-top:3px;">Appeals filed from your application will appear here.</div>
</div>
<?php endif; ?>

<!-- ── Appeal detail modal ────────────────────────────────── -->
<div id="appealModal"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);
            z-index:99990;align-items:flex-start;justify-content:center;padding:2rem 1rem;overflow-y:auto;">
    <div style="background:#fff;width:100%;max-width:680px;border:1px solid #e2e8f0;
                box-shadow:0 20px 60px rgba(0,0,0,0.2);margin:auto;">

        <!-- Modal header -->
        <div style="display:flex;align-items:center;justify-content:space-between;
                    padding:0.85rem 1.25rem;background:#1a3a6b;flex-shrink:0;">
            <div>
                <div id="modalTitle" style="font-weight:700;font-size:0.88rem;color:#fff;"></div>
                <div id="modalSubtitle" style="font-size:0.68rem;color:rgba(255,255,255,0.6);margin-top:2px;"></div>
            </div>
            <button onclick="closeAppealModal()"
                    style="background:none;border:none;color:rgba(255,255,255,0.7);cursor:pointer;font-size:1.2rem;line-height:1;padding:0;"
                    onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.7)'">&times;</button>
        </div>

        <!-- Info grid -->
        <div id="modalInfo" style="display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid #e2e8f0;"></div>

        <!-- Thread -->
        <div style="padding:0.75rem 1.25rem;border-bottom:1px solid #e2e8f0;">
            <div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;margin-bottom:0.6rem;">Thread</div>
            <div id="modalThread" style="display:flex;flex-direction:column;gap:0.6rem;max-height:300px;overflow-y:auto;"></div>
        </div>

        <!-- Reply + Resolve forms -->
        <div id="modalForms" style="padding:0.85rem 1.25rem;display:flex;flex-direction:column;gap:0.75rem;"></div>
    </div>
</div>

<!-- Serialise all appeal data for JS -->
<script>
var _appeals = <?php
    $js_data = [];
    foreach ($appeals as $ap) {
        $aid  = (int)$ap['appeal_id'];
        $msgs = $appeal_messages[$aid] ?? [];
        $fac_name = formatDisplayName(['first_name'=>$ap['faculty_first']??'','middle_name'=>$ap['faculty_middle']??'','last_name'=>$ap['faculty_last']??'','faculty_name'=>$ap['faculty_name']??''], 'faculty_name');
        $js_msgs = [];
        foreach ($msgs as $m) {
            $is_ev   = $m['role'] === 'evaluator';
            $s_name  = $is_ev ? htmlspecialchars(checkerDisplayLabel($m)) : htmlspecialchars(formatDisplayName($m));
            $atts = [];
            foreach ($m['attachments'] as $a) {
                $atts[] = ['path' => $a['file_path'], 'name' => substr($a['original_filename'], 0, 28) . (strlen($a['original_filename'])>28?'…':'')];
            }
            $js_msgs[] = [
                'sender'   => $s_name,
                'is_me'    => (int)$m['sender_id'] === $uid,
                'message'  => htmlspecialchars($m['message']),
                'time'     => date('M d, Y · g:i A', strtotime($m['created_at'])),
                'atts'     => $atts,
            ];
        }
        $js_data[$aid] = [
            'id'         => $aid,
            'title'      => htmlspecialchars($GLOBALS['categories'][$ap['kra_category']] ?? $ap['kra_category']) . ' Appeal',
            'subtitle'   => htmlspecialchars($ap['cycle_name']) . ' · ' . htmlspecialchars(appealStatusLabel($ap)),
            'faculty'    => htmlspecialchars($fac_name),
            'evaluator'  => htmlspecialchars(checkerDisplayLabel($ap)),
            'orig_score' => $ap['original_score'] !== null ? number_format((float)$ap['original_score'],2) : '—',
            'chg_score'  => $ap['changed_score']  !== null ? number_format((float)$ap['changed_score'],2)  : '—',
            'remark'     => htmlspecialchars($ap['checker_remark'] ?? ''),
            'status'     => $ap['status'] ?? 'open',
            'outcome'    => $ap['outcome'] ?? '',
            'open_link'  => 'index.php?page=' . (isFaculty() ? 'my_application' : 'review_application&id=' . (int)$ap['application_id']) . '#kra-verification',
            'can_reply'  => ($ap['status'] !== 'resolved' && !isAdmin()) ? 1 : 0,
            'can_resolve'=> (isEvaluator() && (int)$ap['checker_id'] === $uid && $ap['status'] !== 'resolved') ? 1 : 0,
            'messages'   => $js_msgs,
        ];
    }
    echo json_encode($js_data);
?>;

function openAppealModal(id) {
    var d = _appeals[id];
    if (!d) return;

    document.getElementById('modalTitle').innerHTML    = d.title;
    document.getElementById('modalSubtitle').innerHTML = d.subtitle;

    // Info grid
    var cells = [
        ['Faculty', d.faculty],
        ['Evaluator', d.evaluator],
        ['Original Score', d.orig_score],
        ['Changed Score', '<span style="color:#1a3a6b;font-weight:700;">'+d.chg_score+'</span>'],
    ];
    if (d.remark) cells.push(['Evaluator Remark', '<em style="color:#475569;">'+d.remark+'</em>']);
    var info_html = cells.map(function(c, i) {
        var full = (d.remark && i === 4);
        return '<div style="padding:0.6rem 1.25rem;border-bottom:1px solid #f1f5f9;'
            + (full ? 'grid-column:1/-1;' : (i%2===0 ? 'border-right:1px solid #f1f5f9;' : '')) + '">'
            + '<div style="font-size:0.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;margin-bottom:2px;">' + c[0] + '</div>'
            + '<div style="font-size:0.8rem;font-weight:600;color:#1e293b;">' + c[1] + '</div>'
            + '</div>';
    }).join('');
    // Add Open Item link
    info_html += '<div style="grid-column:1/-1;padding:0.5rem 1.25rem;border-bottom:1px solid #f1f5f9;display:flex;justify-content:flex-end;">'
        + '<a href="'+d.open_link+'" target="_blank" style="font-size:0.72rem;font-weight:600;color:#1a3a6b;text-decoration:none;display:inline-flex;align-items:center;gap:3px;border:1px solid #e2e8f0;padding:2px 10px;background:#f8fafc;">'
        + '<i class="bi bi-box-arrow-up-right" style="font-size:0.65rem;"></i> Open Item</a></div>';
    document.getElementById('modalInfo').innerHTML = info_html;

    // Thread
    var thread_html = '';
    if (d.messages.length === 0) {
        thread_html = '<div style="font-size:0.78rem;color:#94a3b8;text-align:center;padding:1rem 0;">No messages yet.</div>';
    } else {
        d.messages.forEach(function(m) {
            var init = m.sender.trim().charAt(0).toUpperCase();
            var att_html = '';
            if (m.atts && m.atts.length) {
                att_html = '<div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:5px;">';
                m.atts.forEach(function(a) {
                    att_html += '<a href="pages/view_file.php?file='+encodeURIComponent(a.path)+'" target="_blank" '
                        + 'style="font-size:0.68rem;padding:2px 7px;border:1px solid #e2e8f0;background:#fff;color:#1a3a6b;text-decoration:none;">'
                        + '<i class="bi bi-paperclip"></i> '+a.name+'</a>';
                });
                att_html += '</div>';
            }
            thread_html += '<div style="display:flex;align-items:flex-start;gap:0.6rem;">'
                + '<div class="account-avatar" style="width:30px;height:30px;border-radius:50%;background:'+(m.is_me?'#1a3a6b':'#475569')+';flex-shrink:0;'
                + 'display:flex;align-items:center;justify-content:center;color:#fff;font-size:0.62rem;font-weight:700;margin-top:1px;">'
                + init + '</div>'
                + '<div style="flex:1;min-width:0;">'
                + '<div style="font-size:0.75rem;font-weight:700;color:#1e293b;">' + m.sender + '</div>'
                + '<div style="font-size:0.79rem;color:#475569;line-height:1.55;margin-top:2px;white-space:pre-wrap;word-break:break-word;">' + m.message + '</div>'
                + att_html
                + '<div style="text-align:right;font-size:0.6rem;color:#94a3b8;margin-top:3px;">' + m.time + '</div>'
                + '</div></div>';
        });
    }
    document.getElementById('modalThread').innerHTML = thread_html;

    // Forms
    var forms_html = '';
    if (d.can_reply) {
        forms_html += '<div>'
            + '<div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:0.4rem;">Reply</div>'
            + '<form method="POST" enctype="multipart/form-data">'
            + '<input type="hidden" name="action" value="reply_appeal">'
            + '<input type="hidden" name="appeal_id" value="'+id+'">'
            + '<textarea name="message" rows="3" required placeholder="Write your reply..." '
            + 'style="width:100%;border:1px solid #e2e8f0;padding:0.5rem 0.65rem;font-size:0.8rem;resize:vertical;font-family:inherit;box-sizing:border-box;">'
            + '</textarea>'
            + '<div style="display:flex;align-items:center;justify-content:space-between;margin-top:0.5rem;flex-wrap:wrap;gap:0.4rem;">'
            + '<input type="file" name="attachments[]" accept=".pdf,application/pdf" multiple style="font-size:0.72rem;color:#64748b;">'
            + '<button type="submit" style="padding:0.4rem 1.1rem;background:#1a3a6b;color:#fff;border:none;font-size:0.78rem;font-weight:600;cursor:pointer;">Send Reply</button>'
            + '</div></form></div>';
    }
    if (d.can_resolve) {
        forms_html += '<div style="border-top:1px solid #f1f5f9;padding-top:0.75rem;">'
            + '<div style="font-size:0.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:0.5rem;">Resolve Appeal</div>'
            + '<form method="POST">'
            + '<input type="hidden" name="action" value="resolve_appeal">'
            + '<input type="hidden" name="appeal_id" value="'+id+'">'
            + '<div style="display:flex;flex-wrap:wrap;gap:0.6rem;align-items:flex-end;">'
            + '<div><div style="font-size:0.68rem;color:#475569;font-weight:600;margin-bottom:3px;">Outcome</div>'
            + '<select name="outcome" required style="border:1px solid #e2e8f0;padding:0.38rem 0.6rem;font-size:0.78rem;">'
            + '<option value="upheld">Upheld</option><option value="revised">Revised</option><option value="dismissed">Dismissed</option>'
            + '</select></div>'
            + '<div><div style="font-size:0.68rem;color:#475569;font-weight:600;margin-bottom:3px;">New Score <span style="color:#94a3b8;font-weight:400;">(if revised)</span></div>'
            + '<input type="number" name="new_score" step="0.01" min="0" style="border:1px solid #e2e8f0;padding:0.38rem 0.6rem;font-size:0.78rem;width:110px;"></div>'
            + '<label style="font-size:0.78rem;color:#475569;display:flex;align-items:center;gap:4px;cursor:pointer;padding-bottom:2px;">'
            + '<input type="checkbox" name="clear_flag" value="1"> Clear flag</label>'
            + '<button type="submit" style="padding:0.4rem 1.1rem;background:#1a3a6b;color:#fff;border:none;font-size:0.78rem;font-weight:600;cursor:pointer;margin-left:auto;">Resolve</button>'
            + '</div></form></div>';
    }
    if (!forms_html) {
        forms_html = '<div style="font-size:0.75rem;color:#94a3b8;text-align:center;padding:0.25rem 0;">This appeal is resolved.</div>';
    }
    document.getElementById('modalForms').innerHTML = forms_html;

    document.getElementById('appealModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeAppealModal() {
    document.getElementById('appealModal').style.display = 'none';
    document.body.style.overflow = '';
}

// Click outside to close
document.getElementById('appealModal').addEventListener('click', function(e) {
    if (e.target === this) closeAppealModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeAppealModal();
});
</script>
