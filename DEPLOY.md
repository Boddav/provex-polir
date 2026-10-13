# PROVEX deploy — a 185.187.72.159 szerverre

Ez az útmutató a **meglévő Ablakhővédelem.hu oldal lecserélését** írja le a PROVEX
autókozmetika landing page-re **ezen a szerveren**, úgy, hogy a régi oldal
**teljesen visszaállítható** maradjon, és a **PHP admin backoffice** is működjön.

> A deployt a szerverhez hozzáféréssel rendelkező munkamenet / személy végzi.
> A fájlok ebben a repóban vannak (`index.html`, `admin.php`, `content.json`, `images/`).

---

## 0) Mit telepítünk

A PROVEX a következő fájlokból áll (a repo gyökeréből):

```
index.html            ← a landing page
admin.php             ← jelszavas backoffice
content.json          ← szerkeszthető tartalom (tel, email, nyitvatartás, árak)
images/hero-provex.jpeg
```

NEM kell deployolni: `PROVEX Landing.html`, `index-print.html`, `uploads/`,
`.thumbnail`, `README.md`, `DEPLOY.md`.

---

## 1) Előfeltétel: hol a webroot és fut-e PHP

A jelenlegi oldal statikus (`content.js`), ezért **nem biztos, hogy van PHP** a
szerveren — az `admin.php`-hoz viszont **kell** (PHP-FPM).

```bash
# Melyik szerver és hol a webroot?
systemctl status nginx 2>/dev/null | head -3
systemctl status apache2 2>/dev/null | head -3
# nginx esetén a root-ot a server block adja meg:
grep -R "root " /etc/nginx/sites-enabled/ /etc/nginx/conf.d/ 2>/dev/null
# Fut-e PHP?
php -v 2>/dev/null && systemctl status php*-fpm 2>/dev/null | head -3
```

A továbbiakban tegyük fel, hogy a webroot: **`/var/www/ablakhovedelem`**
(cseréld a valósra!). Jelöld: `WEBROOT=/var/www/ablakhovedelem`.

---

## 2) ⚠️ Biztonsági mentés — hogy a régi oldal visszaállítható legyen

**Ez a lépés kötelező, mielőtt bármit cserélnél.** Teljes webroot + a hozzá tartozó
szerver-config mentése időbélyeggel:

```bash
WEBROOT=/var/www/ablakhovedelem          # ← a valós útvonalra cseréld
STAMP=$(date +%Y%m%d-%H%M%S)
mkdir -p /root/backups

# Teljes oldal
tar czf /root/backups/ablakhovedelem-site-$STAMP.tar.gz -C "$WEBROOT" .

# A vonatkozó szerver-config is (nginx VAGY apache)
tar czf /root/backups/ablakhovedelem-nginx-$STAMP.tar.gz /etc/nginx 2>/dev/null
tar czf /root/backups/ablakhovedelem-apache-$STAMP.tar.gz /etc/apache2 2>/dev/null

ls -la /root/backups/
```

> Visszaállítás (ha kell): `tar xzf /root/backups/ablakhovedelem-site-<STAMP>.tar.gz -C "$WEBROOT"`
> majd a config vissza, és `systemctl reload nginx` / `apache2`.

---

## 3) PROVEX fájlok feltöltése a webrootba

Klón a repóból (vagy scp/rsync a repo fájljaiból):

```bash
WEBROOT=/var/www/ablakhovedelem          # ← a valós útvonalra cseréld
TMP=$(mktemp -d)
git clone --depth 1 https://github.com/Boddav/provex-polir "$TMP/provex"

# A régi tartalom ürítése (a backup már megvan!) és a PROVEX bemásolása
rm -rf "$WEBROOT"/*
cp "$TMP/provex/index.html"   "$WEBROOT/"
cp "$TMP/provex/admin.php"    "$WEBROOT/"
cp "$TMP/provex/content.json" "$WEBROOT/"
mkdir -p "$WEBROOT/images"
cp "$TMP/provex/images/hero-provex.jpeg" "$WEBROOT/images/"
```

---

## 4) PHP bekapcsolása az admin-hoz (ha még nincs)

**nginx + php-fpm:**
```bash
sudo apt update && sudo apt install -y php-fpm
# a server blockba kerüljön be (a sock verziót ellenőrizd: ls /var/run/php/):
#   index index.html index.php;
#   location ~ \.php$ {
#       include snippets/fastcgi-php.conf;
#       fastcgi_pass unix:/var/run/php/php-fpm.sock;
#   }
sudo nginx -t && sudo systemctl reload nginx
```

**Apache:**
```bash
sudo apt update && sudo apt install -y php libapache2-mod-php
sudo systemctl reload apache2
```

Teszt: nyisd meg a `http://185.187.72.159/admin.php` címet — ha a PHP fut, a login
oldal jelenik meg (nem a nyers forráskód).

---

## 5) Jogosultságok — hogy az admin írhassa a content.json-t

```bash
WEBROOT=/var/www/ablakhovedelem
sudo chown -R www-data:www-data "$WEBROOT"
sudo chmod 664 "$WEBROOT/content.json"
```

---

## 6) ⚠️ Admin jelszó megváltoztatása (kötelező)

Az `admin.php`-ban a **default jelszó `provex2026`** — ez a dokumentációban is
szerepel, ezért **telepítéskor cseréld le** egy erősre:

```bash
sudo sed -i "s/define('ADMIN_PASS', 'provex2026');/define('ADMIN_PASS', 'ERŐS_JELSZÓ_IDE');/" "$WEBROOT/admin.php"
```

Belépés: `http://185.187.72.159/admin.php` — user: `provex`, jelszó: az új.

> Az admin jelszót **ne** commitold a repóba — csak a szerveren állítsd be.

---

## 7) Ellenőrzés

- `http://185.187.72.159/` → a PROVEX landing page jelenik meg.
- `http://185.187.72.159/admin.php` → admin login, belépés után a mezők menthetők.
- Mentés után a főoldalon frissülnek az adatok (hard reload: Ctrl+Shift+R).

## 8) Ajánlott: HTTPS

Ha a `provex` saját domaint kap (pl. `provex.hu`) és az erre az IP-re mutat:
```bash
sudo apt install -y certbot python3-certbot-nginx   # vagy -apache
sudo certbot --nginx -d provex.hu -d www.provex.hu
```

---

## Visszaállítás a régi oldalra (ha mégis kell)

```bash
WEBROOT=/var/www/ablakhovedelem
rm -rf "$WEBROOT"/*
tar xzf /root/backups/ablakhovedelem-site-<STAMP>.tar.gz -C "$WEBROOT"
sudo systemctl reload nginx   # vagy apache2
```
