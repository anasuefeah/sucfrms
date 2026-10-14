<?php
// AJAX: submit user feedback (index.php?action=submit_feedback). Required by index.php.
defined('APP_ROOT') || exit;

// -- AJAX: submit feedback --
if (isset($_GET['action']) && $_GET['action'] === 'submit_feedback') {
    header('Content-Type: application/json');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $rating  = isset($_POST['rating']) && is_numeric($_POST['rating'])
                ? min(5, max(1, (int)$_POST['rating'])) : null;

    if ($message === '') {
        echo json_encode(['ok' => false, 'error' => 'Message is required.']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO feedback_submissions (user_id, subject, message, rating) VALUES (?,?,?,?)");
        $stmt->execute([$_SESSION['user_id'], $subject ?: null, $message, $rating]);
        logAudit($pdo, $_SESSION['user_id'], 'Feedback Submitted', "Subject: {$subject}");
        echo json_encode(['ok' => true]);
    } catch (\Exception $e) {
        echo json_encode(['ok' => false, 'error' => 'Could not save feedback.']);
    }
    exit;
}
