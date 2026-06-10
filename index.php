<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/api.php';

// Tab routing
$validTabs = POULE_ID ? ['programma', 'uitslagen', 'stand'] : ['programma', 'uitslagen'];
$tab       = in_array($_GET['tab'] ?? '', $validTabs) ? $_GET['tab'] : 'programma';

// Fetch data
$rows  = [];
$error = null;

if (!TEAM_ID) {
    $error = 'Geen team geconfigureerd. Vul <code>TEAM_ID</code> in <strong>config.php</strong> in. '
           . 'Gebruik <a href="setup.php">setup.php</a> om je team-ID te vinden.';
} else {
    switch ($tab) {
        case 'programma': $rows = get_upcoming_matches(); break;
        case 'uitslagen': $rows = get_past_matches();     break;
        case 'stand':     $rows = get_standing();          break;
    }
    if ($rows === null) {
        $rows  = [];
        $error = 'De API is momenteel niet bereikbaar. Probeer het later opnieuw.';
    }
}

// Helper: format Dutch date from ISO 8601
function fmt_date(string $iso, string $format = 'D d M'): string
{
    static $nl = [
        'Mon' => 'ma', 'Tue' => 'di', 'Wed' => 'wo', 'Thu' => 'do',
        'Fri' => 'vr', 'Sat' => 'za', 'Sun' => 'zo',
        'Jan' => 'jan', 'Feb' => 'feb', 'Mar' => 'mrt', 'Apr' => 'apr',
        'May' => 'mei', 'Jun' => 'jun', 'Jul' => 'jul', 'Aug' => 'aug',
        'Sep' => 'sep', 'Oct' => 'okt', 'Nov' => 'nov', 'Dec' => 'dec',
    ];
    if (!$iso) return '–';
    try {
        $dt = new DateTimeImmutable($iso, new DateTimeZone('Europe/Amsterdam'));
        return strtr($dt->format($format), $nl);
    } catch (Exception) {
        return $iso;
    }
}

function fmt_time(string $iso): string
{
    if (!$iso) return '';
    try {
        $dt = new DateTimeImmutable($iso, new DateTimeZone('Europe/Amsterdam'));
        return $dt->format('H:i');
    } catch (Exception) {
        return '';
    }
}

// Highlight our team name in bold
function hl(string $name): string
{
    $teamName = TEAM_NAME;
    if (stripos($name, $teamName) !== false) {
        return '<strong>' . htmlspecialchars($name) . '</strong>';
    }
    return htmlspecialchars($name);
}

function result_class(array $m): string
{
    $hs = match_home_score($m);
    $as = match_away_score($m);
    if ($hs === null || $as === null) return '';
    $isHome = match_is_home($m);
    if ($hs === $as) return 'result-draw';
    $won = $isHome ? ($hs > $as) : ($as > $hs);
    return $won ? 'result-win' : 'result-loss';
}

?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars(CLUB_NAME) ?> – <?= htmlspecialchars(TEAM_NAME) ?></title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    :root {
      --color-primary: <?= htmlspecialchars(COLOR_PRIMARY) ?>;
      --color-accent:  <?= htmlspecialchars(COLOR_ACCENT)  ?>;
      --color-bg:      <?= htmlspecialchars(COLOR_BG)      ?>;
      --color-text:    <?= htmlspecialchars(COLOR_TEXT)    ?>;
    }
  </style>
</head>
<body>

<div class="widget">

  <header class="widget-header">
    <div class="widget-title">
      <span class="club-name"><?= htmlspecialchars(CLUB_NAME) ?></span>
      <span class="team-name"><?= htmlspecialchars(TEAM_NAME) ?></span>
    </div>
  </header>

  <nav class="tabs" role="tablist">
    <?php foreach ($validTabs as $t): ?>
      <a href="?tab=<?= $t ?>"
         class="tab<?= $tab === $t ? ' active' : '' ?>"
         role="tab"
         aria-selected="<?= $tab === $t ? 'true' : 'false' ?>">
        <?= match($t) { 'programma' => 'Programma', 'uitslagen' => 'Uitslagen', 'stand' => 'Stand' } ?>
      </a>
    <?php endforeach; ?>
    <a href="inval.php" class="tab" role="tab" aria-selected="false">Invalcheck</a>
    <a href="kampioen.php" class="tab" role="tab" aria-selected="false">Kampioen?</a>
  </nav>

  <div class="content" role="tabpanel">

    <?php if ($error): ?>
      <div class="notice notice-error"><?= $error ?></div>

    <?php elseif (empty($rows)): ?>
      <div class="notice">
        <?php if ($tab === 'programma'): ?>Geen komende wedstrijden gevonden.
        <?php elseif ($tab === 'uitslagen'): ?>Nog geen uitslagen beschikbaar.
        <?php else: ?>Geen standgegevens beschikbaar.
        <?php endif; ?>
      </div>

    <?php elseif ($tab === 'stand'): ?>

      <table class="table stand-table">
        <thead>
          <tr>
            <th class="col-pos">#</th>
            <th class="col-team">Team</th>
            <th title="Gespeeld">G</th>
            <th title="Gewonnen">W</th>
            <th title="Gelijk">GL</th>
            <th title="Verloren">V</th>
            <th title="Doelpunten voor – tegen">Doel</th>
            <th title="Punten" class="col-pts">Pnt</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $i => $row):
            $pos      = rank_val($row, 'position', 'pos', 'rank') ?: ($i + 1);
            $name     = rank_team_name($row);
            $isOurRow = stripos($name, TEAM_NAME) !== false || rank_team_id($row) === TEAM_ID;
            $played   = rank_val($row, 'played', 'games', 'matchesPlayed', 'gespeeld');
            $won      = rank_val($row, 'won', 'wins', 'gewonnen');
            $drawn    = rank_val($row, 'drawn', 'draws', 'gelijk');
            $lost     = rank_val($row, 'lost', 'losses', 'verloren');
            $gf       = rank_val($row, 'goalsFor', 'goals_for', 'doelpuntenVoor');
            $ga       = rank_val($row, 'goalsAgainst', 'goals_against', 'doelpuntenTegen');
            $pts      = rank_val($row, 'points', 'punten');
          ?>
          <tr class="<?= $isOurRow ? 'our-team' : '' ?>">
            <td class="col-pos"><?= $pos ?></td>
            <td class="col-team"><?= htmlspecialchars($name) ?></td>
            <td><?= $played ?></td>
            <td><?= $won ?></td>
            <td><?= $drawn ?></td>
            <td><?= $lost ?></td>
            <td><?= $gf ?>–<?= $ga ?></td>
            <td class="col-pts"><strong><?= $pts ?></strong></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

    <?php else: /* programma or uitslagen */ ?>

      <table class="table match-table">
        <thead>
          <tr>
            <th class="col-date">Datum</th>
            <th class="col-match">Wedstrijd</th>
            <th class="col-score">Score</th>
            <th class="col-loc">Locatie</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $m):
            $hs   = match_home_score($m);
            $as   = match_away_score($m);
            $dt   = match_datetime($m);
            $rCls = result_class($m);
          ?>
          <tr class="match-row <?= $rCls ?>">
            <td class="col-date">
              <span class="match-day"><?= fmt_date($dt, 'D') ?></span>
              <span class="match-date"><?= fmt_date($dt, 'd M') ?></span>
              <?php if ($tab === 'programma'): ?>
                <span class="match-time"><?= fmt_time($dt) ?></span>
              <?php endif; ?>
            </td>
            <td class="col-match">
              <span class="match-home"><?= hl(match_home_name($m)) ?></span>
              <span class="match-vs">vs</span>
              <span class="match-away"><?= hl(match_away_name($m)) ?></span>
              <?php $comp = match_competition($m); if ($comp): ?>
                <small class="match-comp"><?= htmlspecialchars($comp) ?></small>
              <?php endif; ?>
            </td>
            <td class="col-score">
              <?php if ($hs !== null && $as !== null): ?>
                <span class="score <?= $rCls ?>"><?= $hs ?>–<?= $as ?></span>
              <?php else: ?>
                <span class="score-tbd">–</span>
              <?php endif; ?>
            </td>
            <td class="col-loc">
              <?= htmlspecialchars(match_location($m)) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

    <?php endif; ?>
  </div><!-- .content -->

  <footer class="widget-footer">
    <a href="https://hockey.nl" target="_blank" rel="noopener">Data: KNHB / HockeyWeerelt</a>
  </footer>

</div><!-- .widget -->

</body>
</html>
