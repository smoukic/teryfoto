# Vyber si

Soukromá aplikace pro výběr a předání fotografií. Administrace používá Laravel 12 a Filament 4, galerie Vue 3 a Tailwind CSS. Produkční databáze je PostgreSQL.

## Místní spuštění v XAMPP

Všechny příkazy spusťte ze složky `vybersi`. Pro PHP 8.2 jsou potřeba moduly GD s WebP, Intl, ZIP a PDO pro zvolenou databázi. EXIF zajistí natočení JPEG náhledů podle metadat.

```powershell
composer install
npm ci
npm run build
Copy-Item .env.example .env
```

V `.env` nastavte přístup ke své databázi PostgreSQL a v PHP povolte `pdo_pgsql`. Pro místní zkoušku bez PostgreSQL nastavte `DB_CONNECTION=sqlite`, smažte řádky `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` a `DB_PASSWORD` a vytvořte `database/database.sqlite`.

```powershell
php artisan key:generate
php artisan migrate
php artisan filament:assets
php artisan make:filament-user
```

Pro adresu [http://nahledovky](http://nahledovky) spusťte jednorázově v PowerShellu jako správce:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\deploy\enable-local.ps1
```

Přihlášení je na `/admin/login`. Pro rychlou zkoušku můžete použít `php artisan serve` a odpovídající adresu nastavit v `APP_URL`, aby sdílecí odkazy směřovaly správně.

## Práce s galerií

1. V administraci vytvořte galerii typu **Výběr fotografií** nebo **Předání hotových fotografií**.
2. Přes **Nahrát fotografie** přetáhněte JPEG, PNG nebo WebP. Limit na soubor je 28 MB a 50 megapixelů.
3. Přes **Sdílecí odkaz** zkopírujte adresu pro klienta. Volitelné heslo mu můžete předat samostatně.
4. Ve výběrové galerii může klient označit oblíbené, porovnat až tři snímky a přidat poznámky. Rozpracovaný výběr se ukládá v jeho prohlížeči. Po odeslání je vidět v administraci na kartě **Odeslané výběry**, odkud lze stáhnout TXT s názvy souborů.
5. V předávací galerii si klient stáhne jednotlivé originály nebo ZIP. Pokud má několik originálů stejný název, ZIP je rozliší číselnou předponou.

Originály a jejich odvozené náhledy jsou v `storage/app/private`. Záloha musí zahrnovat tuto složku, databázi a soubor `.env` s klíčem aplikace. Změna režimu z výběru na předání zpřístupní originály ze stejného odkazu.

## Nasazení na jr-web-prod

Nasazujte z konkrétní ověřené revize větve `codex/vybersi`. Na běžném serveru stačí Nginx, PHP-FPM 8.2 a PostgreSQL. Sestavený klient potřebuje pouze statické soubory v `public/build`. Při výrobě balíčku projděte v čistém pracovním adresáři tyto kroky:

```sh
cd vybersi
npm ci
npm run build
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan filament:assets
tar --exclude='bootstrap/cache/*.php' -czf ../vybersi-release.tar.gz \
    app artisan bootstrap composer.json composer.lock config database deploy public resources routes storage vendor
```

Balíček vytvořte před lokálním přidáním testovacích dat. Soubor `.env` se vytváří až na serveru. V produkci je document root `/var/www/vybersi.rulc.eu/public`. Závislosti a statické soubory se připravují před nahráním. Jednorázový instalační skript vyžaduje sudo kvůli instalaci PostgreSQL, databázi a nastavení samostatného PHP-FPM poolu a Nginxu.

```sh
sudo /bin/sh /home/rulcj/vybersi-stage/install-production.sh /home/rulcj/vybersi-stage/vybersi-release.tar.gz
sudo -u vybersi php /var/www/vybersi.rulc.eu/artisan make:filament-user
```

V Cloudflare Tunnelu nastavte veřejné jméno `vybersi.rulc.eu` na službu `http://127.0.0.1:80`. Ověřte produkční `/up` a `/admin/login` přes HTTPS. Lokální Nginx lze ověřit příkazem `curl -f -H 'Host: vybersi.rulc.eu' http://127.0.0.1/up`.

## Testy

```sh
npm ci
npm run build
php artisan test
```

Integrační test pokrývá vytvoření a úpravu galerie ve Filamentu, upload, ochranu heslem, vodoznak, odeslání výběru s poznámkou, export, smazání a stažení originálů i ZIPu.

