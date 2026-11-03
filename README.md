# Teryfoto

Portfolio fotografky a základ poptávkového systému. PHP 8.2+, PDO SQLite, mbstring a OpenSSL. Závislosti: `composer install --no-dev --prefer-dist`; frontend nepotřebuje build.

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

Před ostrým sběrem osobních údajů doplnit vlastní fotografie, kontakty, informace o zpracování údajů, retenční dobu. Formulář je zatím výslovně označen jako vývojová ukázka pro testovací údaje.

## Nasazení

`infra/deploy.sh` zakládá nový vhost, ověří Nginx a provede reload. Odmítá přepsat existující konfiguraci. Aktualizace zdrojů nesmí přepsat `storage/`. Při prvním nasazení je třeba vytvořit tento adresář a nastavit skupinu `www-data` s právem zápisu.

## Administrace a rezervace

Přihlášení: `/?admin=1` (odkaz také v patičce). Vytvoření účtu na každém prostředí: `php infra/create-admin.php`. Skript odmítne přepsat existující účet a uloží náhodné vstupní heslo do neveřejného `storage/initial-admin.txt`. Uživatelské jméno je `fotografka`. V administraci lze změnit heslo; změna odhlásí ostatní relace. Po předání přístupu odstraňte soubor s prvotním heslem. Hesla, hashe ani databáze nepatří do Gitu. Každé prostředí má vlastní účet a heslo.

- Termíny: začátek, konec a volitelné místo. Časy jsou v `Europe/Prague`. Překryvy jsou zakázané. Neobsazené termíny lze upravit a uzavřít/znovu otevřít; obsazené lze měnit až po vyřízení rezervace.
- Rezervace: přehled podle stavu, kontakty, zpráva klienta, interní poznámka, potvrzení, zamítnutí, zrušení a přesun na jiný volný termín. Přesun nastaví stav čekající na potvrzení.
- Původní poptávky se při migraci zachovají. Nemají slot a je nutné jim před potvrzením přiřadit volný termín.
- Bez vypsaných termínů je veřejný formulář vypnutý. Produkce se neplní ukázkovými termíny.
- Potvrzení nebo zamítnutí mění stav v databázi a vytvoří e-mail klientovi. Odesílání vyžaduje vyplněné a zapnuté SMTP nastavení.
- Administrace používá hash hesla, CSRF, regeneraci session po přihlášení, hodinový limit nečinnosti a databázový limit chybných přihlášení (10 za 15 minut). Veřejné domény používají Secure cookies; přihlašujte se přes HTTPS na Cloudflare, nikoli HTTP origin.
- `storage/` musí být zapisovatelný pro PHP. Na serverech ponechat skupinu `www-data`, setgid a přístup skupiny; `admin.json` má práva 660, soubor prvotního hesla 600. Web server zveřejňuje výhradně `public/`.

Migrace databáze probíhá automaticky při prvním požadavku. Před aktualizací vždy vytvořte konzistentní SQLite zálohu (např. Python `sqlite3.Connection.backup`), nikoli prostou kopii zapisované databáze. Nginx nepotřebuje měnit konfiguraci: administrace i formulář používají existující `index.php`.

## SMTP a automatické e-maily

V administraci v sekci **E-maily a SMTP** je přednastaven `wes1-smtp.wedos.net`, port 587 a STARTTLS. Lze přepnout na SSL/TLS port 465. Doplňte celou adresu schránky jako uživatelské jméno, její heslo a povolenou adresu odesílatele. Adresa příjemce Gmail není automaticky odesílací schránkou u WEDOS. Nastavení každého prostředí je samostatné a automatické odesílání je výchozí vypnuté.

Upozornění na novou rezervaci jde na `terezabradacova0@gmail.com` a ve výchozím testovacím režimu také na `jan@rulc.eu`. Potvrzení/zamítnutí jde klientovi a v testovacím režimu má samostatnou kopii `jan@rulc.eu`. Všichni dostávají samostatné zprávy bez sdílených seznamů příjemců. Interní poznámky se nikdy neposílají. Příjemci i přepínač testovacích kopií jsou upravitelní v administraci. Změny adres se týkají nově vznikajících zpráv; původní neodeslané zprávy je možné zrušit v přehledu.

SMTP heslo je šifrované AES-256-GCM v `storage/smtp.json`. Klíč je `storage/smtp.key` (660), worker token `storage/mail-worker.token` (660). Zálohujte nastavení i klíč společně do zabezpečené zálohy. Žádný z těchto souborů není v Gitu ani ve veřejném adresáři. Heslo se nevrací do HTML, prázdné heslo při uložení ponechá původní hodnotu. Šifrování nechrání před kompromitovaným účtem PHP, který má přístup i ke klíči.

Nová rezervace a její zprávy vznikají v jedné databázové transakci. SMTP chyba nikdy nevrací zpět již uloženou rezervaci či stav. Nové zprávy se zkoušejí odeslat hned po akci, zbytek řeší worker. Nejvýše 5 automatických pokusů s rostoucím odstupem; pak ruční opakování v administraci. Změna stavu na stejnou hodnotu nevytváří další zprávu. Neodeslané potvrzení/zamítnutí se při další změně stavu či termínu zruší, aby nezůstalo ve frontě zastaralé rozhodnutí. Historické rezervace z doby před nasazením nevytvářejí zpětně e-maily.

Odesílání je pozastavené, dokud se SMTP nezapne. Zapnutí zpracuje i dosud čekající zprávy. Testovací tlačítko funguje i při vypnuté automatice, ale pouze po uložení platných přístupových údajů. Přehled ukazuje posledních 50 zpráv, chyby, pokusy a možnost zopakovat nebo zrušit neodeslanou zprávu. Stav odesláno znamená přijetí SMTP serverem, nikoli zaručené doručení do inboxu. Při přerušení spojení těsně po přijetí může SMTP opakování způsobit duplicitu; zprávy mají stabilní Message-ID.

### Worker na serverech

Jednorázově `php infra/init-mail.php` a `python3 infra/install-mail-cron.py DOMENA`. Skript přidá samostatný minutový cron účtu aplikace a zachová ostatní úlohy. Python worker čte token ze souboru a předává ho v HTTP hlavičce pouze na loopback. Přihlašovací údaje nejsou v argumentech procesů. Endpoint `/?mail_worker=1` vyžaduje POST, loopback adresu a správný token; jinak vrací 404. SMTP zpracovává PHP-FPM, takže nemusíme rozšiřovat práva systémového uživatele k tajným souborům vytvořeným webem. Výstup cronu: `storage/mail-worker.log`.

Lokálně se zprávy zpracovávají při rezervaci, změně stavu a testu; pro zpracování při nečinnosti spusťte `python infra/mail-worker.py C:/webs/teryfoto teryfoto` nebo tento příkaz naplánujte.

### Testy

`python infra/test-mail.py` používá pouze lokální testovací SMTP a dočasnou databázi. Ověřuje skutečné TLS s důvěryhodným testovacím certifikátem, SMTP autentizaci, obsah zpráv, příjemce, šifrování hesla, opakování, neduplikování stavů a izolaci chyb. Žádný test neodesílá poštu do internetu. `composer audit --no-interaction` kontroluje známé zranitelnosti závislostí.
