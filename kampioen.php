<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/api.php';
require_once __DIR__ . '/includes/kansen.php';

/**
 * Kampioenscheck — kan elk team van de club nog kampioen worden?
 *
 * Twee pagina's: dit bestand toont de buitencompetitie (veld);
 * kampioen-zaal.php zet $KAMP_TYPE = 'ZA' en include't dit bestand.
 *
 * Haalt per team de meest recente poule op en beoordeelt met
 * includes/kansen.php of het team al kampioen is, het nog kan worden,
 * of is uitgeschakeld — met uitleg waarom (Bondsreglement art. 2.6/2.6.1).
 * Jongste jeugd wordt overgeslagen: daarvoor publiceert de KNHB bewust
 * geen ranglijsten (art. 2.6).
 *
 * De pagina streamt: elke teamrij wordt naar de browser geschreven zodra
 * de poule is opgehaald, met een meelopende voortgangsteller. De
 * samenvattingschips bovenaan worden na afloop via een klein script
 * ingevuld (zonder JavaScript blijft alleen die samenvatting leeg).
 */

$KAMP_TYPE = $KAMP_TYPE ?? 'VE';
$isZaal    = $KAMP_TYPE === 'ZA';
$typeNaam  = $isZaal ? 'zaal' : 'veld';

function e(mixed $v): string { return htmlspecialchars((string)$v); }

// ── Output-buffering uit zodat rijen direct de browser bereiken ──
while (ob_get_level() > 0) ob_end_clean();
if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
@ini_set('zlib.output_compression', '0');
ob_implicit_flush(true);

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

// Alleen de teams van deze competitievorm, gegroepeerd per categorie
$teams = array_values(array_filter($teams, fn($t) => ($t['hockey_type'] ?? '') === $KAMP_TYPE));

$groepVolgorde = ['Senioren', 'Junioren', 'Jongste jeugd'];
$groepen = [];
foreach ($teams as $t) {
    $groepen[(string)($t['category_group_name'] ?? 'Overig')][] = $t;
}
uksort($groepen, function ($a, $b) use ($groepVolgorde) {
    $ia = array_search($a, $groepVolgorde, true);
    $ib = array_search($b, $groepVolgorde, true);
    return ($ia === false ? PHP_INT_MAX : $ia) <=> ($ib === false ? PHP_INT_MAX : $ib);
});

// Aantal te checken teams (jongste jeugd niet: geen ranglijsten)
$teChecken = 0;
foreach ($groepen as $cat => $rijen) {
    if ($cat !== 'Jongste jeugd') $teChecken += count($rijen);
}

// Poule ophalen + beoordelen voor één team
function kamp_beoordeel(array $t): array
{
    $rij = [
        'team'  => (string)($t['name'] ?? $t['short_name'] ?? '?'),
        'comp'  => '',
        'stats' => [],
    ];

    $pouleId = $t['recent_poule_id'] ?? null;
    if (!$pouleId) {
        $rij['verdict'] = 'onbekend';
        $rij['reasons'] = ['Voor dit team is geen poule bekend.'];
        return $rij;
    }

    $resp = hapi_get('/poules/' . rawurlencode((string)$pouleId));
    $pd   = $resp['data'] ?? null;
    if (!is_array($pd)) {
        $rij['verdict'] = 'onbekend';
        $rij['reasons'] = ['De poule van dit team kon niet worden opgehaald.'];
        return $rij;
    }

    $compNaam = (string)($pd['competition']['national_competition_name']
        ?? $pd['competition']['name'] ?? '');
    $rij['comp'] = trim($compNaam . ' · ' . (string)($pd['name'] ?? ''), ' ·');

    $r = kampioen_check($pd['standings'] ?? [], $pd['matches'] ?? [], (string)$t['id']);
    $rij['verdict'] = $r['verdict'];
    $rij['reasons'] = $r['reasons'];
    $rij['stats']   = $r['stats'];
    return $rij;
}

$verdictMeta = [
    'kampioen' => ['★', 'Kampioen',                'verdict-ja'],
    'bijna'    => ['!', 'Bijna kampioen',          'verdict-mits'],
    'mogelijk' => ['?', 'Kan nog kampioen worden', 'verdict-mogelijk'],
    'nee'      => ['✗', 'Geen kampioen',           'verdict-nee'],
    'onbekend' => ['–', 'Onbekend',                'verdict-onbekend'],
];

$tabs = POULE_ID ? ['programma', 'uitslagen', 'stand'] : ['programma', 'uitslagen'];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e(CLUB_NAME) ?> – Kampioenscheck <?= e($typeNaam) ?></title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    :root {
      --color-primary: <?= e(COLOR_PRIMARY) ?>;
      --color-accent:  <?= e(COLOR_ACCENT)  ?>;
      --color-bg:      <?= e(COLOR_BG)      ?>;
      --color-text:    <?= e(COLOR_TEXT)    ?>;
    }
  </style>
  <script>
    function kampProg(n) {
      var el = document.getElementById('kamp-progress-done');
      if (el) el.textContent = n;
    }
  </script>
</head>
<body>

<div class="widget">

  <header class="widget-header">
    <div class="widget-title">
      <span class="club-name"><?= e(CLUB_NAME) ?></span>
      <span class="team-name">Kampioenscheck <?= e($typeNaam) ?></span>
    </div>
  </header>

  <nav class="tabs" role="tablist">
    <?php foreach ($tabs as $t): ?>
      <a href="index.php?tab=<?= $t ?>" class="tab" role="tab" aria-selected="false">
        <?= match($t) { 'programma' => 'Programma', 'uitslagen' => 'Uitslagen', 'stand' => 'Stand' } ?>
      </a>
    <?php endforeach; ?>
    <a href="inval.php" class="tab" role="tab" aria-selected="false">Invalcheck</a>
    <a href="kampioen.php" class="tab active" role="tab" aria-selected="true">Kampioen?</a>
  </nav>

  <div class="content" role="tabpanel">

    <div class="kamp-switch">
      <a href="kampioen.php" class="<?= $isZaal ? '' : 'active' ?>">Buiten (veld)</a>
      <a href="kampioen-zaal.php" class="<?= $isZaal ? 'active' : '' ?>">Zaal</a>
    </div>

    <?php if ($apiError): ?>
      <div class="notice notice-error"><?= $apiError ?></div>

    <?php else: ?>

      <p class="kamp-intro">
        Per <?= e($typeNaam) ?>team de stand van de meest recente competitie: al kampioen,
        nog kans, of uitgeschakeld? Klik op een team voor de uitleg. Puntentelling en
        tiebreaks volgens art. 2.6/2.6.1 van het KNHB Bondsreglement.
      </p>

      <div class="kamp-progress" id="kamp-progress">
        Standen laden… <strong><span id="kamp-progress-done">0</span> / <?= $teChecken ?></strong> teams
      </div>

      <div class="kamp-samenvatting" id="kamp-samenvatting"></div>

      <?php
      // Padding zodat browsers vroeg beginnen met renderen, dan eerste flush
      echo '<!-- ' . str_repeat('=', 1024) . " -->\n";
      flush();

      $telling = [];
      $klaar   = 0;

      foreach ($groepen as $cat => $catTeams): ?>
        <section class="kamp-groep">
          <h2><?= e($cat) ?></h2>

          <?php if ($cat === 'Jongste jeugd'): ?>
            <div class="notice">
              Voor jongste-jeugdteams publiceert de KNHB bewust geen uitslagen en
              ranglijsten — de nadruk ligt op plezier en ontwikkeling (art. 2.6).
              Er valt dus geen kampioenschap te checken.
            </div>

          <?php else: foreach ($catTeams as $t):
            $rij = kamp_beoordeel($t);
            $telling[$rij['verdict']] = ($telling[$rij['verdict']] ?? 0) + 1;
            $klaar++;
            [$icon, $label, $cls] = $verdictMeta[$rij['verdict']] ?? $verdictMeta['onbekend'];
            $s = $rij['stats'];
          ?>
            <details class="kamp-row <?= $cls ?>">
              <summary>
                <span class="verdict-icon"><?= $icon ?></span>
                <span class="kamp-team">
                  <strong><?= e($rij['team']) ?></strong>
                  <?php if ($rij['comp']): ?><small><?= e($rij['comp']) ?></small><?php endif; ?>
                </span>
                <span class="kamp-cijfers">
                  <span class="kamp-badge"><?= e($label) ?></span>
                  <?php if (isset($s['rank'])): ?>
                    <span class="kamp-stats"><?= $s['rank'] ?>e · <?= $s['points'] ?> pnt ·
                      <?= $s['finished'] ? 'klaar' : 'nog ' . $s['remaining'] ?></span>
                  <?php endif; ?>
                </span>
              </summary>
              <ul class="verdict-list">
                <?php foreach ($rij['reasons'] as $reason): ?>
                  <li><?= e($reason) ?></li>
                <?php endforeach; ?>
              </ul>
            </details>
            <script>kampProg(<?= $klaar ?>)</script>
            <?php flush(); ?>
          <?php endforeach; endif; ?>

        </section>
      <?php endforeach; ?>

      <?php
      // Samenvatting invullen en de voortgangsbalk verbergen
      $chips = '';
      foreach ($verdictMeta as $v => [, $label, $cls]) {
          if (empty($telling[$v])) continue;
          $chips .= '<span class="kamp-chip ' . $cls . '">' . $telling[$v] . '× ' . e($label) . '</span>';
      }
      ?>
      <script>
        document.getElementById('kamp-samenvatting').innerHTML = <?= json_encode($chips) ?>;
        document.getElementById('kamp-progress').style.display = 'none';
      </script>

    <?php endif; ?>
  </div><!-- .content -->

  <footer class="widget-footer">
    <a href="https://hockey.nl" target="_blank" rel="noopener">Data: KNHB / HockeyWeerelt</a>
  </footer>

</div><!-- .widget -->

</body>
</html>
