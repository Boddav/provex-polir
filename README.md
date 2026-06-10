# PROVEX — Landing page + Admin

## Fájlok

- `index.html` — a publikus landing page (statikusan is működik PHP nélkül)
- `content.json` — a szerkeszthető tartalom (telefon, email, árak stb.)
- `admin.php` — jelszavas backoffice a content.json szerkesztéséhez
- `images/hero-provex.jpeg` — a hero kép

## Telepítés Debian szerverre

### 1) PHP telepítése (ha nincs még)

**nginx + php-fpm-mel:**
```bash
sudo apt update
sudo apt install nginx php-fpm
```

**Apache-csal:**
```bash
sudo apt install apache2 php libapache2-mod-php
```

### 2) Fájlok feltöltése

Másold a teljes mappát a webgyökérbe, pl.:
```
/var/www/provex/
├── index.html
├── admin.php
├── content.json
├── README.md
└── images/
    └── hero-provex.jpeg
```

### 3) Jogosultságok

Hogy az `admin.php` írhassa a `content.json`-t:
```bash
sudo chown -R www-data:www-data /var/www/provex
sudo chmod 664 /var/www/provex/content.json
```

### 4) Jelszó megváltoztatása ⚠️ FONTOS

Nyisd meg az `admin.php`-t és módosítsd:
```php
define('ADMIN_USER', 'provex');
define('ADMIN_PASS', 'provex2026');   // ← ÍRD ÁT EZT!
```

### 5) nginx config (példa)

```nginx
server {
    listen 80;
    server_name provex.hu www.provex.hu;
    root /var/www/provex;
    index index.html;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
    }

    # Védjük a content.json közvetlen szerkesztéstől (csak olvasható kívülről)
    location = /content.json {
        add_header Cache-Control "no-store";
    }
}
```

### 6) Belépés

Nyisd meg: `https://provex.hu/admin.php`
- Alapértelmezett user: `provex`
- Alapértelmezett jelszó: amit a 4. lépésben beállítottál

## Mit lehet szerkeszteni a backofficban

- **Kapcsolat:** telefonszám, email, cím, cím-megjegyzés
- **Nyitvatartás:** hétköznap, szombat, válaszidő
- **Promóció:** hero badge szövege (pl. „-10% akció")
- **Árak:** 3 csomag ára és időtartama

Az oldal egyéb részei (szolgáltatás leírások, GYIK, referenciák, rólunk, stb.) a HTML-ben vannak — ha ezeket is szerkeszthetővé akarod tenni, szólj.

## SSL (ajánlott)

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d provex.hu -d www.provex.hu
```

## Hibakeresés

- **A változások nem jelennek meg az oldalon:** frissíts hard reload-dal (Ctrl+Shift+R). A böngésző cache-elheti a `content.json`-t.
- **„HIBA: nem sikerült menteni":** ellenőrizd a `content.json` jogosultságait (chmod 664, www-data tulajdon).
- **Nem tudok belépni:** a jelszót `admin.php`-ban közvetlenül lehet módosítani.
