<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Prague');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
$message = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $date = (string)($_POST['date'] ?? '');
    $type = (string)($_POST['type'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? '')) || !empty($_POST['website'])) {
        http_response_code(400); $message = 'Formulář vypršel. Obnovte stránku a zkuste to znovu.';
    } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 120 || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$parsed || $parsed->format('Y-m-d') !== $date || $date <= date('Y-m-d') || !in_array($type, ['Portrét', 'Párové focení', 'Rodina', 'Svatba'], true) || mb_strlen($note) > 2000 || empty($_POST['consent'])) {
        http_response_code(422); $message = 'Zkontrolujte jméno, e-mail, budoucí datum a souhlas s uložením poptávky.';
    } elseif (time() - (int)($_SESSION['last_request'] ?? 0) < 60) {
        http_response_code(429); $message = 'Další poptávku můžete odeslat za minutu.';
    } else {
        try {
            $db = new PDO('sqlite:' . dirname(__DIR__) . '/storage/requests.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->exec('CREATE TABLE IF NOT EXISTS requests (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL, requested_date TEXT NOT NULL, type TEXT NOT NULL, note TEXT NOT NULL, created_at TEXT NOT NULL)');
            $db->prepare('INSERT INTO requests (name,email,requested_date,type,note,created_at) VALUES (?,?,?,?,?,?)')->execute([$name,$email,$date,$type,$note,gmdate('c')]);
            $_SESSION['last_request'] = time();
            $_SESSION['sent'] = true;
            header('Location: /?sent=1#rezervace', true, 303); exit;
        } catch (Throwable $e) { error_log('Reservation storage failure: ' . $e->getMessage()); http_response_code(503); $message = 'Poptávku se nepodařilo uložit. Zkuste to prosím později.'; }
    }
}
if (isset($_GET['sent']) && !empty($_SESSION['sent'])) { $success = true; $message = 'Poptávka je uložená. Termín zatím není potvrzený. V této ukázce se e-mail neodesílá.'; unset($_SESSION['sent']); }
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="description" content="Teryfoto — portfolio fotografky. Portréty, páry a rodinné příběhy v přirozeném světle."><title>Teryfoto — příběhy ve světle</title><link rel="stylesheet" href="/style.css"></head>
<body><header><a class="logo" href="/">teryfoto<span>fotografie s citem</span></a><nav aria-label="Hlavní navigace"><a href="#portfolio">Portfolio</a><a href="#pristup">O focení</a><a class="nav-cta" href="#rezervace">Pojďme něco zachytit ↗</a></nav></header>
<main><section class="hero"><div class="hero-copy"><p class="eyebrow">PŘIROZENĚ. OPRAVDOVĚ. PO VAŠEM.</p><h1>Malé okamžiky.<br><em>Velké příběhy.</em></h1><p class="intro">Rozcuchané vlasy, blízkost a světlo na konci dne. Fotografie, ve kterých se poznáte.</p><a class="button" href="#portfolio">Prohlédnout příběhy <span>↗</span></a><div class="hero-foot"><span>PORTRÉTY · PÁRY · RODINY</span><a href="#portfolio" aria-label="Přejít na portfolio">↓</a></div></div><div class="hero-photo"><img src="https://images.unsplash.com/photo-1472396961693-142e6e269027?auto=format&fit=crop&w=1400&q=85" alt="Horské údolí v měkkém večerním světle"><span>01 / SVĚTLO A KLID</span></div></section>
<section class="portfolio section" id="portfolio"><div class="section-heading"><div><p class="eyebrow">VÝBĚR Z PŘÍBĚHŮ</p><h2>Krása je v tom,<br><em>co je skutečné.</em></h2></div><p>Prostor pro vaše chvíle.<br>Bez spěchu a zbytečných póz.</p></div><div class="filters" role="group" aria-label="Filtrovat fotografie"><button class="active" data-filter="all" aria-pressed="true">Všechny příběhy</button><button data-filter="lidé" aria-pressed="false">Lidé</button><button data-filter="příroda" aria-pressed="false">Příroda</button></div><div class="gallery"><figure data-category="lidé"><img loading="lazy" src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=900&q=85" alt="Portrét ženy v přirozeném světle"><figcaption><span>Jen tak, sama sebou</span><small>PORTRÉT / 01</small></figcaption></figure><figure data-category="příroda"><img loading="lazy" src="https://images.unsplash.com/photo-1441974231531-c6227db76b6e?auto=format&fit=crop&w=900&q=85" alt="Sluneční paprsky mezi stromy"><figcaption><span>Tam, kde se zastaví čas</span><small>PŘÍRODA / 02</small></figcaption></figure><figure data-category="lidé"><img loading="lazy" src="https://images.unsplash.com/photo-1517841905240-472988babdf9?auto=format&fit=crop&w=900&q=85" alt="Uvolněný venkovní portrét"><figcaption><span>Obyčejně neobyčejná</span><small>PORTRÉT / 03</small></figcaption></figure></div><p class="demo-note">Ukázková šablona · fotografie z Unsplash slouží jako vizuální reference, nejsou vlastní tvorbou Teryfoto.</p></section>
<section class="approach section" id="pristup"><p class="eyebrow">MÉNĚ PÓZ. VÍCE VÁS.</p><h2>Nemusíte umět být<br><em>před objektivem.</em></h2><div class="steps"><article><span>01 — DOMLUVÍME SE</span><h3>Váš nápad, společný plán.</h3><p>Napíšete, co byste chtěli zachytit. Společně vybereme místo, náladu a vhodný termín.</p></article><article><span>02 — ZPOMALÍME</span><h3>Procházka místo pózování.</h3><p>Prostor pro přirozený pohyb, povídání a chvíle, které vznikají samy.</p></article><article><span>03 — ZŮSTANE VZPOMÍNKA</span><h3>Příběh, ke kterému se vrátíte.</h3><p>Rozsah, cenu i způsob předání fotografií si domluvíme před focením.</p></article></div></section>
<section class="booking section" id="rezervace"><div><p class="eyebrow">VÁŠ PŘÍBĚH ZAČÍNÁ TADY</p><h2>Co spolu<br><em>zachytíme?</em></h2><p>Napište mi svou představu a preferovaný den. Odeslání je nezávazná poptávka, nikoliv potvrzená rezervace.</p><p class="demo-note">Pracovní verze: poptávky se ukládají do databáze. E-mailová oznámení a správa dostupnosti budou doplněny.</p></div><form method="post" action="/#rezervace"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><div class="trap" aria-hidden="true"><label>Web<input name="website" tabindex="-1" autocomplete="off"></label></div><?php if ($message): ?><p class="notice <?= $success ? 'success' : '' ?>" role="status"><?= e($message) ?></p><?php endif; ?><div class="form-row"><label>Vaše jméno<input name="name" autocomplete="name" required minlength="2" maxlength="120" value="<?= e((string)($_POST['name'] ?? '')) ?>" placeholder="Jak vám mám říkat?"></label><label>E-mail<input type="email" name="email" autocomplete="email" required maxlength="254" value="<?= e((string)($_POST['email'] ?? '')) ?>" placeholder="vas@email.cz"></label></div><div class="form-row"><label>Co budeme fotit?<select name="type" required><option value="">Vyberte focení</option>Portrét</option><option>Párové focení</option><option>Rodina</option><option>Svatba</option></select></label><label>Preferovaný den<input type="date" name="date" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required value="<?= e((string)($_POST['date'] ?? '')) ?>"></label></div><label>Pár slov o vaší představě<textarea name="note" rows="3" maxlength="2000" placeholder="Místo, nálada, váš příběh…"><?= e((string)($_POST['note'] ?? '')) ?></textarea></label><label class="consent"><input type="checkbox" name="consent" required> Souhlasím s uložením údajů pro vyřízení této poptávky. Jde o vývojovou ukázku; zadávejte pouze testovací údaje.</label><button class="button" type="submit">Odeslat nezávaznou poptávku <span>↗</span></button></form></section></main>
<footer><a class="logo" href="/">teryfoto</a><span>Vzpomínky, které mají blízko.</span><small>© <?= date('Y') ?> Teryfoto · pracovní návrh</small></footer><script src="/app.js"></script></body></html>
