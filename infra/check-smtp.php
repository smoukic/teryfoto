<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/app/bootstrap.php';
try {
    $config = smtp_config();
    if (!smtp_ready($config)) throw new RuntimeException('Missing configuration');
    $mail = smtp_mailer($config);
    if (!$mail->smtpConnect()) throw new RuntimeException('Connection failed');
    $mail->smtpClose();
    echo "SMTP TLS connection and authentication OK. No message sent.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "SMTP connection or authentication failed. Verify the saved settings.\n");
    exit(1);
}
