<?php
declare(strict_types=1);
$path = storage_path() . '/mail-worker.token';
$token = is_file($path) ? trim(file_get_contents($path)) : '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true) || $token === '' || !hash_equals('Bearer ' . $token, (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''))) { http_response_code(404); exit; }
header('Content-Type: application/json'); header('Cache-Control: no-store');
try { echo json_encode(process_mail_queue(3), JSON_THROW_ON_ERROR); }
catch (Throwable $e) { http_response_code(503); echo '{"error":"Mail worker unavailable"}'; }
