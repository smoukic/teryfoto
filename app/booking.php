<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
start_session();
if (isset($_GET['admin'])) { require __DIR__ . '/admin.php'; exit; }
$message = take_flash(); $success = $message !== ''; $slots = [];
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!valid_csrf() || !empty($_POST['website'])) { http_response_code(400); throw new DomainException('Formulář vypršel. Obnovte stránku a zkuste to znovu.'); }
        if (time() - (int)($_SESSION['last_request'] ?? 0) < 60) { http_response_code(429); throw new DomainException('Další rezervaci můžete odeslat za minutu.'); }
        try { create_booking($_POST); }
        catch (DomainException $e) { http_response_code(422); throw $e; }
        $_SESSION['last_request'] = time();
        flash('Rezervace je uložená a čeká na potvrzení fotografkou. Termín pro vás zatím držíme. E-mailové oznámení se automaticky neodesílá.');
        redirect('/#rezervace');
    }
    $slots = available_slots();
} catch (DomainException $e) { $message = $e->getMessage(); $success = false; $slots = available_slots(); }
catch (Throwable $e) { error_log('Booking failure: ' . $e->getMessage()); http_response_code(503); $message = 'Rezervace jsou dočasně nedostupné. Zkuste to prosím později.'; $success = false; }
