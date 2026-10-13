<?php
// =================================================================
// PROVEX — Véleményezős oldal (review link)
// Bence / bárki ezen a linken megnézheti az oldalt és megjegyzést
// írhat, hogy mit változtassunk. A megjegyzések a feedback.json-ba
// kerülnek; az "Összes másolása" gombbal egyben átadhatók szerkesztésre.
// =================================================================

define('FEEDBACK_FILE', __DIR__ . '/feedback.json');
define('MAX_COMMENTS', 500);
define('MAX_LEN', 3000);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function load_feedback() {
    if (!file_exists(FEEDBACK_FILE)) return [];
    $d = json_decode(file_get_contents(FEEDBACK_FILE), true);
    return is_array($d) ? $d : [];
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $text = trim((string)($_POST['text'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $where = trim((string)($_POST['where'] ?? ''));
    if (!empty($_POST['website'])) { exit; } // honeypot
    if ($text === '') {
        $err = 'Írj be egy megjegyzést.';
    } else {
        $items = load_feedback();
        if (count($items) >= MAX_COMMENTS) array_shift($items);
        $items[] = [
            'id'    => bin2hex(random_bytes(4)),
            'time'  => date('Y-m-d H:i'),
            'name'  => mb_substr($name, 0, 80),
            'where' => mb_substr($where, 0, 120),
            'text'  => mb_substr($text, 0, MAX_LEN),
            'done'  => false,
        ];
        $json = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (file_put_contents(FEEDBACK_FILE, $json, LOCK_EX) === false) {
            $err = 'Nem sikerült menteni (feedback.json jogosultság: chmod 664, chown www-data).';
        } else {
            header('Location: feedback.php?ok=1#list');
            exit;
        }
    }
}

$items = array_reverse(load_feedback());
$plain = '';
foreach (array_reverse($items) as $i => $c) {
    $plain .= ($i + 1) . '. ' . ($c['where'] ? '[' . $c['where'] . '] ' : '') . $c['text']
            . ($c['name'] ? ' — ' . $c['name'] : '') . "\n";
}
?><!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<meta name="robots" content="noindex,nofollow" />
<title>PROVEX — Vélemény / módosítási kérések</title>
<style>
:root{--bg:#0a0a0a;--surface:#161616;--line:rgba(255,255,255,.12);--text:#f4f4f4;--muted:#8a8a8a;--accent:#a8e635;--danger:#ff6b6b}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,sans-serif;display:grid;grid-template-columns:1fr 380px;height:100vh}
iframe{width:100%;height:100%;border:0;background:#000}
aside{border-left:1px solid var(--line);padding:18px;overflow:auto;background:var(--surface)}
h1{font-size:17px;margin:0 0 4px}
p.sub{color:var(--muted);margin:0 0 14px;font-size:13px}
label{display:block;font-size:12px;color:var(--muted);margin:10px 0 4px}
input,textarea{width:100%;background:#0f0f0f;color:var(--text);border:1px solid var(--line);border-radius:8px;padding:9px 10px;font:inherit}
textarea{min-height:130px;resize:vertical}
button{margin-top:12px;background:var(--accent);color:#000;border:0;border-radius:8px;padding:10px 16px;font-weight:700;cursor:pointer}
button.ghost{background:transparent;color:var(--text);border:1px solid var(--line)}
.ok{color:var(--accent);font-size:13px}.err{color:var(--danger);font-size:13px}
.c{border-top:1px solid var(--line);padding:10px 0}
.c small{color:var(--muted)}.c b{color:var(--accent);font-weight:600}
.hp{position:absolute;left:-9999px}
a.open{color:var(--accent);font-size:13px}
@media(max-width:900px){body{grid-template-columns:1fr;grid-template-rows:55vh auto;height:auto}aside{border-left:0;border-top:1px solid var(--line)}}
</style>
</head>
<body>
<iframe src="index.html" title="PROVEX oldal"></iframe>
<aside>
  <h1>Mit változtassunk?</h1>
  <p class="sub">Nézd végig az oldalt balra, és írd le ide, mit módosítanál. <a class="open" href="index.html" target="_blank">Megnyitás új lapon ↗</a></p>
  <?php if (isset($_GET['ok'])): ?><div class="ok">Köszi, a megjegyzés mentve.</div><?php endif; ?>
  <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
  <form method="post" action="feedback.php">
    <label for="name">Név (opcionális)</label>
    <input id="name" name="name" maxlength="80" />
    <label for="where">Hol? (pl. Hero, Árak, GYIK)</label>
    <input id="where" name="where" maxlength="120" />
    <label for="text">Megjegyzés</label>
    <textarea id="text" name="text" required maxlength="<?= MAX_LEN ?>" placeholder="Mit változtassunk, töröljünk, írjunk át?"></textarea>
    <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off" />
    <button type="submit">Küldés</button>
  </form>

  <h1 id="list" style="margin-top:26px">Eddigi megjegyzések (<?= count($items) ?>)</h1>
  <?php if ($items): ?>
    <button type="button" class="ghost" onclick="navigator.clipboard.writeText(document.getElementById('plain').value).then(()=>this.textContent='Másolva ✓')">Összes másolása</button>
    <textarea id="plain" readonly hidden><?= h($plain) ?></textarea>
  <?php endif; ?>
  <?php foreach ($items as $c): ?>
    <div class="c">
      <small><?= h($c['time']) ?><?= $c['name'] ? ' · ' . h($c['name']) : '' ?></small>
      <?php if ($c['where']): ?><div><b><?= h($c['where']) ?></b></div><?php endif; ?>
      <div><?= nl2br(h($c['text'])) ?></div>
    </div>
  <?php endforeach; ?>
</aside>
</body>
</html>
