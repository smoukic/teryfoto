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

Kontroly: `php -l public/index.php`, `node --check public/app.js`, `python infra/smoke.py 127.0.0.1 teryfoto`. Smoke kontrola nic neukládá. Úplné izolované integrační testy: `python infra/test-booking.py` (vyžaduje PHP CLI a Python 3). Testy používají dočasnou databázi a účet, ověřují i souběžnou rezervaci, migraci původních poptávek a odhlášení po změně hesla.

## Stav šablony

Galerie s filtrováním, responzivní rozložení a rezervace konkrétních volných termínů. SQLite, CSRF ochrana, validace, honeypot a interval mezi odesláními v rámci session. Čekající i potvrzené rezervace blokují termín; zamítnutí nebo zrušení ho uvolní. Transakce a unikátní index brání dvojí rezervaci i při souběžném odeslání. Fotografie jsou externí ukázky z Unsplash, fonty z Google Fonts.

Před ostrým sběrem osobních údajů doplnit vlastní fotografie, kontakty, informace o zpracování údajů, retenční dobu a e-mailová oznámení. Formulář je zatím výslovně označen jako vývojová ukázka pro testovací údaje.

## Nasazení

`infra/deploy.sh` zakládá nový vhost, ověří Nginx a provede reload. Odmítá přepsat existující konfiguraci. Aktualizace zdrojů nesmí přepsat `storage/`. Při prvním nasazení je třeba vytvořit tento adresář a nastavit skupinu `www-data` s právem zápisu.

## Administrace a rezervace

Přihlášení: `/?admin=1` (odkaz také v patičce). Vytvoření účtu na každém prostředí: `php infra/create-admin.php`. Skript odmítne přepsat existující účet a uloží náhodné vstupní heslo do neveřejného `storage/initial-admin.txt`. Uživatelské jméno je `fotografka`. V administraci lze změnit heslo; změna odhlásí ostatní relace. Po předání přístupu odstraňte soubor s prvotním heslem. Hesla, hashe ani databáze nepatří do Gitu. Každé prostředí má vlastní účet a heslo.

- Termíny: začátek, konec a volitelné místo. Časy jsou v `Europe/Prague`. Překryvy jsou zakázané. Neobsazené termíny lze upravit a uzavřít/znovu otevřít; obsazené lze měnit až po vyřízení rezervace.
- Rezervace: přehled podle stavu, kontakty, zpráva klienta, interní poznámka, potvrzení, zamítnutí, zrušení a přesun na jiný volný termín. Přesun nastaví stav čekající na potvrzení.
- Původní poptávky se při migraci zachovají. Nemají slot a je nutné jim před potvrzením přiřadit volný termín.
- Bez vypsaných termínů je veřejný formulář vypnutý. Produkce se neplní ukázkovými termíny.
- Potvrzení mění stav v databázi. E-maily se automaticky neodesílají; fotografka musí klienta kontaktovat sama.
- Administrace používá hash hesla, CSRF, regeneraci session po přihlášení, hodinový limit nečinnosti a databázový limit chybných přihlášení (10 za 15 minut). Veřejné domény používají Secure cookies; přihlašujte se přes HTTPS na Cloudflare, nikoli HTTP origin.
- `storage/` musí být zapisovatelný pro PHP. Na serverech ponechat skupinu `www-data`, setgid a přístup skupiny; `admin.json` má práva 660, soubor prvotního hesla 600. Web server zveřejňuje výhradně `public/`.

Migrace databáze probíhá automaticky při prvním požadavku. Před aktualizací vždy vytvořte konzistentní SQLite zálohu (např. Python `sqlite3.Connection.backup`), nikoli prostou kopii zapisované databáze. Nginx nepotřebuje měnit konfiguraci: administrace i formulář používají existující `index.php`.
