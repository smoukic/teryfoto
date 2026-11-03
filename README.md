# Teryfoto

Portfolio fotografky a základ poptávkového systému. PHP 8.2+, PDO SQLite a mbstring; bez Composeru či buildu.

- Lokálně: http://teryfoto/ — XAMPP Apache, document root `C:/webs/teryfoto/public`.
- Test: `jr-web-test`, doména `test.teryfoto.com`, `/var/www/test.teryfoto.com/public_html`.
- Produkce: `jr-web-prod`, doména `teryfoto.com`, `/var/www/teryfoto.com/public_html`.
- Servery používají Nginx a PHP-FPM 8.2. Konfigurace: `/etc/nginx/sites-available/<doména>`.
- DNS a Cloudflare Tunnel spravuje vlastník. Tunel musí předávat správný Host pro příslušný virtualhost. Origin používá HTTP port 80; veřejné HTTPS terminovat na Cloudflare.

## Vývoj

`public/` je jediný veřejný adresář. SQLite databáze vznikne při první poptávce ve `storage/requests.sqlite`; adresář musí být zapisovatelný pro PHP. Databáze, zálohy a logy nejsou v Gitu. Zálohujte databázi samostatně.

Lokální nastavení s administrátorskými právy: `powershell -ExecutionPolicy Bypass -File infra/setup-local.ps1`.

Kontroly: `php -l public/index.php`, `node --check public/app.js`, `python infra/smoke.py 127.0.0.1 teryfoto`. HTTP kontrola vloží označenou testovací poptávku.

## Stav šablony

Galerie s filtrováním, responzivní rozložení a český formulář. Poptávky se ukládají do SQLite; CSRF token, validace, honeypot a interval mezi odesláními v rámci session. Termín není automaticky rezervovaný. Fotografie jsou externí ukázky z Unsplash, fonty z Google Fonts.

Před ostrým sběrem osobních údajů doplnit vlastní fotografie, kontakty, informace o zpracování údajů, retenční dobu, správu poptávek, e-mailová oznámení a dostupnost termínů. Formulář je zatím výslovně označen jako vývojová ukázka pro testovací údaje.

## Nasazení

`infra/deploy.sh` zakládá nový vhost, ověří Nginx a provede reload. Odmítá přepsat existující konfiguraci. Aktualizace zdrojů nesmí přepsat `storage/`. Při prvním nasazení je třeba vytvořit tento adresář a nastavit skupinu `www-data` s právem zápisu.
