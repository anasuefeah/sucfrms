<?php
// Outbound email over SMTP via PHPMailer.
require_once __DIR__ . '/config.php';

/**
 * Send an HTML email.
 * Returns '' on success, otherwise the error message (callers decide how to log it).
 */
function sendMail(string $to, string $toName, string $subject, string $html, string $altBody = '', ?string $fromName = null): string {
    $src = __DIR__ . '/../../vendor/phpmailer/phpmailer/src/';
    if (!file_exists($src . 'PHPMailer.php')) return 'PHPMailer library not found.';

    require_once $src . 'Exception.php';
    require_once $src . 'SMTP.php';
    require_once $src . 'PHPMailer.php';

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(SMTP_FROM, $fromName ?? SMTP_FROM_NAME);
        $mail->addAddress($to, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $altBody;
        $mail->send();
        return '';
    } catch (\Exception $e) {
        return $e->getMessage();
    }
}
