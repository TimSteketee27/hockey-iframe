<?php
declare(strict_types=1);

/**
 * Unit-tests voor includes/rules.php.
 * Draaien: c:\xampp\php\php.exe tests\rules_test.php
 *
 * De cases coderen de letterlijke voorbeelden uit het KNHB Bondsreglement
 * 2025-2026 (art. 4.3.6, 4.3.7, 5.3.5.1 t/m 5.3.5.5).
 */

require_once __DIR__ . '/../includes/rules.php';

$fails = 0;
$tests = 0;

function team(string $short, ?string $klasse = null, string $catGroup = ''): array
{
    $t = ['name' => 'Hilvarenbeek ' . $short, 'short_name' => $short, 'hockey_type' => 'VE',
          'category_group_name' => $catGroup];
    return inval_team_profile($t, $klasse);
}

function check(string $label, array $bron, array $doel, string $expect, array $opts = []): void
{
    global $fails, $tests;
    $tests++;
    $r = check_inval($bron, $doel, $opts);
    if ($r['verdict'] !== $expect) {
        $fails++;
        echo "FAIL  $label\n      verwacht: $expect, kreeg: {$r['verdict']}\n";
        foreach ($r['reasons'] as $reason) echo "      reden: $reason\n";
    } else {
        echo "ok    $label [$expect]\n";
    }
}

// ── Profiel-parsing ───────────────────────────────────────────
$p = team('MO16-2', '3e klasse');
assert($p['gender'] === 'v' && $p['age'] === 'o16' && $p['kind'] === 'junior' && $p['level'] === 8);
$p = team('H30-1', 'Hoofdklasse');
assert($p['kind'] === '30plus' && $p['gender'] === 'm' && $p['level'] === 2);
$p = team('DO25-1-O', '1e klasse');
assert($p['kind'] === 'o25' && $p['opleiding'] === true && $p['level'] === 4);
$p = team('zMO18-1', null);
assert($p['zaal'] === true);
$p = team('D1', 'Tweede Klasse Dames');
assert($p['kind'] === 'standaard' && $p['categorie'] === 1 && $p['klasse_n'] === 2);
$p = team('DDW1', null);
assert($p['kind'] === 'ddw' && $p['categorie'] === 3);
$p = team('MO9-Geel', null, 'Jongste jeugd');
assert($p['kind'] === 'jongstejeugd' && $p['categorie'] === 3);
echo "ok    profiel-parsing\n";

// ── Voorbeelden art. 4.3.6 / 5.3.5.1: gelijk of lager niveau ──
// JO18-2 (Subtopklasse) mag invallen bij JO18-1 (Topklasse)
check('JO18-2 Subtop -> JO18-1 Top (omhoog, vrij)',
    team('JO18-2', 'Subtopklasse'), team('JO18-1', 'Topklasse'), 'ja');
// MO16-3 (2e Klasse) mag invallen bij MO16-1 (Subtopklasse)
check('MO16-3 2e kl -> MO16-1 Subtop (omhoog, vrij)',
    team('MO16-3', '2e klasse'), team('MO16-1', 'Subtopklasse'), 'ja');
// H4 (2e Klasse) mag invallers lenen uit H3 (2e Klasse)
check('H3 res 2e kl -> H4 res 2e kl (gelijk)',
    team('H3', '2e klasse'), team('H4', '2e klasse'), 'ja');
// D2 (2e Klasse) mag invallers lenen uit D5 (3e Klasse)
check('D5 res 3e kl -> D2 res 2e kl (lager naar hoger)',
    team('D5', '3e klasse'), team('D2', '2e klasse'), 'ja');
// JO18-2 (3e kl) mag invallers lenen uit JO14-2 (1e kl)
check('JO14-2 1e kl -> JO18-2 3e kl (lagere leeftijdscat, gelijk niveau)',
    team('JO14-2', '1e klasse'), team('JO18-2', '3e klasse'), 'ja');
// MO16-3 (2e kl) mag invallers lenen uit MO18-3 (3e kl), mits juiste leeftijd
check('MO18-3 3e kl -> MO16-3 2e kl (mits leeftijd)',
    team('MO18-3', '3e klasse'), team('MO16-3', '2e klasse'), 'mits');
check('MO18-3 3e kl -> MO16-3 2e kl, leeftijd ok',
    team('MO18-3', '3e klasse'), team('MO16-3', '2e klasse'), 'ja', ['leeftijd_ok' => true]);
check('MO18-3 3e kl -> MO16-3 2e kl, leeftijd niet ok',
    team('MO18-3', '3e klasse'), team('MO16-3', '2e klasse'), 'nee', ['leeftijd_ok' => false]);
// JO16-1 (Topklasse) mag invallen bij JO18-1 (Subtopklasse) — lagere cat max 1 klasse hoger
check('JO16-1 Top -> JO18-1 Subtop (gelijk niveau via tabel)',
    team('JO16-1', 'Topklasse'), team('JO18-1', 'Subtopklasse'), 'ja');
// JO18-1 (Subtop) mag invallen bij JO16-1 (Top), mits juiste leeftijd
check('JO18-1 Subtop -> JO16-1 Top (mits leeftijd)',
    team('JO18-1', 'Subtopklasse'), team('JO16-1', 'Topklasse'), 'mits');

// ── Voorbeelden art. 5.3.5.2: hoger spelend niveau ────────────
// Voorbeeld 1: res 2e Klasse leent max 2 spelers uit res 1e Klasse
check('D-res 1e kl -> D-res 2e kl (één hoger: mits)',
    team('D2', '1e klasse'), team('D3', '2e klasse'), 'mits');
check('D-res 1e kl -> D-res 2e kl, >11 spelers beschikbaar',
    team('D2', '1e klasse'), team('D3', '2e klasse'), 'nee', ['lte11' => false]);
check('D-res 1e kl -> D-res 2e kl, alternatief beschikbaar',
    team('D2', '1e klasse'), team('D3', '2e klasse'), 'nee', ['geen_alternatief' => false]);
check('D-res 1e kl -> D-res 2e kl, keeper ondanks >11',
    team('D2', '1e klasse'), team('D3', '2e klasse'), 'mits', ['lte11' => false, 'keeper' => true]);
// Voorbeeld 2: JO16-2 (1e kl) leent uit JO18-3 (1e kl), mits leeftijd + voorwaarden
check('JO18-3 1e kl -> JO16-2 1e kl (één hoger: mits)',
    team('JO18-3', '1e klasse'), team('JO16-2', '1e klasse'), 'mits');
// Voorbeeld 3a: JO16-3 (3e kl) mag nooit lenen uit JO18-3 (2e kl) — 2 klassen hoger
check('JO18-3 2e kl -> JO16-3 3e kl (twee hoger: nee)',
    team('JO18-3', '2e klasse'), team('JO16-3', '3e klasse'), 'nee');
// Voorbeeld 3b: JO16-3 (3e kl) mag wel lenen uit JO18-4 (3e kl)
check('JO18-4 3e kl -> JO16-3 3e kl (één hoger: mits)',
    team('JO18-4', '3e klasse'), team('JO16-3', '3e klasse'), 'mits');
// Voorbeeld 4: MO14-6 (6e kl) mag lenen uit MO14-5 (4e kl) — junioren 4e kl e.l.
check('MO14-5 4e kl -> MO14-6 6e kl (junioren 4e+ uitzondering)',
    team('MO14-5', '4e klasse'), team('MO14-6', '6e klasse'), 'mits');

// ── Art. 5.3.5.3: jeugd -> senioren ───────────────────────────
// O18 Landelijk mag alleen t/m res 1e klasse
check('JO18-1 Landelijk -> H-res 1e kl (matrix: ja)',
    team('JO18-1', 'Landelijk'), team('H2', '1e klasse'), 'ja');
check('JO18-1 Landelijk -> H-res 2e kl (matrix: nee)',
    team('JO18-1', 'Landelijk'), team('H3', '2e klasse'), 'nee');
// O18 2e klasse -> t/m res 4e klasse
check('MO18-2 2e kl -> D-res 4e kl (matrix: ja)',
    team('MO18-2', '2e klasse'), team('D4', '4e klasse'), 'ja');
check('MO18-2 2e kl -> D-res 5e kl (matrix: nee)',
    team('MO18-2', '2e klasse'), team('D5', '5e klasse'), 'nee');
// O16 1e klasse -> t/m res 4e klasse / O25 3e klasse
check('MO16-1 1e kl -> DO25-3 3e kl (matrix: ja)',
    team('MO16-1', '1e klasse'), team('DO25-3', '3e klasse'), 'ja');
// O14 mag altijd bij senioren/O25 (veld)
check('MO14-1 Topklasse -> DO25-2 4e kl (O14 altijd)',
    team('MO14-1', 'Topklasse'), team('DO25-2', '4e klasse'), 'ja');
// Jeugd nooit bij 30+/45+
check('MO18-1 -> D30 (jeugd nooit bij 30+)',
    team('MO18-1', '1e klasse'), team('D30-1', '1e klasse'), 'nee');

// ── Art. 5.3.5.4 / 5.3.5.5: reserve <-> O25/30+/45+ ──────────
// res hoofdklasse mag niet invallen in O25/30+/45+
check('H-res Hoofdklasse -> H30 Hoofdklasse (nee)',
    team('H2', 'Hoofdklasse'), team('H30-1', 'Hoofdklasse'), 'nee');
// res 2e klasse -> 30+ hoofd t/m 1e klasse
check('D-res 2e kl -> D30 1e kl (matrix: mits leeftijd)',
    team('D3', '2e klasse'), team('D30-1', '1e klasse'), 'mits');
check('D-res 2e kl -> D30 2e kl (matrix: nee)',
    team('D3', '2e klasse'), team('D30-1', '2e klasse'), 'nee');
// O25 1e klasse -> res 2e t/m res hoofdklasse
check('DO25 1e kl -> D-res 2e kl (matrix: ja)',
    team('DO25-2', '1e klasse'), team('D3', '2e klasse'), 'ja');
check('DO25 1e kl -> D-res 3e kl (matrix: nee)',
    team('DO25-2', '1e klasse'), team('D4', '3e klasse'), 'nee');

// ── Standaardteams ────────────────────────────────────────────
check('D2 res -> D1 standaard (omhoog: ja, met waarschuwingen)',
    team('D2', '1e klasse'), team('D1', 'Tweede Klasse Dames'), 'ja');
check('MO18-1 -> D1 standaard (jeugd altijd bij standaard)',
    team('MO18-1', '1e klasse'), team('D1', 'Tweede Klasse Dames'), 'ja');
check('D1 standaard -> D2 res (teamopgave bepaalt: mits)',
    team('D1', 'Tweede Klasse Dames'), team('D2', '1e klasse'), 'mits');
check('D1 standaard -> opleidingsteam (mits positie 10-18)',
    team('D1', 'Tweede Klasse Dames'), team('DO25-1-O', '1e klasse'), 'mits');

// ── Geslacht ──────────────────────────────────────────────────
check('H3 -> D2 (geslacht: nee)',
    team('H3', '2e klasse'), team('D2', '2e klasse'), 'nee');
check('JO16-1 -> MO16-1 (geslacht: nee)',
    team('JO16-1', '1e klasse'), team('MO16-1', '1e klasse'), 'nee');

// ── Categorie III ─────────────────────────────────────────────
check('D5 -> DDW1 (cat III: ja)',
    team('D5', '3e klasse'), team('DDW1', null), 'ja');
check('MO9 -> MO12-1 (jongste jeugd mag bij 11-tallen)',
    team('MO9-Geel', null, 'Jongste jeugd'), team('MO12-1', '1e klasse'), 'ja');
check('MO9 -> D2 (jongste jeugd niet bij senioren)',
    team('MO9-Geel', null, 'Jongste jeugd'), team('D2', '2e klasse'), 'nee');
check('D2 -> MO10 (senior niet bij jongste jeugd)',
    team('D2', '2e klasse'), team('MO10-Geel', null, 'Jongste jeugd'), 'nee');

// ── Randgevallen ──────────────────────────────────────────────
check('Zelfde team', team('D2', '2e klasse'), team('D2', '2e klasse'), 'nee');
check('Zaalteam -> onbekend', team('zMO18-1', null), team('MO18-1', '1e klasse'), 'onbekend');
check('Onbekende klasse -> onbekend', team('D4', null), team('D3', null), 'onbekend');
check('S1-mix -> onbekend teamtype', team('S1-mix', null), team('D2', '2e klasse'), 'onbekend');

echo "\n$tests tests, $fails failures\n";
exit($fails > 0 ? 1 : 0);
