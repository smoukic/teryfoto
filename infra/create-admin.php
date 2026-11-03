<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (admin_config()) { fwrite(STDERR, "Administrátor již existuje. Heslo změňte v administraci.\n"); exit(1); }
$password = bin2hex(random_bytes(12));
save_admin(['username' => 'fotografka', 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'version' => bin2hex(random_bytes(16))]);
$path = storage_path() . '/initial-admin.txt';
file_put_contents($path, "Přihlášení: /?admin=1\nUživatel: fotografka\nHeslo: $password\nPo přihlášení změňte heslo v administraci.\n", LOCK_EX);
chmod($path, 0600);
echo "Administrátor vytvořen. Přístupové údaje jsou v neveřejném storage/initial-admin.txt.\n";
