<?php
// =================================================================
// PROVEX — Egyszerű backoffice / admin
// -----------------------------------------------------------------
// Telepítés Debian-on:
//   sudo apt install nginx php-fpm   (vagy apache2 + php)
//   index.html, content.json, admin.php egy könyvtárba pl. /var/www/provex
//   chown www-data:www-data content.json && chmod 664 content.json
//
// FONTOS: változtasd meg a jelszót lent (ADMIN_PASS) az első indulás előtt!
// =================================================================

session_start();

// ============== KONFIGURÁCIÓ ==============
define('ADMIN_USER', 'provex');
define('ADMIN_PASS', 'provex2026');                   // ← VÁLTOZTASD MEG!
define('CONTENT_FILE', __DIR__ . '/content.json');
define('SESSION_TTL', 60 * 60 * 4);                   // 4 óra

// ============== HELPEREK ==============
function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function load_content() {
    if (!file_exists(CONTENT_FILE)) return [];
    $raw = file_get_contents(CONTENT_FILE);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function save_content($data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return file_put_contents(CONTENT_FILE, $json, LOCK_EX) !== false;
}

function is_authed() {
    if (empty($_SESSION['admin'])) return false;
    if (empty($_SESSION['admin_time']) || (time() - $_SESSION['admin_time']) > SESSION_TTL) {
        session_destroy();
        return false;
    }
    $_SESSION['admin_time'] = time();
    return true;
}

// ============== AUTH ==============
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

$login_error = '';
if (!is_authed() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $u = $_POST['user'] ?? '';
    $p = $_POST['pass'] ?? '';
    if (hash_equals(ADMIN_USER, (string)$u) && hash_equals(ADMIN_PASS, (string)$p)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['admin_time'] = time();
        header('Location: admin.php');
        exit;
    } else {
        sleep(1); // basic brute-force throttle
        $login_error = 'Hibás felhasználónév vagy jelszó.';
    }
}

// ============== MENTÉS ==============
$save_msg = '';
$save_err = '';
if (is_authed() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_submit'])) {
    $data = load_content();

    // Flat fields
    foreach (['phone','email','addressShort','addressNote','hoursMain','hoursSaturday','responseTime','responseTimeSub','heroBadge'] as $k) {
        if (isset($_POST[$k])) $data[$k] = trim((string)$_POST[$k]);
    }
    // Pricing array
    if (isset($_POST['pricing']) && is_array($_POST['pricing'])) {
        $pricing = [];
        foreach ($_POST['pricing'] as $i => $row) {
            if (!is_array($row)) continue;
            $pricing[] = [
                'price'    => trim((string)($row['price']    ?? '')),
                'duration' => trim((string)($row['duration'] ?? '')),
            ];
        }
        $data['pricing'] = $pricing;
    }

    if (save_content($data)) {
        $save_msg = 'Mentve. A változások azonnal élnek az oldalon.';
    } else {
        $save_err = 'HIBA: nem sikerült menteni a content.json fájlt. Ellenőrizd, hogy a webszerver írhatja-e (chmod 664, chown www-data).';
    }
}

$c = load_content();

// Defaults if missing
$defaults = [
    'phone' => '+36 30 210 6895',
    'email' => 'kozmetika@provex.hu',
    'addressShort' => 'Budapest, XIII. kerület',
    'addressNote' => 'Pontos cím egyeztetés után',
    'hoursMain' => 'H–P · 08:00 – 18:00',
    'hoursSaturday' => 'Szo · előre egyeztetve',
    'responseTime' => 'Munkanapokon 2 órán belül',
    'responseTimeSub' => 'Hétvégén következő munkanapon',
    'heroBadge' => 'Új ügyfél? -10% az első kerámia bevonatra',
    'pricing' => [
        ['price'=>'79 000 Ft','duration'=>'~ 1 munkanap'],
        ['price'=>'189 000 Ft','duration'=>'~ 2–3 munkanap'],
        ['price'=>'690 000 Ft','duration'=>'~ 5–7 munkanap'],
    ],
];
foreach ($defaults as $k => $v) {
    if (!isset($c[$k])) $c[$k] = $v;
}
$pricing = $c['pricing'] ?? $defaults['pricing'];
while (count($pricing) < 3) $pricing[] = ['price'=>'','duration'=>''];
$tierNames = ['Alap · Frissítés', 'Prémium · Kerámia védelem', 'Ultra · PPF + Kerámia'];

?><!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>PROVEX Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Saira:wght@500;600;700&family=Manrope:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet" />
<style>
:root{
  --bg:#0a0a0a;
  --bg-2:#0f0f0f;
  --surface:#161616;
  --surface-2:#1c1c1c;
  --line:rgba(255,255,255,.09);
  --line-2:rgba(255,255,255,.16);
  --text:#f4f4f4;
  --muted:#8a8a8a;
  --muted-2:#5a5a5a;
  --accent:#a8e635;
  --danger:#ff6b6b;
  --display:"Saira", system-ui, sans-serif;
  --body:"Manrope", system-ui, sans-serif;
  --mono:"JetBrains Mono", ui-monospace, monospace;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font-family:var(--body);-webkit-font-smoothing:antialiased;line-height:1.55}
a{color:var(--accent)}
button{font-family:inherit;cursor:pointer}

/* Top bar */
.topbar{position:sticky;top:0;z-index:10;display:flex;align-items:center;justify-content:space-between;padding:18px 32px;background:rgba(10,10,10,.85);backdrop-filter:blur(14px);border-bottom:1px solid var(--line)}
.brand{font-family:var(--display);font-weight:700;font-size:20px;letter-spacing:.2em;display:flex;align-items:center;gap:14px}
.brand .x{color:var(--accent)}
.brand .pill{font-family:var(--mono);font-size:10px;letter-spacing:.18em;color:var(--accent);background:rgba(168,230,53,.1);border:1px solid rgba(168,230,53,.3);padding:4px 10px;border-radius:999px;text-transform:uppercase}
.top-actions{display:flex;gap:10px;align-items:center}
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:8px;font-family:inherit;font-size:14px;font-weight:600;border:1px solid transparent;transition:all .2s ease;text-decoration:none}
.btn-primary{background:var(--accent);color:#0a0a0a}
.btn-primary:hover{background:#bdf24a}
.btn-ghost{background:transparent;color:var(--text);border-color:var(--line-2)}
.btn-ghost:hover{background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.3)}
.btn-text{background:transparent;color:var(--muted);padding:11px 6px}
.btn-text:hover{color:var(--text)}

/* Main layout */
main{max-width:1100px;margin:0 auto;padding:48px 32px 96px}
.eyebrow{font-family:var(--mono);font-size:11px;letter-spacing:.18em;color:var(--accent);text-transform:uppercase;margin-bottom:14px}
h1{font-family:var(--display);font-weight:700;font-size:44px;letter-spacing:-.02em;line-height:1;margin:0 0 14px}
.lead{color:var(--muted);font-size:16px;max-width:55ch;margin:0 0 0}

/* Toast */
.toast{margin:32px 0 0;padding:16px 20px;border-radius:10px;display:flex;align-items:center;gap:14px;font-size:14px}
.toast.ok{background:rgba(168,230,53,.08);border:1px solid rgba(168,230,53,.4);color:var(--accent)}
.toast.err{background:rgba(255,107,107,.08);border:1px solid rgba(255,107,107,.4);color:var(--danger)}
.toast svg{width:18px;height:18px}

/* Sections */
.section{margin-top:40px;background:var(--surface);border:1px solid var(--line);border-radius:14px;overflow:hidden}
.section-head{padding:24px 28px;border-bottom:1px solid var(--line);display:flex;align-items:baseline;gap:16px}
.section-head h2{font-family:var(--display);font-weight:600;font-size:20px;letter-spacing:-.01em;margin:0}
.section-head .num{font-family:var(--mono);font-size:11px;letter-spacing:.18em;color:var(--muted-2);text-transform:uppercase}
.section-head .desc{margin-left:auto;color:var(--muted);font-size:13px}
.section-body{padding:28px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
.field{display:flex;flex-direction:column}
.field.full{grid-column:1/-1}
.field label{font-family:var(--mono);font-size:11px;letter-spacing:.18em;color:var(--muted);text-transform:uppercase;margin-bottom:8px}
.field input, .field textarea{width:100%;background:var(--bg-2);border:1px solid var(--line-2);border-radius:8px;padding:13px 14px;color:var(--text);font-family:inherit;font-size:15px;transition:all .2s ease;outline:none}
.field input:focus, .field textarea:focus{border-color:var(--accent);background:#0d0d0d}
.field textarea{resize:vertical;min-height:90px}
.field .hint{font-size:12px;color:var(--muted-2);margin-top:6px}

/* Tier card */
.tier{background:var(--bg-2);border:1px solid var(--line);border-radius:10px;padding:22px}
.tier h3{font-family:var(--display);font-weight:600;font-size:16px;margin:0 0 16px;color:var(--accent);letter-spacing:.01em}

/* Sticky save bar */
.save-bar{position:sticky;bottom:0;background:linear-gradient(180deg,transparent,#0a0a0a 30%);padding:32px 0 0;margin-top:40px;display:flex;justify-content:space-between;align-items:center;gap:16px}
.save-bar .save-info{color:var(--muted);font-size:13px}
.save-bar button{font-size:15px;padding:14px 24px}

/* Login screen */
.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:
  radial-gradient(900px 600px at 50% 30%, rgba(168,230,53,.06), transparent 60%),
  #060606;}
.login-card{width:100%;max-width:420px;background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:40px}
.login-card .brand{justify-content:center;margin-bottom:32px;font-size:24px}
.login-card h1{font-size:22px;text-align:center;margin-bottom:8px}
.login-card .lead{text-align:center;font-size:13.5px;margin-bottom:28px}
.login-card .field{margin-bottom:16px}
.login-card button{width:100%;justify-content:center;margin-top:8px}
.login-card .note{margin-top:24px;padding-top:20px;border-top:1px solid var(--line);font-family:var(--mono);font-size:11px;letter-spacing:.04em;color:var(--muted-2);text-align:center;line-height:1.7}

@media (max-width: 720px){
  .topbar{padding:14px 18px}
  main{padding:32px 18px 80px}
  h1{font-size:32px}
  .grid, .grid-3{grid-template-columns:1fr}
  .section-head{flex-wrap:wrap;gap:8px}
  .section-head .desc{margin-left:0;flex-basis:100%}
  .section-body{padding:20px}
  .top-actions .btn-text{display:none}
}
</style>
</head>
<body>

<?php if (!is_authed()): ?>
  <!-- ============== LOGIN ============== -->
  <div class="login-wrap">
    <div class="login-card">
      <div class="brand">PROVE<span class="x">X</span> <span class="pill">Admin</span></div>
      <h1>Bejelentkezés</h1>
      <p class="lead">Itt szerkesztheted a weboldal adatait.</p>

      <?php if ($login_error): ?>
        <div class="toast err" style="margin-bottom:20px">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= h($login_error) ?>
        </div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <div class="field">
          <label for="lu">Felhasználónév</label>
          <input id="lu" type="text" name="user" required autofocus />
        </div>
        <div class="field">
          <label for="lp">Jelszó</label>
          <input id="lp" type="password" name="pass" required />
        </div>
        <button type="submit" name="login_submit" value="1" class="btn btn-primary">
          Bejelentkezés
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
        </button>
      </form>

      <div class="note">
        Felejtett jelszó? Nyisd meg az <code>admin.php</code> fájlt és<br/>változtasd meg az <code>ADMIN_PASS</code> értékét.
      </div>
    </div>
  </div>

<?php else: ?>
  <!-- ============== ADMIN ============== -->
  <div class="topbar">
    <div class="brand">PROVE<span class="x">X</span> <span class="pill">Admin</span></div>
    <div class="top-actions">
      <a href="index.html" class="btn btn-text" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px"><path d="M15 3h6v6M14 10l7-7M5 12v7a2 2 0 0 0 2 2h7"/></svg>
          Oldal megnyitása
      </a>
      <a href="?logout=1" class="btn btn-ghost">Kilépés</a>
    </div>
  </div>

  <main>
    <div class="eyebrow">Backoffice</div>
    <h1>Tartalom szerkesztése</h1>
    <p class="lead">Itt módosíthatod a weboldal főbb adatait. A változások mentés után azonnal megjelennek az élő oldalon.</p>

    <?php if ($save_msg): ?>
      <div class="toast ok">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        <?= h($save_msg) ?>
      </div>
    <?php endif; ?>
    <?php if ($save_err): ?>
      <div class="toast err">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= h($save_err) ?>
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="off">

      <!-- ============== Kapcsolat ============== -->
      <section class="section">
        <header class="section-head">
          <span class="num">01</span>
          <h2>Kapcsolati adatok</h2>
          <span class="desc">Telefon, email, cím — minden helyen frissül</span>
        </header>
        <div class="section-body">
          <div class="grid">
            <div class="field">
              <label for="phone">Telefonszám</label>
              <input id="phone" type="text" name="phone" value="<?= h($c['phone']) ?>" required />
              <div class="hint">A <code>tel:</code> link automatikusan generálódik belőle.</div>
            </div>
            <div class="field">
              <label for="email">Email</label>
              <input id="email" type="email" name="email" value="<?= h($c['email']) ?>" required />
            </div>
            <div class="field">
              <label for="addressShort">Műhely címe</label>
              <input id="addressShort" type="text" name="addressShort" value="<?= h($c['addressShort']) ?>" />
            </div>
            <div class="field">
              <label for="addressNote">Cím megjegyzés</label>
              <input id="addressNote" type="text" name="addressNote" value="<?= h($c['addressNote']) ?>" />
              <div class="hint">Pl. „Pontos cím egyeztetés után" — a cím alatt jelenik meg.</div>
            </div>
          </div>
        </div>
      </section>

      <!-- ============== Nyitvatartás ============== -->
      <section class="section">
        <header class="section-head">
          <span class="num">02</span>
          <h2>Nyitvatartás &amp; válaszidő</h2>
          <span class="desc">Több helyen jelenik meg az oldalon</span>
        </header>
        <div class="section-body">
          <div class="grid">
            <div class="field">
              <label for="hoursMain">Hétköznapok</label>
              <input id="hoursMain" type="text" name="hoursMain" value="<?= h($c['hoursMain']) ?>" />
              <div class="hint">Pl. „H–P · 08:00 – 18:00"</div>
            </div>
            <div class="field">
              <label for="hoursSaturday">Szombat</label>
              <input id="hoursSaturday" type="text" name="hoursSaturday" value="<?= h($c['hoursSaturday']) ?>" />
              <div class="hint">Pl. „Szo · előre egyeztetve" vagy „Szo · 09:00 – 14:00"</div>
            </div>
            <div class="field">
              <label for="responseTime">Válaszidő (fő)</label>
              <input id="responseTime" type="text" name="responseTime" value="<?= h($c['responseTime']) ?>" />
            </div>
            <div class="field">
              <label for="responseTimeSub">Válaszidő (megjegyzés)</label>
              <input id="responseTimeSub" type="text" name="responseTimeSub" value="<?= h($c['responseTimeSub']) ?>" />
            </div>
          </div>
        </div>
      </section>

      <!-- ============== Hero / Promó ============== -->
      <section class="section">
        <header class="section-head">
          <span class="num">03</span>
          <h2>Promóciós szöveg</h2>
          <span class="desc">A hero képen megjelenő villogó badge</span>
        </header>
        <div class="section-body">
          <div class="field">
            <label for="heroBadge">Hero badge szöveg</label>
            <input id="heroBadge" type="text" name="heroBadge" value="<?= h($c['heroBadge']) ?>" />
            <div class="hint">Tartsd rövidnek — pl. akciós ajánlat, új szolgáltatás, foglalási info.</div>
          </div>
        </div>
      </section>

      <!-- ============== Árak ============== -->
      <section class="section">
        <header class="section-head">
          <span class="num">04</span>
          <h2>Csomag árak</h2>
          <span class="desc">A 3 árcsomag „-tól" ára és időtartama</span>
        </header>
        <div class="section-body">
          <div class="grid-3">
            <?php for ($i = 0; $i < 3; $i++): ?>
              <div class="tier">
                <h3><?= h($tierNames[$i]) ?></h3>
                <div class="field" style="margin-bottom:14px">
                  <label for="p<?= $i ?>p">Ár</label>
                  <input id="p<?= $i ?>p" type="text" name="pricing[<?= $i ?>][price]" value="<?= h($pricing[$i]['price'] ?? '') ?>" />
                </div>
                <div class="field">
                  <label for="p<?= $i ?>d">Időtartam</label>
                  <input id="p<?= $i ?>d" type="text" name="pricing[<?= $i ?>][duration]" value="<?= h($pricing[$i]['duration'] ?? '') ?>" />
                </div>
              </div>
            <?php endfor; ?>
          </div>
        </div>
      </section>

      <div class="save-bar">
        <div class="save-info">A mentés után frissítsd a publikus oldalt (F5) az ellenőrzéshez.</div>
        <button type="submit" name="save_submit" value="1" class="btn btn-primary">
          Mentés
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px"><polyline points="20 6 9 17 4 12"/></svg>
        </button>
      </div>
    </form>
  </main>
<?php endif; ?>

</body>
</html>
