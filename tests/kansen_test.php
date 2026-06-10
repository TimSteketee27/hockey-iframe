<?php
declare(strict_types=1);

/**
 * Unit-tests voor includes/kansen.php (kampioenscheck-engine).
 * Draaien: c:\xampp\php\php.exe tests\kansen_test.php
 *
 * De puntentelling en tiebreaks volgen art. 2.6/2.6.1 van het KNHB
 * Bondsreglement 2025-2026 (winst 3, gelijk 1; daarna gewonnen wedstrijden,
 * doelsaldo, doelpunten voor, onderling resultaat).
 */

require_once __DIR__ . '/../includes/kansen.php';

$fails = 0;
$tests = 0;

// Standings-rij zoals /poules/{id} die levert
function row(int $id, string $name, int $rank, int $points, int $played = 0, int $wins = 0): array
{
    return [
        'rank' => $rank, 'points' => $points, 'played' => $played,
        'wins' => $wins, 'draws' => 0, 'losses' => 0,
        'goals_for' => 0, 'goals_against' => 0,
        'team' => ['id' => $id, 'name' => $name],
    ];
}

// Geplande wedstrijd tussen twee teams
function sched(int $home, int $away): array
{
    return ['status' => 'scheduled', 'home' => ['id' => $home], 'away' => ['id' => $away]];
}

function check(string $label, array $standings, array $matches, int $teamId, string $expect): array
{
    global $fails, $tests;
    $tests++;
    $r = kampioen_check($standings, $matches, $teamId);
    if ($r['verdict'] !== $expect) {
        $fails++;
        echo "FAIL  $label\n      verwacht: $expect, kreeg: {$r['verdict']}\n";
        foreach ($r['reasons'] as $reason) echo "      reden: $reason\n";
    } else {
        echo "ok    $label [$expect]\n";
    }
    return $r;
}

// ── Competitie klaar ──────────────────────────────────────────

// Klaar en 1e -> kampioen
check('klaar, 1e plaats', [
    row(1, 'Beek D1', 1, 42, 22),
    row(2, 'Weert D1', 2, 39, 22),
], [], 1, 'kampioen');

// Klaar en 2e -> nee
check('klaar, 2e plaats', [
    row(1, 'Weert D1', 1, 42, 22),
    row(2, 'Beek D1', 2, 39, 22),
], [], 2, 'nee');

// Klaar, gelijke punten maar rank 1 (tiebreak al verwerkt in de stand) -> kampioen
$r = check('klaar, gelijke punten, 1e op tiebreak', [
    row(1, 'Beek D1', 1, 42, 22, 13),
    row(2, 'Weert D1', 2, 42, 22, 12),
], [], 1, 'kampioen');
assert(str_contains(implode(' ', $r['reasons']), '2.6.1'));

// ── Nog niet klaar: zeker kampioen ────────────────────────────

// Niemand kan ons aantal punten nog halen -> kampioen
check('zeker: nr 2 kan max 39 halen', [
    row(1, 'Beek D1', 1, 42, 20),
    row(2, 'Weert D1', 2, 36, 20),
    row(3, 'Heesch D1', 3, 20, 20),
], [sched(2, 3)], 1, 'kampioen');

// Nr 2 kan precies gelijk komen -> bijna (tiebreaks beslissen)
$r = check('bijna: nr 2 kan gelijk komen', [
    row(1, 'Beek D1', 1, 42, 21),
    row(2, 'Weert D1', 2, 39, 20),
    row(3, 'Heesch D1', 3, 20, 20),
], [sched(2, 3)], 1, 'bijna');
assert(str_contains(implode(' ', $r['reasons']), 'gewonnen'));

// ── Nog niet klaar: uitgeschakeld ─────────────────────────────

// Max haalbaar (30 + 2x3 = 36) < koploper (37) -> nee
$r = check('uitgeschakeld: koploper onbereikbaar', [
    row(1, 'Weert D1', 1, 37, 20),
    row(2, 'Heesch D1', 2, 33, 20),
    row(3, 'Beek D1', 3, 30, 20),
], [sched(3, 2), sched(1, 3)], 3, 'nee');
// 1 resterend duel is tegen de koploper zelf... toch nee: zelfs bij winst
// stijgt ons max niet boven diens huidige punten.
assert(str_contains(implode(' ', $r['reasons']), '37'));

// ── Nog niet klaar: nog mogelijk ──────────────────────────────

// Eigen hand: wij 36 (+2 te spelen = 42), koploper 38 met alleen nog
// het onderlinge duel -> als wij alles winnen blijft hij op 38
$r = check('mogelijk, eigen hand', [
    row(1, 'Weert D1', 1, 38, 20),
    row(2, 'Beek D1', 2, 36, 19),
    row(3, 'Heesch D1', 3, 10, 20),
], [sched(2, 1), sched(3, 2)], 2, 'mogelijk');
assert($r['stats']['eigen_hand'] === true);

// Hulp nodig: koploper speelt niet meer tegen ons en kan naar 46
$r = check('mogelijk, hulp nodig', [
    row(1, 'Weert D1', 1, 40, 20),
    row(2, 'Beek D1', 2, 36, 20),
    row(3, 'Heesch D1', 3, 10, 20),
    row(4, 'Drunen D1', 4, 8, 20),
], [sched(2, 3), sched(2, 4), sched(1, 3), sched(1, 4)], 2, 'mogelijk');
assert($r['stats']['eigen_hand'] === false);

// Alleen gelijk eindigen kan (max = huidige punten koploper) -> mogelijk + tiebreak-uitleg
$r = check('mogelijk, alleen via tiebreak', [
    row(1, 'Weert D1', 1, 42, 22),
    row(2, 'Beek D1', 2, 36, 20),
    row(3, 'Heesch D1', 3, 10, 20),
], [sched(2, 3), sched(3, 2)], 2, 'mogelijk');
assert(str_contains(implode(' ', $r['reasons']), '2.6.1'));

// ── Randgevallen ──────────────────────────────────────────────

// Geplande wedstrijden ver in het verleden zijn de facto afgelast (typisch
// afgeronde zaalpoules) en tellen niet als nog te spelen: nr 2 kan ons dus
// niet meer inhalen -> de competitie is klaar en wij zijn kampioen.
$r = kampioen_check([
    row(1, 'Beek zD1', 1, 19, 8),
    row(2, 'Weert zD1', 2, 15, 8),
], [
    ['status' => 'scheduled', 'date' => '2026-02-01T10:00:00+01:00',
     'home' => ['id' => 2], 'away' => ['id' => 3]],
], 1, '2026-06-10');
$tests++;
if ($r['verdict'] !== 'kampioen' || $r['stats']['finished'] !== true) {
    $fails++;
    echo "FAIL  oude geplande wedstrijd telt niet mee\n      kreeg: {$r['verdict']}\n";
} else {
    echo "ok    oude geplande wedstrijd telt niet mee [kampioen]\n";
}

// Geen standings -> onbekend
check('lege standings', [], [], 1, 'onbekend');

// Team staat niet in de stand -> onbekend
check('team niet in stand', [row(1, 'Weert D1', 1, 42, 22)], [], 99, 'onbekend');

// ── Resultaat ─────────────────────────────────────────────────
echo "\n$tests tests, $fails failures\n";
exit($fails > 0 ? 1 : 0);
