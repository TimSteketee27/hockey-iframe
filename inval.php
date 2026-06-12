<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/api.php';
require_once __DIR__ . '/includes/rules.php';

/**
 * Invalcheck — mag een speler invallen?
 *
 * Kies het team waarin wordt ingevallen en het team waar de invaller vandaan
 * komt (beide van de eigen club). Oordeel volgens het KNHB Bondsreglement
 * 2025-2026 en de Tabel Klassengrenzen veldhockey. Zie includes/rules.php.
 */

$klasseOpties = [
    'Hoofdklasse', 'Promotieklasse', 'Overgangsklasse', 'Landelijk', 'Super',
    'Topklasse', 'Subtopklasse',
    '1e klasse', '2e klasse', '3e klasse', '4e klasse', '5e klasse',
    '6e klasse', '7e klasse', '8e klasse',
];

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

// Alleen veldhockeyteams: de checker dekt de veldregels
$veldTeams = array_values(array_filter($teams, fn($t) => ($t['hockey_type'] ?? '') === 'VE'));

$byId = [];
foreach ($veldTeams as $t) $byId[(string)$t['id']] = $t;

// ── Invoer ────────────────────────────────────────────────────
$doelId = trim((string)($_GET['doel'] ?? ''));
$bronId = trim((string)($_GET['bron'] ?? ''));

$doelKlasseOvr = trim((string)($_GET['doel_klasse'] ?? ''));
$bronKlasseOvr = trim((string)($_GET['bron_klasse'] ?? ''));
if (!in_array($doelKlasseOvr, $klasseOpties, true)) $doelKlasseOvr = '';
if (!in_array($bronKlasseOvr, $klasseOpties, true)) $bronKlasseOvr = '';

$tri = function (string $key): ?bool {
    $v = $_GET[$key] ?? '';
    return $v === '1' ? true : ($v === '0' ? false : null);
};
$opts = [
    'keeper'               => ($_GET['keeper'] ?? '') === '1',
    'lte11'                => $tri('lte11'),
    'geen_alternatief'     => $tri('alt'),
    'leeftijd_ok'          => $tri('leeftijd'),
    'beslissingswedstrijd' => $tri('beslissing'),
];

// Houd het "Extra gegevens"-paneel open over auto-submits heen (zie script onderaan het formulier)
$extraOpen = (($_GET['extra'] ?? '') === '1');
$detailsOpen = $extraOpen || $opts['keeper'] || $opts['lte11'] !== null
    || $opts['geen_alternatief'] !== null || $opts['leeftijd_ok'] !== null
    || $opts['beslissingswedstrijd'] !== null
    || $doelKlasseOvr !== '' || $bronKlasseOvr !== '';

// ── Beoordeling ───────────────────────────────────────────────
$result = null;
$bronProfiel = $doelProfiel = null;

function inval_lookup_class(array $team): ?string
{
    $pouleId = $team['recent_poule_id'] ?? null;
    if (!$pouleId) return null;
    $comp = get_team_competition((string)$team['id'], (string)$pouleId);
    return $comp['class_name'] ?? null;
}

if ($doelId !== '' && $bronId !== '' && isset($byId[$doelId], $byId[$bronId])) {
    $doelTeam = $byId[$doelId];
    $bronTeam = $byId[$bronId];

    $doelKlasse = $doelKlasseOvr !== '' ? $doelKlasseOvr : inval_lookup_class($doelTeam);
    $bronKlasse = $bronKlasseOvr !== '' ? $bronKlasseOvr : inval_lookup_class($bronTeam);

    $doelProfiel = inval_team_profile($doelTeam, $doelKlasse);
    $bronProfiel = inval_team_profile($bronTeam, $bronKlasse);

    $result = check_inval($bronProfiel, $doelProfiel, $opts);

    // HC Hilvarenbeek-beleid: waarschuw als een jeugdinvaller meer dan twee
    // jaarlagen omhoog gaat (KNHB staat het toe, maar de vereniging vindt het
    // leeftijdsverschil te groot).
    if (in_array($result['verdict'], ['ja', 'mits'], true)
        && $bronProfiel['kind'] === 'junior'
        && $doelProfiel['kind'] === 'junior'
    ) {
        $bronJaar = (int)ltrim($bronProfiel['age'], 'o');
        $doelJaar = (int)ltrim($doelProfiel['age'], 'o');
        if ($doelJaar - $bronJaar > 2) {
            $result['warnings'][] = 'HC Hilvarenbeek-beleid: dit invallen mag formeel'
                . ' volgens de KNHB-regels, maar het leeftijdsverschil van '
                . ($doelJaar - $bronJaar) . ' jaar tussen beide teams vinden wij als'
                . ' vereniging te groot. Stem dit altijd af met de jeugdcoördinator.';
        }
    }
}

function e(mixed $v): string { return htmlspecialchars((string)$v); }

function tri_select(string $name, ?bool $cur, string $jaLabel = 'Ja', string $neeLabel = 'Nee'): string
{
    $v = $cur === true ? '1' : ($cur === false ? '0' : '');
    $h  = '<select name="' . e($name) . '">';
    $h .= '<option value=""' . ($v === '' ? ' selected' : '') . '>Weet niet</option>';
    $h .= '<option value="1"' . ($v === '1' ? ' selected' : '') . '>' . e($jaLabel) . '</option>';
    $h .= '<option value="0"' . ($v === '0' ? ' selected' : '') . '>' . e($neeLabel) . '</option>';
    return $h . '</select>';
}

$verdictMeta = [
    'ja'       => ['✓', 'Mag invallen',            'verdict-ja'],
    'mits'     => ['!', 'Mag invallen, mits …',    'verdict-mits'],
    'nee'      => ['✗', 'Mag niet invallen',       'verdict-nee'],
    'onbekend' => ['?', 'Niet automatisch te bepalen', 'verdict-onbekend'],
];

// Embed-modus: stand-alone iframe voor www.hilverhockey.nl — geen widget-chrome,
// styling die aansluit bij de clubsite (assets/inval-embed.css).
$embed = (($_GET['embed'] ?? '') === '1');

$tabs = POULE_ID ? ['programma', 'uitslagen', 'stand'] : ['programma', 'uitslagen'];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e(CLUB_NAME) ?> – Invalcheck</title>
<?php if ($embed): ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=Open+Sans:wght@400;600;700&display=swap">
  <link rel="stylesheet" href="assets/inval-embed.css">
<?php else: ?>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    :root {
      --color-primary: <?= e(COLOR_PRIMARY) ?>;
      --color-accent:  <?= e(COLOR_ACCENT)  ?>;
      --color-bg:      <?= e(COLOR_BG)      ?>;
      --color-text:    <?= e(COLOR_TEXT)    ?>;
    }
  </style>
<?php endif; ?>
</head>
<body<?= $embed ? ' class="embed"' : '' ?>>

<?php if ($embed): ?>
<main class="inval-embed">

  <header class="inval-embed-head">
    <h2>Invalcheck</h2>
    <p class="inval-embed-intro">
      Check of een speler volgens de KNHB-invalregels mag invallen
      in een ander team van <?= e(CLUB_NAME) ?>.
    </p>
  </header>

<?php else: ?>
<div class="widget">

  <header class="widget-header">
    <div class="widget-title">
      <span class="club-name"><?= e(CLUB_NAME) ?></span>
      <span class="team-name">Invalcheck</span>
    </div>
  </header>

  <nav class="tabs" role="tablist">
    <?php foreach ($tabs as $t): ?>
      <a href="index.php?tab=<?= $t ?>" class="tab" role="tab" aria-selected="false">
        <?= match($t) { 'programma' => 'Programma', 'uitslagen' => 'Uitslagen', 'stand' => 'Stand' } ?>
      </a>
    <?php endforeach; ?>
    <a href="inval.php" class="tab active" role="tab" aria-selected="true">Invalcheck</a>
    <a href="kampioen.php" class="tab" role="tab" aria-selected="false">Kampioen?</a>
  </nav>
<?php endif; ?>

  <div class="content" role="tabpanel">

    <?php if ($apiError): ?>
      <div class="notice notice-error"><?= $apiError ?></div>
    <?php endif; ?>

    <form method="get" class="inval-form">
      <?php if ($embed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
      <div class="inval-row">
        <label>
          <span>Team waarin wordt ingevallen</span>
          <select name="doel" required>
            <option value="">— kies team —</option>
            <?php foreach ($veldTeams as $t): ?>
              <option value="<?= e($t['id']) ?>" <?= (string)$t['id'] === $doelId ? 'selected' : '' ?>>
                <?= e($t['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          <span>Team van de invaller</span>
          <select name="bron" required>
            <option value="">— kies team —</option>
            <?php foreach ($veldTeams as $t): ?>
              <option value="<?= e($t['id']) ?>" <?= (string)$t['id'] === $bronId ? 'selected' : '' ?>>
                <?= e($t['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>

      <?php if (!$embed): // tijdelijk geen extra gegevens in de embed, houdt het simpel ?>
      <details class="inval-extra" <?= $detailsOpen ? 'open' : '' ?>>
        <summary>Extra gegevens (scherpt het oordeel aan)</summary>
        <div class="inval-grid">
          <label><span>Invaller is (vaste) doelverdediger</span>
            <input type="checkbox" name="keeper" value="1" <?= $opts['keeper'] ? 'checked' : '' ?>>
          </label>
          <label><span>Ontvangend team heeft max. 11 beschikbare spelers</span>
            <?= tri_select('lte11', $opts['lte11']) ?>
          </label>
          <label><span>Geen invallers beschikbaar uit gelijk/lager niveau</span>
            <?= tri_select('alt', $opts['geen_alternatief'], 'Klopt, geen', 'Wel beschikbaar') ?>
          </label>
          <label><span>Speler voldoet aan leeftijdsgrens doelteam</span>
            <?= tri_select('leeftijd', $opts['leeftijd_ok']) ?>
          </label>
          <label><span>Het betreft een beslissingswedstrijd</span>
            <?= tri_select('beslissing', $opts['beslissingswedstrijd']) ?>
          </label>
          <label><span>Klasse doelteam (handmatig)</span>
            <select name="doel_klasse">
              <option value="">— automatisch —</option>
              <?php foreach ($klasseOpties as $k): ?>
                <option <?= $k === $doelKlasseOvr ? 'selected' : '' ?>><?= e($k) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label><span>Klasse bronteam (handmatig)</span>
            <select name="bron_klasse">
              <option value="">— automatisch —</option>
              <?php foreach ($klasseOpties as $k): ?>
                <option <?= $k === $bronKlasseOvr ? 'selected' : '' ?>><?= e($k) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
      </details>

      <input type="hidden" name="extra" value="<?= $detailsOpen ? '1' : '0' ?>">
      <?php endif; ?>
      <button type="submit" class="inval-submit">Check invalregels</button>
    </form>

    <script>
    // Auto-check: submit zodra beide teams gekozen zijn en bij elke wijziging
    // daarna. De knop blijft staan als fallback zonder JavaScript.
    (function () {
      var form = document.querySelector('.inval-form');
      var extra = form.querySelector('details.inval-extra');
      form.querySelector('.inval-submit').style.display = 'none';
      if (extra) extra.addEventListener('toggle', function () {
        form.elements.extra.value = extra.open ? '1' : '0';
      });
      form.addEventListener('change', function () {
        if (form.elements.doel.value && form.elements.bron.value) form.submit();
      });
    })();
    </script>

    <?php if ($result !== null): [$icon, $title, $cls] = $verdictMeta[$result['verdict']]; ?>
      <div class="verdict-card <?= $cls ?>">
        <div class="verdict-head">
          <span class="verdict-icon"><?= $icon ?></span>
          <div>
            <strong><?= e($title) ?></strong>
            <div class="verdict-sub">
              <?= e($bronProfiel['name']) ?> (<?= e(inval_level_label($bronProfiel)) ?>)
              → <?= e($doelProfiel['name']) ?> (<?= e(inval_level_label($doelProfiel)) ?>)
            </div>
          </div>
        </div>

        <?php if ($result['reasons']): ?>
          <ul class="verdict-list">
            <?php foreach ($result['reasons'] as $r): ?><li><?= e($r) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if ($result['conditions']): ?>
          <p class="verdict-label">Voorwaarden:</p>
          <ul class="verdict-list verdict-conditions">
            <?php foreach ($result['conditions'] as $c): ?><li><?= e($c) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if ($result['warnings']): ?>
          <p class="verdict-label">Let op:</p>
          <ul class="verdict-list verdict-warnings">
            <?php foreach ($result['warnings'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if ($result['refs'] && !$embed): ?>
          <p class="verdict-refs">Bron: <?= e(implode(' · ', array_unique($result['refs']))) ?></p>
        <?php endif; ?>
      </div>

      <p class="inval-disclaimer">
        Dit oordeel is een hulpmiddel op teamniveau (veldhockey, seizoen 2025-2026). Spelerspecifieke
        zaken — niveaubepaling, vastspelen, teamopgave-posities en dispensaties — kan deze check niet
        zien. Het <a href="https://www.knhb.nl/over-knhb/reglementen/" target="_blank" rel="noopener">
        KNHB Bondsreglement</a> is altijd leidend.
      </p>
    <?php endif; ?>

  </div><!-- .content -->

<?php if ($embed): ?>
</main>

<script>
// Meld de eigen hoogte aan de bovenliggende pagina, zodat het iframe daar
// zonder scrollbalk kan meegroeien (zie embed-voorbeeld.html).
(function () {
  var post = function () {
    parent.postMessage({ type: 'invalcheck:height', height: document.documentElement.scrollHeight }, '*');
  };
  if ('ResizeObserver' in window) new ResizeObserver(post).observe(document.body);
  window.addEventListener('load', post);
})();
</script>

</body>
</html>
<?php else: ?>
  <footer class="widget-footer">
    <a href="https://www.knhb.nl/kenniscentrum/competities/invallen/" target="_blank" rel="noopener">KNHB invalregels</a>
  </footer>

</div><!-- .widget -->

</body>
</html>
<?php endif; ?>
