<?php
// Request handling and data loading for frontend/views/admin/manage_users.php
if (!isAdmin()) { echo '<div class="alert alert-danger">Access denied.</div>'; return false; }
ensureKraAssignmentTable($pdo);

require_once __DIR__ . '/../../services/mail/mailer.php';

function adminRandomPassword(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@#!';
    return $chars[random_int(0, 25)] . substr(str_shuffle('abcdefghjkmnpqrstuvwxyz'), 0, 5) . random_int(10, 99) . $chars[random_int(52, strlen($chars) - 1)];
}

function adminSendTempPasswordEmail(string $to, string $name, string $pass): string {
    $err = sendMail(
        $to,
        $name,
        'Your SUCFRMS Account',
        '<p>Hello <strong>' . htmlspecialchars($name) . '</strong>,</p>'
            . '<p>Your SUCFRMS account has been created by the Administrator.</p>'
            . '<p>Temporary password: <strong style="font-family:monospace;font-size:18px;">' . htmlspecialchars($pass) . '</strong></p>'
            . '<p>You may change this password any time from your Profile settings.</p>',
        "Hello $name,\n\nYour SUCFRMS temporary password is: $pass\n\nYou may change this password any time from your Profile settings."
    );
    if ($err !== '' && $err !== 'PHPMailer library not found.') {
        error_log('SUCFRMS account email failed: ' . $err);
    }
    return $err;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_account') {
        $role = $_POST['role'] ?? '';
        $first = trim($_POST['first_name'] ?? '');
        $middle = !empty($_POST['no_middle_name']) ? '' : trim($_POST['middle_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $valid_roles = ['faculty','evaluator'];

        if (!in_array($role, $valid_roles, true) || $first === '' || $last === '' || $email === '' || $password === '') {
            flashMessage('danger', 'Please fill in all required account fields.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flashMessage('danger', 'Please enter a valid email address.');
        } elseif (strlen($password) < 8) {
            flashMessage('danger', 'Temporary password must be at least 8 characters.');
        } else {
            $dup = $pdo->prepare("SELECT user_id FROM users WHERE email=?");
            $dup->execute([$email]);
            if ($dup->fetch()) {
                flashMessage('danger', 'An account with that email already exists.');
            } else {
                $label = $role === 'evaluator' ? nextCheckerLabel($pdo) : null;
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $profile_completed = $role === 'faculty' ? 0 : 1;
                $email_locked = $role === 'faculty' ? 1 : 0;
                $stmt = $pdo->prepare("INSERT INTO users (first_name, middle_name, last_name, email, password, role, status, checker_label, profile_completed, email_locked)
                    VALUES (?,?,?,?,?,?, 'active', ?, ?, ?)");
                $stmt->execute([$first, $middle ?: null, $last, $email, $hash, $role, $label, $profile_completed, $email_locked]);
                $new_id = (int)$pdo->lastInsertId();
                $name = formatDisplayName(['first_name'=>$first,'middle_name'=>$middle,'last_name'=>$last]);
                $mail_error = adminSendTempPasswordEmail($email, $name, $password);
                $role_label = match($role) {
                    'evaluator' => 'Evaluator',
                    default => 'Faculty',
                };
                logAudit($pdo, $_SESSION['user_id'], 'Account Created', "Created {$role_label} account for {$email}.");
                if ($role === 'evaluator') {
                    $cycles_for_assignment = $pdo->query("SELECT cycle_id FROM cycles")->fetchAll(PDO::FETCH_COLUMN);
                    if ($cycles_for_assignment) {
                        $assign = $pdo->prepare("INSERT IGNORE INTO checker_kra_assignments (cycle_id, checker_id, kra_category, assigned_by) VALUES (?,?,?,?)");
                        foreach ($cycles_for_assignment as $cycle_id) {
                            foreach (array_keys(kraAssignmentCategories()) as $kra) {
                                $assign->execute([(int)$cycle_id, $new_id, $kra, $_SESSION['user_id']]);
                            }
                        }
                        logAudit($pdo, $_SESSION['user_id'], 'KRA Assignment Updated', "Granted all KRAs to new evaluator {$email} for existing cycles.");
                    }
                }
                $extra = $mail_error ? " Email failed; temporary password: <strong>" . htmlspecialchars($password) . "</strong>" : ' Temporary password was emailed.';
                flashMessage($mail_error ? 'warning' : 'success', "{$role_label} account created.{$extra}");
            }
        }
    } elseif ($action === 'set_inactive') {
        $uid = (int)$_POST['uid'];
        if ($uid !== (int)$_SESSION['user_id']) {
            $pdo->prepare("UPDATE users SET status='inactive' WHERE user_id=?")->execute([$uid]);
            logAudit($pdo, $_SESSION['user_id'], 'User Deactivated', "Set user ID {$uid} to inactive.");
            flashMessage('warning', 'User has been set to inactive.');
        }
    } elseif ($action === 'set_active') {
        $uid = (int)$_POST['uid'];
        $pdo->prepare("UPDATE users SET status='active' WHERE user_id=?")->execute([$uid]);
        logAudit($pdo, $_SESSION['user_id'], 'User Reactivated', "Reactivated user ID {$uid}.");
        flashMessage('success', 'User has been reactivated.');
    } elseif ($action === 'delete_user') {
        $uid = (int)$_POST['uid'];
        if ($uid !== (int)$_SESSION['user_id']) {
            $row = $pdo->prepare("SELECT full_name FROM users WHERE user_id=?");
            $row->execute([$uid]);
            $name = $row->fetchColumn() ?: "ID {$uid}";
            $pdo->prepare("DELETE FROM users WHERE user_id=?")->execute([$uid]);
            logAudit($pdo, $_SESSION['user_id'], 'User Deleted', "Deleted user {$name}.");
            flashMessage('success', "User <strong>" . htmlspecialchars($name) . "</strong> deleted.");
        }
    } elseif ($action === 'reset_password') {
        $uid = (int)$_POST['uid'];
        $temp = trim($_POST['temp_password'] ?? '') ?: adminRandomPassword();
        if (strlen($temp) < 8) {
            flashMessage('danger', 'Temporary password must be at least 8 characters.');
        } else {
            $row = $pdo->prepare("SELECT email, first_name, middle_name, last_name FROM users WHERE user_id=?");
            $row->execute([$uid]);
            $target = $row->fetch();
            if ($target) {
                $pdo->prepare("UPDATE users SET password=? WHERE user_id=?")->execute([password_hash($temp, PASSWORD_DEFAULT), $uid]);
                $name = formatDisplayName($target);
                $mail_error = adminSendTempPasswordEmail($target['email'], $name, $temp);
                logAudit($pdo, $_SESSION['user_id'], 'Password Reset', "Reset password for user ID {$uid}.");
                $msg = "Temporary password set for <strong>" . htmlspecialchars($name) . "</strong>: <strong>" . htmlspecialchars($temp) . "</strong>";
                if (!$mail_error) $msg .= ' and emailed to the user.';
                flashMessage($mail_error ? 'warning' : 'success', $msg);
            }
        }
    } elseif ($action === 'update_kra_assignments') {
        $cycle_id = (int)($_POST['cycle_id'] ?? 0);
        $posted = $_POST['kra_assignments'] ?? [];
        $categories = kraAssignmentCategories();
        if ($cycle_id <= 0) {
            flashMessage('danger', 'Please select a cycle for KRA assignments.');
        } else {
            $checkers = $pdo->query("SELECT user_id, checker_label, first_name, middle_name, last_name, email FROM users WHERE role='evaluator' AND status='active' ORDER BY user_id ASC")->fetchAll();
            $old_stmt = $pdo->prepare("SELECT checker_id, kra_category FROM checker_kra_assignments WHERE cycle_id=?");
            $old_stmt->execute([$cycle_id]);
            $old_map = [];
            foreach ($old_stmt->fetchAll() as $row) $old_map[(int)$row['checker_id']][] = $row['kra_category'];

            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM checker_kra_assignments WHERE cycle_id=?")->execute([$cycle_id]);
                $ins = $pdo->prepare("INSERT IGNORE INTO checker_kra_assignments (cycle_id, checker_id, kra_category, assigned_by) VALUES (?,?,?,?)");
                $changes = [];
                $removal_warnings = [];
                foreach ($checkers as $checker) {
                    $cid = (int)$checker['user_id'];
                    $new_kras = array_values(array_intersect(array_keys($categories), $posted[$cid] ?? []));
                    foreach ($new_kras as $kra) $ins->execute([$cycle_id, $cid, $kra, $_SESSION['user_id']]);
                    $old_kras = $old_map[$cid] ?? [];
                    $added = array_diff($new_kras, $old_kras);
                    $removed = array_diff($old_kras, $new_kras);
                    $label = checkerDisplayLabel($checker);
                    foreach ($added as $kra) $changes[] = "{$label}: added {$categories[$kra]}";
                    foreach ($removed as $kra) {
                        $changes[] = "{$label}: removed {$categories[$kra]}";
                        $open_stmt = $pdo->prepare("
                            SELECT COUNT(*)
                            FROM kra_submissions ks
                            JOIN applications a ON a.application_id = ks.application_id
                            WHERE a.cycle_id = ?
                              AND ks.kra_category = ?
                              AND a.status IN ('submitted','under_review','needs_revision','talisay_review')
                              AND (ks.verified_by = ? OR ks.revision_by = ?)
                        ");
                        $open_stmt->execute([$cycle_id, $kra, $cid, $cid]);
                        $open_count = (int)$open_stmt->fetchColumn();
                        if ($open_count > 0) {
                            $removal_warnings[] = "{$label} has {$open_count} open touched item(s) in {$categories[$kra]}. Please reassign or resolve them.";
                        }
                        try {
                            $replacement = $pdo->prepare("
                                SELECT checker_id
                                FROM checker_kra_assignments
                                WHERE cycle_id=? AND kra_category=? AND checker_id<>?
                                ORDER BY checker_id ASC
                                LIMIT 1
                            ");
                            $replacement->execute([$cycle_id, $kra, $cid]);
                            $new_checker = (int)($replacement->fetchColumn() ?: 0);
                            if ($new_checker) {
                                $move = $pdo->prepare("
                                    UPDATE appeals ap
                                    JOIN applications a ON a.application_id = ap.application_id
                                    JOIN kra_submissions ks ON ks.submission_id = ap.submission_id
                                    SET ap.checker_id=?, ap.is_read_by_checker=0, ap.updated_at=NOW()
                                    WHERE ap.checker_id=? AND ap.status IN ('open','under_review')
                                      AND a.cycle_id=? AND ks.kra_category=?
                                ");
                                $move->execute([$new_checker, $cid, $cycle_id, $kra]);
                                if ($move->rowCount() > 0) {
                                    logAudit($pdo, $_SESSION['user_id'], 'Appeals Reassigned', "Moved {$move->rowCount()} open appeal(s) from {$label} to evaluator ID {$new_checker} for {$categories[$kra]}.");
                                }
                            }
                        } catch (\Exception $e) {}
                    }
                }
                $pdo->commit();
                logAudit($pdo, $_SESSION['user_id'], 'KRA Assignment Updated', "Cycle #{$cycle_id}. " . ($changes ? implode('; ', $changes) : 'No changes.'));
                if ($removal_warnings) {
                    flashMessage('warning', 'KRA assignments updated. ' . htmlspecialchars(implode(' ', $removal_warnings)));
                } else {
                    flashMessage('success', 'KRA assignments updated.');
                }
            } catch (\Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flashMessage('danger', 'Unable to save KRA assignments: ' . htmlspecialchars($e->getMessage()));
            }
        }
    } elseif ($action === 'copy_kra_assignments') {
        $from_cycle = (int)($_POST['from_cycle_id'] ?? 0);
        $to_cycle = (int)($_POST['to_cycle_id'] ?? 0);
        if ($from_cycle && $to_cycle && $from_cycle !== $to_cycle) {
            $pdo->prepare("DELETE FROM checker_kra_assignments WHERE cycle_id=?")->execute([$to_cycle]);
            $copy = $pdo->prepare("INSERT IGNORE INTO checker_kra_assignments (cycle_id, checker_id, kra_category, assigned_by)
                SELECT ?, checker_id, kra_category, ? FROM checker_kra_assignments WHERE cycle_id=?");
            $copy->execute([$to_cycle, $_SESSION['user_id'], $from_cycle]);
            logAudit($pdo, $_SESSION['user_id'], 'KRA Assignment Copied', "Copied KRA assignments from cycle #{$from_cycle} to cycle #{$to_cycle}.");
            flashMessage('success', 'KRA assignment setup copied.');
        } else {
            flashMessage('danger', 'Please choose a different source cycle to copy from.');
        }
    } elseif ($action === 'update_email') {
        $uid = (int)$_POST['uid'];
        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flashMessage('danger', 'Please enter a valid email address.');
        } else {
            $dup = $pdo->prepare("SELECT user_id FROM users WHERE email=? AND user_id<>?");
            $dup->execute([$email, $uid]);
            if ($dup->fetch()) {
                flashMessage('danger', 'That email is already used by another account.');
            } else {
                $pdo->prepare("UPDATE users SET email=? WHERE user_id=?")->execute([$email, $uid]);
                logAudit($pdo, $_SESSION['user_id'], 'User Email Updated', "Updated email for user ID {$uid} to {$email}.");
                flashMessage('success', 'Email updated.');
            }
        }
    }
    echo "<script>window.location.href='index.php?page=manage_users';</script>"; exit;
}

$search = trim($_GET['search'] ?? '');
$filter = $_GET['role_filter'] ?? '';
$filter_campus = (int)($_GET['campus_id'] ?? 0);
$campuses = $pdo->query("SELECT campus_id, campus_name FROM campuses ORDER BY campus_name ASC")->fetchAll();
$kra_categories = kraAssignmentCategories();
$cycles_for_kra = $pdo->query("SELECT cycle_id, cycle_name, status FROM cycles ORDER BY created_at DESC")->fetchAll();
$assignment_cycle_id = (int)($_GET['assignment_cycle_id'] ?? 0);
if (!$assignment_cycle_id && $cycles_for_kra) {
    foreach ($cycles_for_kra as $cy) {
        if ($cy['status'] === 'open') { $assignment_cycle_id = (int)$cy['cycle_id']; break; }
    }
    if (!$assignment_cycle_id) $assignment_cycle_id = (int)$cycles_for_kra[0]['cycle_id'];
}
$active_checkers = $pdo->query("SELECT user_id, checker_label, first_name, middle_name, last_name, email, status FROM users WHERE role='evaluator' ORDER BY status DESC, user_id ASC")->fetchAll();
$assignment_map = [];
$coverage_counts = array_fill_keys(array_keys($kra_categories), 0);
if ($assignment_cycle_id) {
    $am = $pdo->prepare("SELECT checker_id, kra_category FROM checker_kra_assignments WHERE cycle_id=?");
    $am->execute([$assignment_cycle_id]);
    foreach ($am->fetchAll() as $row) {
        $assignment_map[(int)$row['checker_id']][] = $row['kra_category'];
        if (isset($coverage_counts[$row['kra_category']])) $coverage_counts[$row['kra_category']]++;
    }
}

$query = "SELECT u.*, c.campus_name,
    (SELECT COUNT(*) FROM applications a WHERE a.user_id=u.user_id AND a.status!='draft') AS app_count
    FROM users u
    LEFT JOIN campuses c ON u.campus_id=c.campus_id
    WHERE 1=1";
$params = [];
if ($search) { $query .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.employee_id LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
if ($filter) { $query .= " AND u.role=?"; $params[] = $filter; }
if ($filter_campus) { $query .= " AND u.campus_id=?"; $params[] = $filter_campus; }
$query .= " ORDER BY u.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll();
