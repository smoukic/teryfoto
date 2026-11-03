<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Prague');
const TYPES = ['Portrét', 'Párové focení', 'Rodina', 'Svatba', 'Brand'];
const STATES = ['pending' => 'Čeká na potvrzení', 'confirmed' => 'Potvrzená', 'rejected' => 'Zamítnutá', 'cancelled' => 'Zrušená'];
function storage_path(): string { return getenv('TERYFOTO_STORAGE') ?: dirname(__DIR__) . '/storage'; }
function db(): PDO {
    static $db;
    if ($db) return $db;
    $db = new PDO('sqlite:' . storage_path() . '/requests.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON');
    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS requests (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL, requested_date TEXT NOT NULL, type TEXT NOT NULL, note TEXT NOT NULL, created_at TEXT NOT NULL)");
        $columns = array_column($db->query('PRAGMA table_info(requests)')->fetchAll(), 'name');
        foreach (['status' => "TEXT NOT NULL DEFAULT 'pending'", 'slot_id' => 'INTEGER REFERENCES slots(id)', 'admin_note' => "TEXT NOT NULL DEFAULT ''", 'updated_at' => 'TEXT'] as $name => $definition) {
            if (!in_array($name, $columns, true)) $db->exec("ALTER TABLE requests ADD COLUMN $name $definition");
        }
        $db->exec("CREATE TABLE IF NOT EXISTS slots (id INTEGER PRIMARY KEY, starts_at TEXT NOT NULL, ends_at TEXT NOT NULL, location TEXT NOT NULL DEFAULT '', is_open INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL)");
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS active_slot ON requests(slot_id) WHERE slot_id IS NOT NULL AND status IN ('pending','confirmed')");
        $db->exec('CREATE INDEX IF NOT EXISTS slot_dates ON slots(starts_at)');
        $db->exec("CREATE TABLE IF NOT EXISTS packages (id INTEGER PRIMARY KEY,name TEXT NOT NULL,description TEXT NOT NULL DEFAULT '',duration_minutes INTEGER,is_individual INTEGER NOT NULL DEFAULT 0,is_active INTEGER NOT NULL DEFAULT 1,sort_order INTEGER NOT NULL DEFAULT 0)");
        if (!(int)$db->query('SELECT COUNT(*) FROM packages')->fetchColumn()) {
            $db->exec("INSERT INTO packages(name,description,duration_minutes,is_individual,sort_order) VALUES ('Minibalíček','Hodina focení pro vaše malé i velké příběhy.',60,0,1),('Velký balíček','Dvě hodiny s prostorem pro více nápadů.',120,0,2),('Brand','Focení pro vaši značku. Rozsah i termín domluvíme individuálně.',NULL,1,3)");
        }
        $slotColumns = array_column($db->query('PRAGMA table_info(slots)')->fetchAll(), 'name');
        if (!in_array('package_id',$slotColumns,true)) $db->exec('ALTER TABLE slots ADD COLUMN package_id INTEGER REFERENCES packages(id)');
        $requestColumns = array_column($db->query('PRAGMA table_info(requests)')->fetchAll(), 'name');
        foreach (['package_id'=>'INTEGER REFERENCES packages(id)','package_name'=>"TEXT NOT NULL DEFAULT 'Bez balíčku'",'is_individual'=>'INTEGER NOT NULL DEFAULT 0'] as $name=>$definition) {
            if (!in_array($name,$requestColumns,true)) $db->exec("ALTER TABLE requests ADD COLUMN $name $definition");
        }
        $db->exec('CREATE TABLE IF NOT EXISTS login_limits (bucket TEXT PRIMARY KEY, failures INTEGER NOT NULL, reset_at INTEGER NOT NULL)');
        $db->exec("CREATE TABLE IF NOT EXISTS mail_outbox (id INTEGER PRIMARY KEY,request_id INTEGER REFERENCES requests(id),event_kind TEXT NOT NULL,recipient TEXT NOT NULL,subject TEXT NOT NULL,body TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'pending',attempts INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL,sent_at TEXT,next_attempt INTEGER NOT NULL,locked_at INTEGER,last_error TEXT NOT NULL DEFAULT '')");
        $db->exec('CREATE INDEX IF NOT EXISTS mail_due ON mail_outbox(status,next_attempt)');
        $db->exec('COMMIT');
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
    return $db;
}
function e(string|int|null $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function query(string $sql, array $params = []): PDOStatement { $stmt = db()->prepare($sql); $stmt->execute($params); return $stmt; }
function atomic(callable $action): mixed {
    $db = db(); $db->exec('BEGIN IMMEDIATE');
    try { $result = $action(); $db->exec('COMMIT'); return $result; }
    catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}
function start_session(): void {
    ini_set('session.use_strict_mode', '1');
    // The origin uses HTTP behind Cloudflare; secure cookies are forced on the public domains.
    $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
    session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_secure' => in_array($host, ['teryfoto.com', 'test.teryfoto.com'], true) || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')]);
    $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}
function valid_csrf(): bool { return hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? '')); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function redirect(string $to): never { header('Location: ' . $to, true, 303); exit; }
function flash(string $message): void { $_SESSION['flash'] = $message; }
function take_flash(): string { $message = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']); return $message; }
function slot_label(array $slot): string { return date('j. n. Y · H:i', strtotime($slot['starts_at'])) . '–' . date('H:i', strtotime($slot['ends_at'])) . ($slot['location'] ? ' · ' . $slot['location'] : ''); }
function available_slots(): array {
    return query("SELECT s.* FROM slots s LEFT JOIN packages p ON p.id=s.package_id WHERE (s.package_id IS NULL OR p.is_active=1) AND s.is_open=1 AND s.starts_at > ? AND NOT EXISTS (SELECT 1 FROM requests r WHERE r.slot_id=s.id AND r.status IN ('pending','confirmed')) ORDER BY s.starts_at", [date('Y-m-d H:i:s')])->fetchAll();
}
function create_booking(array $data): int {
    $name = trim((string)($data['name'] ?? '')); $email = trim((string)($data['email'] ?? '')); $note = trim((string)($data['note'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($data['type'] ?? '', TYPES, true) || mb_strlen($note) > 2000 || empty($data['consent'])) throw new DomainException('Vyplňte jméno, platný e-mail, druh focení a souhlas s uložením údajů.');
    return atomic(function () use ($data, $name, $email, $note): int {
        $packageValue = (string)($data['package_id'] ?? '');
        $package = ctype_digit($packageValue) && (int)$packageValue > 0 ? query('SELECT * FROM packages WHERE id=? AND is_active=1', [(int)$packageValue])->fetch() : null;
        if ($packageValue !== '' && $packageValue !== 'none' && !$package) throw new DomainException('Vyberte dostupný balíček.');
        $individual = $package && $package['is_individual'];
        $slot = null;
        if ($individual) {
            if (!empty($data['slot_id'])) throw new DomainException('Individuální balíček se poptává bez pevného termínu.');
            if (mb_strlen($note) < 5) throw new DomainException('Napište prosím pár slov o své představě pro individuální focení.');
        } else {
            $slot = query("SELECT s.* FROM slots s LEFT JOIN packages p ON p.id=s.package_id WHERE s.id=? AND s.is_open=1 AND s.starts_at>? AND (s.package_id IS NULL OR p.is_active=1) AND NOT EXISTS (SELECT 1 FROM requests r WHERE r.slot_id=s.id AND r.status IN ('pending','confirmed'))", [(int)($data['slot_id'] ?? 0),date('Y-m-d H:i:s')])->fetch();
            if (!$slot) throw new DomainException('Tento termín už není volný. Vyberte prosím jiný.');
            if ($packageValue !== '' && (int)($package['id'] ?? 0) !== (int)$slot['package_id']) throw new DomainException('Vybraný termín nepatří k tomuto balíčku.');
            if ($packageValue === '' && $slot['package_id']) $package = query('SELECT * FROM packages WHERE id=?', [$slot['package_id']])->fetch();
        }
        query("INSERT INTO requests (name,email,requested_date,type,note,created_at,slot_id,status,package_id,package_name,is_individual) VALUES (?,?,?,?,?,?,?,'pending',?,?,?)", [$name,$email,$slot ? substr($slot['starts_at'],0,10) : 'Individuální domluva',$data['type'],$note,gmdate('c'),$slot['id'] ?? null,$package['id'] ?? null,$package['name'] ?? 'Bez balíčku',(int)$individual]);
        $id = (int)db()->lastInsertId();
        queue_booking_mail($id, 'new');
        return $id;
    });
}
function save_slot(array $data): void {
    $start = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', (string)($data['starts_at'] ?? ''));
    $location = trim((string)($data['location'] ?? ''));
    $id = (int)($data['slot_id'] ?? 0);
    $count = $id ? 1 : filter_var($data['count'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>20]]);
    if (!$start || $start->format('Y-m-d\TH:i') !== ($data['starts_at'] ?? '') || $start <= new DateTimeImmutable() || mb_strlen($location)>160 || !$count) throw new DomainException('Zadejte budoucí začátek, místo do 160 znaků a počet termínů 1–20.');
    atomic(function () use ($data,$start,$location,$id,$count): void {
        $old = $id ? query('SELECT * FROM slots WHERE id=?',[$id])->fetch() : null;
        if ($id && !$old) throw new DomainException('Termín neexistuje.');
        if ($id && query("SELECT id FROM requests WHERE slot_id=? AND status IN ('pending','confirmed')",[$id])->fetch()) throw new DomainException('Obsazený termín nelze upravit. Nejprve zrušte nebo zamítněte rezervaci.');
        $packageId = (int)($data['package_id'] ?? ($old['package_id'] ?? 0));
        $package = $packageId ? query('SELECT * FROM packages WHERE id=? AND is_active=1',[$packageId])->fetch() : null;
        if ($packageId && (!$package || $package['is_individual'])) throw new DomainException('Pro individuální balíček nelze vypisovat pevné termíny.');
        $minutes = $package ? (int)$package['duration_minutes'] : 120;
        for ($i=0;$i<$count;$i++) {
            $a = $start->modify('+' . ($i*$minutes) . ' minutes')->format('Y-m-d H:i:s');
            $b = $start->modify('+' . (($i+1)*$minutes) . ' minutes')->format('Y-m-d H:i:s');
            if (query('SELECT id FROM slots WHERE id<>? AND starts_at<? AND ends_at>?',[$id,$b,$a])->fetch()) throw new DomainException('Termín se překrývá s jiným termínem. Žádný z nových termínů nebyl uložen.');
            if ($id) query('UPDATE slots SET starts_at=?,ends_at=?,location=?,package_id=? WHERE id=?',[$a,$b,$location,$packageId ?: null,$id]);
            else query('INSERT INTO slots(starts_at,ends_at,location,package_id,created_at) VALUES(?,?,?,?,?)',[$a,$b,$location,$packageId ?: null,gmdate('c')]);
        }
    });
}
function set_booking_status(int $id, string $status, string $note): void {
    if (!isset(STATES[$status]) || mb_strlen($note) > 2000) throw new DomainException('Neplatný stav nebo příliš dlouhá interní poznámka.');
    atomic(function () use ($id, $status, $note): void {
        $booking = query('SELECT r.*,s.starts_at,s.is_open FROM requests r LEFT JOIN slots s ON s.id=r.slot_id WHERE r.id=?', [$id])->fetch();
        if (!$booking) throw new DomainException('Rezervace neexistuje.');
        if (in_array($status, ['pending','confirmed'], true)) {
            if (!$booking['slot_id'] && !$booking['is_individual']) throw new DomainException('Původní poptávka nemá termín. Nejdříve jí přiřaďte volný termín.');
            if ($booking['slot_id'] && (!$booking['is_open'] || $booking['starts_at'] <= date('Y-m-d H:i:s'))) throw new DomainException('Tento termín je uzavřený nebo již proběhl.');
            if (query("SELECT id FROM requests WHERE slot_id=? AND id<>? AND status IN ('pending','confirmed')", [$booking['slot_id'],$id])->fetch()) throw new DomainException('Tento termín už má jinou rezervaci.');
        }
        query('UPDATE requests SET status=?,admin_note=?,updated_at=? WHERE id=?', [$status,trim($note),gmdate('c'),$id]);
        if ($booking['status'] !== $status) {
            cancel_pending_decisions($id);
            if (in_array($status, ['confirmed','rejected'], true)) queue_booking_mail($id, $status);
        }
    });
}
function assign_booking(int $id, int $slotId): void {
    atomic(function () use ($id, $slotId): void {
        $booking = query('SELECT * FROM requests WHERE id=?', [$id])->fetch();
        $slot = query("SELECT s.* FROM slots s WHERE s.id=? AND s.is_open=1 AND s.starts_at>? AND NOT EXISTS (SELECT 1 FROM requests r WHERE r.slot_id=s.id AND r.id<>? AND r.status IN ('pending','confirmed'))", [$slotId,date('Y-m-d H:i:s'),$id])->fetch();
        if (!$booking || !$slot) throw new DomainException('Rezervace nebo volný termín není dostupný.');
        if ($booking['is_individual'] || (int)$booking['package_id'] !== (int)$slot['package_id']) throw new DomainException('Vyberte volný termín stejného balíčku. Individuální focení se domlouvá bez pevného termínu.');
        query("UPDATE requests SET slot_id=?,requested_date=?,status='pending',updated_at=? WHERE id=?", [$slotId,substr($slot['starts_at'],0,10),gmdate('c'),$id]);
        cancel_pending_decisions($id);
    });
}
function admin_config(): ?array {
    $file = storage_path() . '/admin.json';
    return is_file($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : null;
}
function save_admin(array $config): void {
    $path = storage_path() . '/admin.json'; $tmp = $path . '.' . bin2hex(random_bytes(6));
    if (file_put_contents($tmp, json_encode($config, JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Cannot save admin configuration');
    chmod($tmp, 0660);
    if (!rename($tmp, $path)) { unlink($tmp); throw new RuntimeException('Cannot replace admin configuration'); }
}
function authenticated(?array $config): bool {
    if (!$config || !isset($_SESSION['admin_version'], $_SESSION['admin_seen']) || $_SESSION['admin_version'] !== $config['version'] || time() - $_SESSION['admin_seen'] > 3600) { unset($_SESSION['admin_version'], $_SESSION['admin_seen']); return false; }
    $_SESSION['admin_seen'] = time(); return true;
}
require_once __DIR__ . '/mail.php';

require_once __DIR__ . '/packages.php';
