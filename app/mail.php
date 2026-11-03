<?php
declare(strict_types=1);

const PHOTOGRAPHER_EMAIL = 'terezabradacova0@gmail.com';
const TEST_EMAIL = 'jan@rulc.eu';

function smtp_defaults(): array {
    return ['enabled' => false, 'host' => 'wes1-smtp.wedos.net', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => '', 'from_email' => '', 'from_name' => 'Teryfoto', 'photographer_email' => PHOTOGRAPHER_EMAIL, 'test_email' => TEST_EMAIL, 'test_copy' => true];
}
function smtp_key(): string {
    $path = storage_path() . '/smtp.key';
    if (!is_file($path)) {
        $file = @fopen($path, 'x');
        if ($file) { chmod($path, 0660); fwrite($file, base64_encode(random_bytes(32))); fclose($file); }
    }
    $key = base64_decode((string)@file_get_contents($path), true);
    if ($key === false || strlen($key) !== 32) throw new RuntimeException('SMTP encryption key unavailable');
    return $key;
}
function smtp_config(bool $withPassword = true): array {
    $path = storage_path() . '/smtp.json';
    $config = is_file($path) ? array_replace(smtp_defaults(), json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)) : smtp_defaults();
    $config['password'] = '';
    if ($withPassword && !empty($config['secret'])) {
        $secret = $config['secret'];
        $password = openssl_decrypt(base64_decode($secret['data'], true), 'aes-256-gcm', smtp_key(), OPENSSL_RAW_DATA, base64_decode($secret['iv'], true), base64_decode($secret['tag'], true));
        if ($password === false) throw new RuntimeException('SMTP password could not be decrypted');
        $config['password'] = $password;
    }
    return $config;
}
function smtp_ready(array $config): bool {
    return $config['host'] !== '' && $config['username'] !== '' && $config['password'] !== '' && (bool)filter_var($config['from_email'], FILTER_VALIDATE_EMAIL);
}
function save_smtp(array $data): void {
    $old = smtp_config(); $config = smtp_defaults();
    foreach (['host','username','from_email','from_name','photographer_email','test_email','encryption'] as $name) $config[$name] = trim((string)($data[$name] ?? ''));
    $config['port'] = filter_var($data['port'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range'=>1,'max_range'=>65535]]);
    $config['enabled'] = !empty($data['enabled']); $config['test_copy'] = !empty($data['test_copy']);
    // A host only, never a URL or PHPMailer multi-host expression.
    if (!$config['port'] || strlen($config['host']) > 253 || !preg_match('/\A[a-zA-Z0-9.-]+\z/', $config['host']) || !in_array($config['encryption'], ['tls','ssl'], true) || strlen($config['username']) > 254 || mb_strlen($config['from_name']) > 120 || preg_match('/[\r\n]/', $config['from_name'] . $config['username'])) throw new DomainException('Zkontrolujte SMTP server, port, šifrování a jméno odesílatele.');
    foreach (['from_email','photographer_email','test_email'] as $field) if (($config[$field] !== '' || $field === 'photographer_email') && (strlen($config[$field]) > 254 || !filter_var($config[$field], FILTER_VALIDATE_EMAIL))) throw new DomainException('Zadejte platné e-mailové adresy.');
    if ($config['test_copy'] && $config['test_email'] === '') throw new DomainException('Vyplňte e-mail pro testovací kopie, nebo je vypněte.');
    $password = (string)($data['smtp_password'] ?? '');
    if ($password === '') $password = $old['password'];
    if (!empty($data['clear_password'])) $password = '';
    if (strlen($password) > 1000) throw new DomainException('SMTP heslo je příliš dlouhé.');
    $config['password'] = $password;
    if ($config['enabled'] && !smtp_ready($config)) throw new DomainException('Pro zapnutí doplňte odesílací adresu, SMTP uživatele a heslo.');
    if ($password !== '') {
        $iv = random_bytes(12); $tag = '';
        $encrypted = openssl_encrypt($password, 'aes-256-gcm', smtp_key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) throw new RuntimeException('SMTP encryption failed');
        $config['secret'] = ['data'=>base64_encode($encrypted),'iv'=>base64_encode($iv),'tag'=>base64_encode($tag)];
    }
    unset($config['password']);
    $path = storage_path() . '/smtp.json'; $tmp = $path . '.' . bin2hex(random_bytes(6));
    if (file_put_contents($tmp, json_encode($config, JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('SMTP settings could not be saved');
    chmod($tmp, 0660);
    if (!rename($tmp, $path)) { unlink($tmp); throw new RuntimeException('SMTP settings could not be replaced'); }
}
function mail_recipients(array $config, bool $client = false): array {
    $recipients = $client ? [] : [$config['photographer_email']];
    if ($config['test_copy'] && $config['test_email'] !== '') $recipients[] = $config['test_email'];
    return array_values(array_unique($recipients));
}
function enqueue_message(?int $bookingId, string $kind, string $recipient, string $subject, string $body): void {
    query("INSERT INTO mail_outbox(request_id,event_kind,recipient,subject,body,created_at,next_attempt) VALUES(?,?,?,?,?,?,?)", [$bookingId,$kind,$recipient,$subject,$body,gmdate('c'),time()]);
}
function queue_booking_mail(int $id, string $kind): void {
    $config = smtp_config(false);
    $booking = query('SELECT r.*,s.starts_at,s.ends_at,s.location FROM requests r LEFT JOIN slots s ON s.id=r.slot_id WHERE r.id=?', [$id])->fetch();
    if (!$booking) return;
    $date = $booking['starts_at'] ? slot_label($booking) : $booking['requested_date'];
    $details = "Rezervace #$id\nJméno: {$booking['name']}\nFocení: {$booking['type']}\nTermín: $date\nČasová zóna: Praha (Europe/Prague)\n";
    if ($kind === 'new') {
        $subject = "Teryfoto — nová rezervace #$id";
        $body = "Přišla nová rezervace, která čeká na potvrzení.\n\n$details\nE-mail klienta: {$booking['email']}\nZpráva klienta:\n{$booking['note']}\n\nRezervaci vyřiďte v administraci Teryfoto.\n";
        foreach (mail_recipients($config) as $recipient) enqueue_message($id,$kind,$recipient,$subject,$body);
    } elseif (in_array($kind, ['confirmed','rejected'], true)) {
        $confirmed = $kind === 'confirmed';
        $subject = 'Teryfoto — ' . ($confirmed ? 'potvrzení' : 'zamítnutí') . " rezervace #$id";
        $body = "Dobrý den,\n\n" . ($confirmed ? 'vaše rezervace focení je potvrzená. Těším se na společné focení.' : 'vaši rezervaci bohužel nemohu potvrdit. Můžete si vybrat jiný volný termín nebo mě kontaktovat.') . "\n\n$details\nTeryfoto\n";
        foreach (array_values(array_unique([$booking['email'], ...mail_recipients($config, true)])) as $recipient) enqueue_message($id,$kind,$recipient,$subject,$body);
    }
}
function cancel_pending_decisions(int $id): void {
    query("UPDATE mail_outbox SET status='cancelled',last_error='' WHERE request_id=? AND event_kind IN ('confirmed','rejected') AND status IN ('pending','failed')", [$id]);
}
function smtp_mailer(array $config): PHPMailer\PHPMailer\PHPMailer {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP(); $mail->Host = $config['host']; $mail->Port = (int)$config['port'];
    $mail->SMTPAuth = true; $mail->Username = $config['username']; $mail->Password = $config['password'];
    $mail->SMTPSecure = $config['encryption']; $mail->SMTPAutoTLS = true;
    $mail->Timeout = 5; $mail->Timelimit = 10; $mail->SMTPDebug = 0;
    $mail->SMTPOptions = ['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]];
    $mail->CharSet = 'UTF-8'; $mail->Encoding = 'base64';
    $mail->setFrom($config['from_email'], $config['from_name']);
    $mail->addReplyTo($config['photographer_email'], 'Teryfoto');
    return $mail;
}
function send_smtp_message(array $config, array $message): void {
    $mail = smtp_mailer($config);
    $mail->addAddress($message['recipient']);
    $mail->Subject = $message['subject']; $mail->Body = $message['body'];
    // Stable ID helps recipients identify a retry of the same queued message.
    $mail->MessageID = '<teryfoto-' . hash('sha256', smtp_key() . ':' . $message['id']) . '@' . substr(strrchr($config['from_email'], '@'), 1) . '>';
    $mail->send();
}
function process_mail_queue(int $limit = 3, bool $testOnly = false): array {
    $result = ['sent'=>0,'failed'=>0];
    $config = smtp_config();
    if ((!$config['enabled'] && !$testOnly) || !smtp_ready($config)) return $result;
    $lock = fopen(storage_path() . '/mail-worker.lock', 'c');
    if (!$lock) throw new RuntimeException('Mail worker lock unavailable');
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return $result; }
    try {
        // A killed worker cannot leave a row locked forever.
        query("UPDATE mail_outbox SET status='failed',next_attempt=?,last_error='Odesílání bylo přerušeno. Zpráva čeká na nový pokus.' WHERE status='sending' AND locked_at<?", [time(),time()-300]);
        $messages = query("SELECT * FROM mail_outbox WHERE status IN ('pending','failed') AND attempts<5 AND next_attempt<=?" . ($testOnly ? " AND event_kind='test'" : '') . ' ORDER BY id LIMIT ' . max(1,min(10,$limit)), [time()])->fetchAll();
        foreach ($messages as $message) {
            $claimed = query("UPDATE mail_outbox SET status='sending',locked_at=?,attempts=attempts+1 WHERE id=? AND status IN ('pending','failed')", [time(),$message['id']]);
            if (!$claimed->rowCount()) continue;
            try {
                send_smtp_message($config, $message);
                query("UPDATE mail_outbox SET status='sent',sent_at=?,last_error='' WHERE id=?", [gmdate('c'),$message['id']]);
                $result['sent']++;
            } catch (Throwable $e) {
                // Do not persist SMTP transcripts, provider responses, passwords or message bodies in logs.
                query("UPDATE mail_outbox SET status='failed',next_attempt=?,last_error='SMTP zprávu nepřijal. Ověřte připojení, účet a adresu odesílatele; poté zkuste odeslat test.' WHERE id=?", [time()+min(3600,60*(2**(int)$message['attempts'])),$message['id']]);
                $result['failed']++;
            }
        }
    } finally { flock($lock, LOCK_UN); fclose($lock); }
    return $result;
}
function dispatch_mail_safely(): void {
    try { process_mail_queue(2); }
    catch (Throwable $e) { error_log('Teryfoto mail worker could not run; messages remain queued.'); }
}
