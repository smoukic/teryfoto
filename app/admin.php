<?php
declare(strict_types=1);
header('X-Robots-Tag: noindex, nofollow');
$config = admin_config();
$loggedIn = authenticated($config);
$error = '';
$notice = take_flash();
$section = (string)($_GET['section'] ?? 'reservations');
if (!in_array($section,['reservations','slots','packages','smtp','account'],true)) $section = 'reservations';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!valid_csrf()) { http_response_code(400); throw new DomainException('Platnost formuláře vypršela. Obnovte stránku.'); }
        $action = (string)($_POST['action'] ?? '');
        if (in_array($action,['save_smtp','test_smtp','retry_mail','cancel_mail'],true)) $section='smtp';
        if (in_array($action,['save_slot','toggle_slot'],true)) $section='slots';
        if ($action==='save_package') $section='packages';
        if ($action==='password') $section='account';
        if ($action === 'login') {
            // Global and direct peer limits are database-backed, not reset by a new browser session.
            $buckets = ['global', hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown')];
            $ok = atomic(function () use ($config, $buckets): bool {
                foreach ($buckets as $bucket) {
                    $limit = query('SELECT * FROM login_limits WHERE bucket=?', [$bucket])->fetch();
                    if ($limit && $limit['reset_at'] > time() && $limit['failures'] >= 10) { http_response_code(429); throw new DomainException('Příliš mnoho pokusů. Přihlášení zkuste za 15 minut.'); }
                }
                $valid = $config && hash_equals($config['username'], (string)($_POST['username'] ?? '')) && password_verify((string)($_POST['password'] ?? ''), $config['password_hash']);
                foreach ($buckets as $bucket) {
                    if ($valid) query('DELETE FROM login_limits WHERE bucket=?', [$bucket]);
                    else query('INSERT INTO login_limits(bucket,failures,reset_at) VALUES(?,1,?) ON CONFLICT(bucket) DO UPDATE SET failures=CASE WHEN reset_at<=? THEN 1 ELSE failures+1 END,reset_at=CASE WHEN reset_at<=? THEN excluded.reset_at ELSE reset_at END', [$bucket,time()+900,time(),time()]);
                }
                return $valid;
            });
            if (!$ok) { http_response_code(401); throw new DomainException('Nesprávné přihlašovací údaje.'); }
            session_regenerate_id(true);
            $_SESSION['admin_version'] = $config['version']; $_SESSION['admin_seen'] = time(); $_SESSION['csrf'] = bin2hex(random_bytes(24));
            redirect('/?admin=1');
        }
        if (!$loggedIn) { http_response_code(401); throw new DomainException('Nejprve se přihlaste.'); }
        switch ($action) {
            case 'logout':
                $_SESSION = []; session_regenerate_id(true); redirect('/?admin=1');
            case 'save_package':
                save_package($_POST); flash('Balíček je uložený.'); break;
            case 'quick_status':
                $request = query('SELECT admin_note FROM requests WHERE id=?',[(int)($_POST['request_id'] ?? 0)])->fetch();
                $state = (string)($_POST['status'] ?? '');
                if (!in_array($state,['confirmed','rejected'],true)) throw new DomainException('Neplatná akce.');
                set_booking_status((int)($_POST['request_id'] ?? 0),$state,$request['admin_note'] ?? ''); dispatch_mail_safely(); flash('Rezervace je vyřízená a zpráva pro klienta připravená k odeslání.'); break;
            case 'save_slot':
                save_slot($_POST); flash('Termín je uložený.'); break;
            case 'toggle_slot':
                atomic(function (): void {
                    $id = (int)($_POST['slot_id'] ?? 0);
                    $slot = query('SELECT * FROM slots WHERE id=?', [$id])->fetch();
                    if (!$slot) throw new DomainException('Termín neexistuje.');
                    if (query("SELECT id FROM requests WHERE slot_id=? AND status IN ('pending','confirmed')", [$id])->fetch()) throw new DomainException('Obsazený termín nelze uzavřít. Nejprve vyřiďte rezervaci.');
                    query('UPDATE slots SET is_open=1-is_open WHERE id=?', [$id]);
                }); flash('Dostupnost termínu je změněná.'); break;
            case 'status':
                set_booking_status((int)($_POST['request_id'] ?? 0), (string)($_POST['status'] ?? ''), (string)($_POST['admin_note'] ?? '')); dispatch_mail_safely(); flash('Rezervace je aktualizovaná. Při novém potvrzení nebo zamítnutí je zpráva pro klienta ve frontě e-mailů.'); break;
            case 'assign':
                assign_booking((int)($_POST['request_id'] ?? 0), (int)($_POST['slot_id'] ?? 0)); flash('Termín je přiřazený. Rezervace čeká na potvrzení.'); break;
            case 'save_smtp':
                save_smtp($_POST); flash('SMTP nastavení je uložené. Připojení ověříte tlačítkem Odeslat testovací e-maily.'); redirect('/?admin=1&section=smtp#emaily');
            case 'test_smtp':
                $smtp = smtp_config();
                if (!smtp_ready($smtp)) throw new DomainException('Nejprve uložte SMTP uživatele, heslo a adresu odesílatele.');
                atomic(function () use ($smtp): void {
                    foreach (mail_recipients($smtp) as $recipient) enqueue_message(null,'test',$recipient,'Teryfoto — test odesílání e-mailů',"Toto je test nastavení SMTP v administraci Teryfoto.\nPokud zpráva dorazila, server přijal testovací e-mail.\n");
                });
                $result = process_mail_queue(4,true);
                flash('Testovací zprávy: přijato SMTP ' . $result['sent'] . ', neúspěšných ' . $result['failed'] . '. Podrobnosti najdete v přehledu e-mailů.'); redirect('/?admin=1&section=smtp#emaily');
            case 'retry_mail':
                $smtp = smtp_config();
                $mailId = (int)($_POST['mail_id'] ?? 0);
                $mail = query('SELECT * FROM mail_outbox WHERE id=?', [$mailId])->fetch();
                if (!$mail || !in_array($mail['status'], ['pending','failed'], true)) throw new DomainException('Tuto zprávu nelze opakovat.');
                if (!smtp_ready($smtp) || (!$smtp['enabled'] && $mail['event_kind'] !== 'test')) throw new DomainException('Nejprve nastavte a zapněte odesílání e-mailů.');
                query("UPDATE mail_outbox SET status='pending',attempts=0,next_attempt=?,last_error='' WHERE id=? AND status IN ('pending','failed')", [time(),$mailId]);
                process_mail_queue(4,!$smtp['enabled']); flash('Požadavek na opakování je zpracovaný. Zkontrolujte stav zprávy.'); redirect('/?admin=1&section=smtp#emaily');
            case 'cancel_mail':
                query("UPDATE mail_outbox SET status='cancelled' WHERE id=? AND status IN ('pending','failed')", [(int)($_POST['mail_id'] ?? 0)]); flash('Neodeslaná zpráva je zrušená.'); redirect('/?admin=1&section=smtp#emaily');
            case 'password':
                $password = (string)($_POST['new_password'] ?? '');
                if (!password_verify((string)($_POST['current_password'] ?? ''), $config['password_hash'])) throw new DomainException('Současné heslo není správné.');
                if (strlen($password) < 12 || strlen($password) > 72 || $password !== (string)($_POST['repeat_password'] ?? '')) throw new DomainException('Nové heslo musí mít 12 až 72 bajtů a oba zápisy musí být stejné.');
                $config['password_hash'] = password_hash($password, PASSWORD_DEFAULT); $config['version'] = bin2hex(random_bytes(16));
                save_admin($config); session_regenerate_id(true); $_SESSION['admin_version'] = $config['version']; $_SESSION['csrf'] = bin2hex(random_bytes(24));
                flash('Heslo je změněné. Ostatní přihlášení byla odhlášena.'); break;
            default: throw new DomainException('Neznámá akce.');
        }
        redirect('/?admin=1&section=' . $section);
    } catch (DomainException $e) { $error = $e->getMessage(); }
    catch (Throwable $e) { error_log('Admin failure: ' . $e->getMessage()); http_response_code(503); $error = 'Změnu nelze uložit. Zkuste to znovu.'; }
}
$filter = (string)($_GET['status'] ?? 'pending');
if ($filter !== 'all' && !isset(STATES[$filter])) $filter = 'pending';
$bookings = []; $slots = []; $freeSlots = []; $counts = [];
if ($loggedIn) {
    $counts = query('SELECT status,COUNT(*) AS total FROM requests GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $bookings = query('SELECT r.*,s.starts_at,s.ends_at,s.location FROM requests r LEFT JOIN slots s ON s.id=r.slot_id' . ($filter === 'all' ? '' : ' WHERE r.status=?') . ' ORDER BY r.created_at DESC,r.id DESC', $filter === 'all' ? [] : [$filter])->fetchAll();
    $slots = query("SELECT s.*,r.id AS booking_id,r.name AS booking_name,r.status AS booking_status FROM slots s LEFT JOIN requests r ON r.slot_id=s.id AND r.status IN ('pending','confirmed') ORDER BY s.starts_at DESC")->fetchAll();
    $freeSlots = available_slots();
    $allPackages = packages();
    $smtp = smtp_config(false);
    $mailCounts = query('SELECT status,COUNT(*) AS total FROM mail_outbox GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $mailLog = query('SELECT id,request_id,event_kind,recipient,subject,status,attempts,created_at,sent_at,last_error FROM mail_outbox ORDER BY id DESC LIMIT 50')->fetchAll();
}
?>
<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Administrace — Teryfoto</title><link rel="stylesheet" href="/style.css"><link rel="stylesheet" href="/admin.css"></head>
<body class="admin"><header><a href="/" class="logo">teryfoto<span>administrace</span></a><nav><a href="/">Zpět na web ↗</a><?php if ($loggedIn): ?><form method="post" action="/?admin=1"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="text-button">Odhlásit se</button></form><?php endif; ?></nav></header>
<main class="admin-main"><?php if ($error): ?><p class="notice" role="alert"><?= e($error) ?></p><?php endif; ?><?php if ($notice): ?><p class="notice success" role="status"><?= e($notice) ?></p><?php endif; ?>
<?php if (!$loggedIn): ?><section class="login-panel"><p class="eyebrow">PROSTOR PRO VAŠE PŘÍBĚHY</p><h1>Přihlášení</h1><?php if (!$config): ?><p>Administrace ještě není aktivovaná. Správce serveru musí spustit <code>php infra/create-admin.php</code>.</p><?php else: ?><p>Spravujte termíny a rezervace na jednom místě.</p><form method="post" action="/?admin=1"><?= csrf_field() ?><input type="hidden" name="action" value="login"><label>Uživatelské jméno<input name="username" autocomplete="username" required maxlength="120"></label><label>Heslo<input name="password" type="password" autocomplete="current-password" required maxlength="200"></label><button class="button">Přihlásit se ↗</button></form><?php endif; ?></section>
<?php else: ?><div class="admin-title"><div><p class="eyebrow">PŘEHLED FOCENÍ</p><h1>Všechno má<br><em>svůj čas.</em></h1></div><p>Všechny časy jsou v časové zóně Praha.<br>Čekající rezervace už blokuje svůj termín.<br>Potvrzení a zamítnutí vytvoří zprávu klientovi.<br><a href="/?admin=1&amp;section=smtp">Nastavení e-mailů a přehled odesílání ↗</a></p></div>
<div class="stat-grid"><a href="/?admin=1&amp;status=pending"><strong><?= (int)($counts['pending'] ?? 0) ?></strong>Čeká na potvrzení</a><a href="/?admin=1&amp;status=confirmed"><strong><?= (int)($counts['confirmed'] ?? 0) ?></strong>Potvrzených rezervací</a><a href="/?admin=1&amp;section=slots"><strong><?= count($freeSlots) ?></strong>Volných termínů</a></div>
<nav class="admin-tabs" aria-label="Sekce administrace"><?php foreach (['reservations'=>'Rezervace','slots'=>'Termíny','packages'=>'Balíčky','smtp'=>'E-maily a SMTP','account'=>'Účet'] as $key=>$label): ?><a class="<?= $section===$key ? 'selected' : '' ?>" href="/?admin=1&amp;section=<?= e($key) ?>" <?= $section===$key ? 'aria-current="page"' : '' ?>><?= e($label) ?><?php if ($key==='reservations' && !empty($counts['pending'])): ?><b><?= (int)$counts['pending'] ?></b><?php endif; ?></a><?php endforeach; ?></nav>
<?php if (!empty($counts['pending'])): ?><a class="pending-alert" href="/?admin=1&amp;status=pending#rezervace"><strong><?= (int)$counts['pending'] ?> žádostí čeká na vaše potvrzení</strong><span>Otevřít nové žádosti a vyřídit rezervace →</span></a><?php endif; ?>
<?php require __DIR__ . '/' . ['reservations'=>'admin-reservations.php','slots'=>'admin-slots.php','packages'=>'admin-packages.php','smtp'=>'mail-settings.php','account'=>'admin-account.php'][$section]; ?>
<?php endif; ?></main><script src="/admin.js"></script></body></html>
