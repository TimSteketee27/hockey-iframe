<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/api.php';
require_once __DIR__ . '/includes/kansen.php';

/**
 * Kampioenscheck — stand-alone iframe voor www.hilverhockey.nl.
 *
 * Zelfde stijl en opbouw als inval.php?embed=1 (assets/inval-embed.css):
 * kies eerst de competitievorm (veld of zaal), dan een team, en zie of dat
 * team nog kampioen kan worden — met de uitleg waarom. Inhoudelijk gelijk
 * aan kampioen.php / kampioen-zaal.php, maar één team per keer, zodat er
 * maar één poule opgehaald hoeft te worden.
 *
 * De check draait automatisch zodra vorm + team gekozen zijn (de knop is
 * fallback zonder JavaScript). De pagina meldt zijn hoogte aan de parent
 * via postMessage ({type:'kampioenscheck:height'}), zie embed-voorbeeld.html.
 */

function e(mixed $v): string { return htmlspecialchars((string)$v); }

$apiError = null;
$teams    = [];

if (!defined('CLUB_ID') || !CLUB_ID) {
    $apiError = 'Geen club geconfigureerd. Vul <code>CLUB_ID</code> in <strong>config.php</strong> in.';
} else {
    $teams = get_club_teams(CLUB_ID);
    if ($teams === null) {
        $teams    = [];
        $apiError = 'De KNHB-API is momenteel niet bereikbaar; teamlijst kan niet worden geladen.';
    }
}

// ── Invoer ────────────────────────────────────────────────────
$vorm   = in_array($_GET['vorm'] ?? '', ['veld', 'zaal'], true) ? $_GET['vorm'] : '';
$teamId = trim((string)($_GET['team'] ?? ''));

// Teams van de gekozen vorm, zonder jongste jeugd (geen ranglijsten, art. 2.6),
// gegroepeerd per categorie voor <optgroup>
$keuzeGroepen = [];
$byId = [];
if ($vorm !== '') {
    $type = $vorm === 'zaal' ? 'ZA' : 'VE';
    foreach ($teams as $t) {
        if (($t['hockey_type'] ?? '') !== $type) continue;
        if (($t['category_group_name'] ?? '') === 'Jongste jeugd') continue;
        $keuzeGroepen[(string)($t['category_group_name'] ?? 'Overig')][] = $t;
        $byId[(string)$t['id']] = $t;
    }
}

// ── Beoordeling (één team = één poule-call) ───────────────────
$result   = null;
$gekozen  = null;
$compNaam = '';

if ($vorm !== '' && $teamId !== '' && isset($byId[$teamId])) {
    $gekozen = $byId[$teamId];
    $pouleId = $gekozen['recent_poule_id'] ?? null;
    if (!$pouleId) {
        $result = ['verdict' => 'onbekend', 'stats' => ['finished' => null],
                   'reasons' => ['Voor dit team is geen poule bekend.']];
    } else {
        $resp = hapi_get('/poules/' . rawurlencode((string)$pouleId));
        $pd   = $resp['data'] ?? null;
        if (!is_array($pd)) {
            $result = ['verdict' => 'onbekend', 'stats' => ['finished' => null],
                       'reasons' => ['De poule van dit team kon niet worden opgehaald.']];
        } else {
            $compNaam = trim((string)($pd['competition']['national_competition_name']
                ?? $pd['competition']['name'] ?? '') . ' · ' . (string)($pd['name'] ?? ''), ' ·');
            $result = kampioen_check($pd['standings'] ?? [], $pd['matches'] ?? [], $teamId);
        }
    }
}

// Titel per oordeel; bij 'nee' hangt de formulering af van of de competitie klaar is
$verdictMeta = [
    'kampioen' => ['★', 'Kampioen!',                    'verdict-ja'],
    'bijna'    => ['!', 'Bijna kampioen',               'verdict-mits'],
    'mogelijk' => ['?', 'Kan nog kampioen worden',      'verdict-mogelijk'],
    'nee'      => ['✗', 'Kan geen kampioen meer worden', 'verdict-nee'],
    'onbekend' => ['–', 'Niet te bepalen',              'verdict-onbekend'],
];
if ($result !== null && $result['verdict'] === 'nee' && ($result['stats']['finished'] ?? false)) {
    $verdictMeta['nee'][1] = 'Geen kampioen geworden';
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e(CLUB_NAME) ?> – Kampioenscheck</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=Open+Sans:wght@400;600;700&display=swap">
  <link rel="stylesheet" href="assets/inval-embed.css">
</head>
<body class="embed">

<main class="inval-embed">

  <header class="inval-embed-head">
    <h2>Kampioenscheck</h2>
    <p class="inval-embed-intro">
      Kan een team van <?= e(CLUB_NAME) ?> nog kampioen worden — of is het dat al?
      Kies de competitie en het team.
    </p>
  </header>

  <?php if ($apiError): ?>
    <div class="notice notice-error"><?= $apiError ?></div>
  <?php endif; ?>

  <form method="get" class="inval-form">
    <div class="inval-row">
      <label>
        <span>Competitie</span>
        <select name="vorm" required>
          <option value="">— kies competitie —</option>
          <option value="veld" <?= $vorm === 'veld' ? 'selected' : '' ?>>Buiten (veld)</option>
          <option value="zaal" <?= $vorm === 'zaal' ? 'selected' : '' ?>>Zaal</option>
        </select>
      </label>
      <?php if ($vorm !== ''): ?>
        <label>
          <span>Team</span>
          <select name="team" required>
            <option value="">— kies team —</option>
            <?php foreach ($keuzeGroepen as $cat => $catTeams): ?>
              <optgroup label="<?= e($cat) ?>">
                <?php foreach ($catTeams as $t): ?>
                  <option value="<?= e($t['id']) ?>" <?= (string)$t['id'] === $teamId ? 'selected' : '' ?>>
                    <?= e($t['name']) ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
    </div>
    <button type="submit" class="inval-submit">Check kampioenskansen</button>
  </form>

  <script>
  // Auto-check: submit bij elke wijziging zodra de vorm gekozen is; bij een
  // vormwissel wordt de teamkeuze gewist (andere teamlijst). De knop blijft
  // staan als fallback zonder JavaScript.
  (function () {
    var form = document.querySelector('.inval-form');
    form.querySelector('.inval-submit').style.display = 'none';
    form.addEventListener('change', function (ev) {
      if (ev.target.name === 'vorm' && form.elements.team) form.elements.team.value = '';
      if (form.elements.vorm.value) form.submit();
    });
  })();
  </script>

  <?php if ($result !== null): [$icon, $title, $cls] = $verdictMeta[$result['verdict']];
    $s = $result['stats'];
  ?>
    <div class="verdict-card <?= $cls ?>">
      <div class="verdict-head">
        <span class="verdict-icon"><?= $icon ?></span>
        <div>
          <strong><?= e($title) ?></strong>
          <div class="verdict-sub">
            <?= e($gekozen['name'] ?? '') ?><?= $compNaam ? ' · ' . e($compNaam) : '' ?>
            <?php if (isset($s['rank'])): ?>
              — <?= $s['rank'] ?>e plaats · <?= $s['points'] ?> punten ·
              <?= $s['finished'] ? 'competitie gespeeld' : 'nog ' . $s['remaining'] . ' wedstrijd(en)' ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if ($result['reasons']): ?>
        <ul class="verdict-list">
          <?php foreach ($result['reasons'] as $r): ?><li><?= e($r) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <p class="verdict-refs">
        Bron: KNHB Bondsreglement art. 2.6/2.6.1 · Data: KNHB / HockeyWeerelt
      </p>
    </div>

    <p class="inval-disclaimer">
      Dit is de wiskundige stand van zaken op basis van de gepubliceerde uitslagen
      en het resterende programma. Het
      <a href="https://www.knhb.nl/over-knhb/reglementen/" target="_blank" rel="noopener">KNHB
      Bondsreglement</a> en de competitieleiding zijn altijd leidend.
    </p>
  <?php endif; ?>

</main>

<script>
// Meld de eigen hoogte aan de bovenliggende pagina, zodat het iframe daar
// zonder scrollbalk kan meegroeien (zie embed-voorbeeld.html).
(function () {
  var post = function () {
    parent.postMessage({ type: 'kampioenscheck:height', height: document.documentElement.scrollHeight }, '*');
  };
  if ('ResizeObserver' in window) new ResizeObserver(post).observe(document.body);
  window.addEventListener('load', post);
})();
</script>

</body>
</html>
