<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/app/bootstrap.php';
smtp_key();
$path = storage_path() . '/mail-worker.token';
if (!file_exists($path)) { $file = fopen($path, 'x'); chmod($path, 0660); fwrite($file, bin2hex(random_bytes(32))); fclose($file); }
echo "Mail encryption key and worker token ready.\n";
