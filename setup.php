<?php
/**
 * setup.php — Hulppagina om je team-ID en poule-ID te vinden
 * Alleen lokaal gebruiken; verwijder of blokkeer dit bestand op productie.
 */
declare(strict_types=1);
define('CACHE_TTL', 60);
define('CACHE_DIR', __DIR__ . '/cache');
require_once __DIR__ . '/includes/api.php';

$search    = trim($_GET['q']   ?? '');
$clubId    = trim($_GET['club'] ?? '');
$clubName  = trim($_GET['cname'] ?? '');

$clubs     = [];
$teams     = [];
$searchErr = null;

if ($search !== '') {
    $clubs = search_clubs($search);
    if ($clubs === null) {
        $clubs     = [];
        $searchErr = 'API niet bereikbaar.';
    }
}

if ($clubId !== '') {
    $teams = get_club_teams($clubId);
    if ($teams === null) $teams = [];
}

function e(mixed $v): string { return htmlspecialchars((string)$v); }
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Hockey-iframe – Setup</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; color: #1a1a1a; }
    h1   { font-size: 1.4rem; margin-bottom: .25rem; }
    p.sub { color: #555; margin-top: 0; }
    form { display: flex; gap: .5rem; margin-bottom: 1.5rem; }
    input[type=text] { flex: 1; padding: .5rem .75rem; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; }
    button { padding: .5rem 1rem; background: #003087; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 1rem; }
    button:hover { background: #002060; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; font-size: .9rem; }
    th, td { text-align: left; padding: .5rem .75rem; border-bottom: 1px solid #e8e8e8; }
    th { background: #f5f5f5; font-weight: 600; }
    code { background: #f0f0f0; padding: .1em .4em; border-radius: 3px; font-size: .95em; }
    .copy-box { background: #f8f8f8; border: 1px solid #ddd; border-radius: 4px; padding: .75rem 1rem; font-family: monospace; font-size: .9rem; white-space: pre-wrap; margin-bottom: 1rem; }
    .notice { background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: .6rem 1rem; margin-bottom: 1rem; }
    .error  { background: #fdecea; border-color: #f44336; }
    a { color: #003087; }
    .badge { display: inline-block; background: #003087; color: #fff; border-radius: 3px; padding: .1em .4em; font-size: .8em; cursor: pointer; }
    .badge:hover { background: #002060; }
    h2 { font-size: 1.1rem; margin-bottom: .5rem; }
    .back { margin-bottom: 1rem; display: block; }
  </style>
</head>
<body>

<h1>Hockey-iframe — Setup</h1>
<p class="sub">Zoek je club om het team-ID en poule-ID te vinden die je in <code>config.php</code> nodig hebt.</p>

<!-- Step 1: club search -->
<h2>Stap 1 – Zoek je club</h2>
<form method="get" action="">
  <input type="text" name="q" value="<?= e($search) ?>" placeholder="Clubnaam (bijv. HCH, Quick, Amsterdam)" autofocus>
  <?php if ($clubId): ?>
    <input type="hidden" name="club" value="<?= e($clubId) ?>">
    <input type="hidden" name="cname" value="<?= e($clubName) ?>">
  <?php endif; ?>
  <button type="submit">Zoeken</button>
</form>

<?php if ($searchErr): ?>
  <div class="notice error"><?= e($searchErr) ?></div>
<?php endif; ?>

<?php if ($search !== '' && empty($clubs) && !$searchErr): ?>
  <div class="notice">Geen clubs gevonden voor "<strong><?= e($search) ?></strong>". Probeer een kortere zoekterm.</div>
<?php endif; ?>

<?php if (!empty($clubs)): ?>
  <table>
    <thead><tr><th>Club</th><th>Stad</th><th>ID</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($clubs as $club):
        $cid   = $club['id'] ?? $club['abbreviation'] ?? '?';
        $cname = $club['name'] ?? '?';
        $city  = $club['city'] ?? '–';
      ?>
        <tr>
          <td><?= e($cname) ?></td>
          <td><?= e($city) ?></td>
          <td><code><?= e($cid) ?></code></td>
          <td>
            <a href="?q=<?= urlencode($search) ?>&club=<?= urlencode((string)$cid) ?>&cname=<?= urlencode($cname) ?>">
              Bekijk teams →
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<!-- Step 2: teams for selected club -->
<?php if ($clubId !== ''): ?>
  <a href="?q=<?= urlencode($search) ?>" class="back">← Terug naar zoekresultaten</a>
  <h2>Stap 2 – Teams van <?= e($clubName ?: $clubId) ?></h2>

  <?php if (empty($teams)): ?>
    <div class="notice">Geen teams gevonden voor deze club. Probeer een andere club.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Team</th><th>Type</th><th>Team-ID</th></tr></thead>
      <tbody>
        <?php foreach ($teams as $team):
          $tid   = $team['id'] ?? '?';
          $tname = $team['name'] ?? $team['short_name'] ?? '?';
          $ttype = $team['type'] ?? $team['category'] ?? '–';
        ?>
          <tr>
            <td><?= e($tname) ?></td>
            <td><?= e($ttype) ?></td>
            <td><code><?= e($tid) ?></code></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <h2>Stap 3 – Kopieer naar config.php</h2>
    <p>Kopieer het team-ID van je team uit de tabel hierboven en vul het in:</p>
    <div class="copy-box">define('TEAM_ID',   '<span style="color:#c00">JOUW_TEAM_ID</span>');
define('CLUB_NAME', '<?= e($clubName) ?>');
define('TEAM_NAME', 'Heren 1');   // pas aan naar jouw team</div>

    <p><strong>Poule-ID (voor de stand):</strong> het poule-ID kun je vinden door een wedstrijd aan te klikken op
       <a href="https://hockey.nl/wedstrijden" target="_blank" rel="noopener">hockey.nl/wedstrijden</a>
       en de URL te bekijken — het cijfer achter <code>/poules/</code> is jouw poule-ID.
    </p>
  <?php endif; ?>
<?php endif; ?>

<!-- Raw API response viewer -->
<?php if ($clubId !== '' && !empty($teams)): ?>
  <details style="margin-top:2rem">
    <summary style="cursor:pointer;color:#555">Ruwe API-data (debug)</summary>
    <pre style="background:#f5f5f5;padding:1rem;overflow:auto;font-size:.8rem;border-radius:4px"><?= e(json_encode($teams, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
  </details>
<?php elseif ($search !== '' && !empty($clubs)): ?>
  <details style="margin-top:2rem">
    <summary style="cursor:pointer;color:#555">Ruwe API-data (debug)</summary>
    <pre style="background:#f5f5f5;padding:1rem;overflow:auto;font-size:.8rem;border-radius:4px"><?= e(json_encode($clubs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
  </details>
<?php endif; ?>

<hr style="margin:2rem 0;border:none;border-top:1px solid #ddd">
<p style="color:#888;font-size:.85rem">
  Dit bestand is alleen bedoeld voor lokale setup. Verwijder of blokkeer <code>setup.php</code> op een productieserver.
</p>

</body>
</html>
