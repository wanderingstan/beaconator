<?php
// Beaconator v0 — one-file broadcast layer.
//   POST  (who, text, url, key)                         -> add a beacon
//   GET  ?format=json  [&who=name] [&since=id]         -> all beacons as JSON
//   GET  ?format=rss   [&who=name]                     -> RSS feed
//   GET  /                                             -> dumb default viewer + post form
// v0: no real auth. Posting needs the shared KEY below (trusted friends only).

const KEY = 'beacon';            // change me / share with friends
const DB_FILE = __DIR__ . '/data/beacons.sqlite';

if (!is_dir(__DIR__ . '/data')) {
  mkdir(__DIR__ . '/data', 0755, true);
  file_put_contents(__DIR__ . '/data/.htaccess', "Require all denied\nDeny from all\n");
}
$db = new PDO('sqlite:' . DB_FILE);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE IF NOT EXISTS beacons (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  who TEXT NOT NULL,
  text TEXT NOT NULL,
  url TEXT,
  created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\'))
)');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$self = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');

// ---- POST a beacon (form or JSON body) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $in = $_POST;
  if (!$in) $in = json_decode(file_get_contents('php://input'), true) ?: [];
  $key = $_GET['key'] ?? $in['key'] ?? '';
  $who = trim($in['who'] ?? '');
  $text = trim($in['text'] ?? '');
  $url = trim($in['url'] ?? '') ?: null;
  $wantsHtml = !empty($_POST) && empty($_GET['format']);
  if ($key !== KEY) { http_response_code(403); echo json_encode(['error' => 'bad key']); exit; }
  if ($who === '' || $text === '') { http_response_code(400); echo json_encode(['error' => 'who and text required']); exit; }
  $st = $db->prepare('INSERT INTO beacons (who, text, url) VALUES (?, ?, ?)');
  $st->execute([mb_substr($who, 0, 50), mb_substr($text, 0, 2000), $url]);
  $id = $db->lastInsertId();
  if ($wantsHtml) { setcookie('who', $who, time() + 31536000); header('Location: ' . $self); exit; }
  header('Content-Type: application/json');
  echo json_encode(['ok' => true, 'id' => (int)$id]);
  exit;
}

// ---- Query ----
$where = []; $args = [];
if (!empty($_GET['who'])) { $where[] = 'who = ?'; $args[] = $_GET['who']; }
if (!empty($_GET['since'])) { $where[] = 'id > ?'; $args[] = (int)$_GET['since']; }
$sql = 'SELECT * FROM beacons' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 500';
$st = $db->prepare($sql); $st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$format = $_GET['format'] ?? 'html';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

if ($format === 'json') {
  header('Content-Type: application/json');
  echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

if ($format === 'rss') {
  header('Content-Type: application/rss+xml; charset=utf-8');
  $title = 'Beaconator' . (!empty($_GET['who']) ? ' — ' . $_GET['who'] : '');
  echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
  echo '<rss version="2.0"><channel><title>' . $h($title) . '</title><link>' . $h($self) . '</link><description>Beacons from friends</description>';
  foreach ($rows as $r) {
    echo '<item><title>' . $h($r['who'] . ': ' . mb_strimwidth($r['text'], 0, 80, '…')) . '</title>';
    echo '<description>' . $h($r['text']) . '</description>';
    echo '<author>' . $h($r['who']) . '</author>';
    if ($r['url']) echo '<link>' . $h($r['url']) . '</link>';
    echo '<guid isPermaLink="false">beacon-' . $r['id'] . '</guid>';
    echo '<pubDate>' . date(DATE_RSS, strtotime($r['created_at'])) . '</pubDate></item>';
  }
  echo '</channel></rss>';
  exit;
}

// ---- Dumb default HTML viewer ----
$people = $db->query('SELECT who, COUNT(*) n FROM beacons GROUP BY who ORDER BY MAX(id) DESC')->fetchAll(PDO::FETCH_ASSOC);
$me = $_COOKIE['who'] ?? '';
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Beaconator</title>
<style>
  :root { --bg:#fbfaf7; --fg:#1d1d1f; --muted:#6b6b70; --card:#fff; --line:#e6e3dc; --accent:#e8590c; }
  @media (prefers-color-scheme: dark) { :root { --bg:#141414; --fg:#eee; --muted:#9a9a9f; --card:#1e1e1e; --line:#333; --accent:#ff8a3d; } }
  body { margin:0; background:var(--bg); color:var(--fg); font:16px/1.5 system-ui, sans-serif; }
  main { max-width:680px; margin:0 auto; padding:24px 16px 64px; }
  h1 { margin:0 0 4px; font-size:28px; } h1 span { color:var(--accent); }
  .sub { color:var(--muted); margin:0 0 20px; }
  form { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:12px; display:grid; gap:8px; margin-bottom:20px; }
  input, textarea, button { font:inherit; padding:8px 10px; border-radius:8px; border:1px solid var(--line); background:var(--bg); color:var(--fg); }
  textarea { min-height:60px; resize:vertical; }
  .row { display:flex; gap:8px; } .row input { flex:1; min-width:0; }
  button { background:var(--accent); color:#fff; border:0; font-weight:600; cursor:pointer; }
  .people { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:16px; }
  .people a { font-size:13px; padding:3px 10px; border-radius:99px; border:1px solid var(--line); color:var(--fg); text-decoration:none; }
  .b { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:10px 14px; margin-bottom:10px; }
  .b .meta { font-size:13px; color:var(--muted); } .b .meta strong { color:var(--fg); }
  .b p { margin:4px 0 0; white-space:pre-wrap; word-wrap:break-word; }
  .api { font-size:13px; color:var(--muted); border-top:1px solid var(--line); margin-top:28px; padding-top:12px; }
  code { font-size:12px; word-break:break-all; }
</style></head>
<body><main>
  <h1>🛰️ Beacon<span>ator</span></h1>
  <p class="sub">Broadcast what you're up to. Read it however you like.</p>

  <form method="post" action="<?= $h($self) ?>">
    <div class="row"><input name="who" placeholder="your name" value="<?= $h($me) ?>" required>
    <input name="key" placeholder="shared key" value="<?= $h($_COOKIE['key'] ?? '') ?>" required></div>
    <textarea name="text" placeholder="What are you up to?" required></textarea>
    <div class="row"><input name="url" placeholder="link (optional)"><button>Beacon</button></div>
  </form>

  <?php if ($people): ?><div class="people">
    <a href="<?= $h($self) ?>">everyone</a>
    <?php foreach ($people as $p): ?><a href="?who=<?= urlencode($p['who']) ?>"><?= $h($p['who']) ?> · <?= $p['n'] ?></a><?php endforeach; ?>
  </div><?php endif; ?>

  <?php foreach ($rows as $r): ?>
    <div class="b"><div class="meta"><strong><?= $h($r['who']) ?></strong> · <time datetime="<?= $h($r['created_at']) ?>"><?= $h($r['created_at']) ?></time></div>
      <p><?= $h($r['text']) ?></p>
      <?php if ($r['url']): ?><div class="meta"><a href="<?= $h($r['url']) ?>"><?= $h($r['url']) ?></a></div><?php endif; ?></div>
  <?php endforeach; if (!$rows): ?><p class="sub">No beacons yet. Be the first.</p><?php endif; ?>

  <div class="api"><strong>Build your own viewer:</strong><br>
    JSON: <code><?= $h($self) ?>?format=json</code> (add <code>&amp;who=name</code>, <code>&amp;since=id</code>)<br>
    RSS: <code><?= $h($self) ?>?format=rss</code> (add <code>&amp;who=name</code>)<br>
    Post: <code>curl -d who=you -d text=hi -d key=KEY "<?= $h($self) ?>?format=json"</code>
  </div>
</main>
<script>
  document.querySelectorAll('time').forEach(t => { try { t.textContent = new Date(t.dateTime).toLocaleString(); } catch (e) {} });
  document.querySelector('form').addEventListener('submit', e => { document.cookie = 'key=' + encodeURIComponent(e.target.key.value) + ';max-age=31536000;path=/'; });
</script>
</body></html>
