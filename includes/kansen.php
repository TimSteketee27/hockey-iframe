<?php
declare(strict_types=1);

/**
 * Kampioenscheck-engine: kan een team in zijn poule nog kampioen worden,
 * is het dat al, of is het uitgeschakeld — en waarom?
 *
 * Pure rekenmodule zonder I/O, getest in tests/kansen_test.php.
 *
 * Reglementaire basis (KNHB Bondsreglement 2025-2026):
 * - art. 2.6:   winst 3 punten, gelijkspel 1, verlies 0;
 * - art. 2.6.1: bij gelijke punten beslist achtereenvolgens het aantal
 *   gewonnen wedstrijden, het doelsaldo, doelpunten vóór, het onderlinge
 *   resultaat en uiteindelijk een play-off.
 *
 * De "nog mogelijk"-check is een paarsgewijze ondergrens: wedstrijden die
 * concurrenten nog tegen elkáár spelen kunnen het kampioenschap in
 * werkelijkheid eerder onmogelijk maken dan deze check ziet. Daarom spreekt
 * de uitleg van "theoretisch nog mogelijk". Wedstrijden met een andere
 * status dan 'final' tellen als nog te spelen, behalve als hun datum meer
 * dan een week in het verleden ligt: dat zijn vrijwel altijd afgelaste
 * wedstrijden uit een al afgeronde competitie (typisch zaalpoules).
 */

const KANSEN_TIEBREAKS = 'het aantal gewonnen wedstrijden, het doelsaldo, het aantal '
    . 'doelpunten vóór en het onderlinge resultaat (art. 2.6.1)';

/**
 * @param array       $standings standings[] uit /poules/{id}
 * @param array       $matches   matches[] uit /poules/{id}
 * @param string|int  $teamId    team-id binnen die poule
 * @param string|null $vandaag   'Y-m-d', voor tests; standaard vandaag
 * @return array ['verdict' => kampioen|bijna|mogelijk|nee|onbekend,
 *                'reasons' => string[], 'stats' => array]
 */
function kampioen_check(array $standings, array $matches, string|int $teamId, ?string $vandaag = null): array
{
    $tid = (string)$teamId;
    $speelbaarVanaf = date('Y-m-d', strtotime(($vandaag ?? date('Y-m-d')) . ' -7 days'));

    $rows = [];
    foreach ($standings as $row) {
        $id = (string)($row['team']['id'] ?? $row['id'] ?? '');
        if ($id !== '') $rows[$id] = $row;
    }

    $stats = ['finished' => null, 'eigen_hand' => null];

    if (!$rows) {
        return ['verdict' => 'onbekend', 'stats' => $stats,
                'reasons' => ['Voor deze poule is geen stand beschikbaar.']];
    }
    if (!isset($rows[$tid])) {
        return ['verdict' => 'onbekend', 'stats' => $stats,
                'reasons' => ['Het team staat niet in de stand van deze poule.']];
    }

    // Resterende wedstrijden per team + resterende onderlinge duels met ons
    $rem     = array_fill_keys(array_keys($rows), 0);
    $remVsUs = array_fill_keys(array_keys($rows), 0);
    foreach ($matches as $m) {
        if (($m['status'] ?? '') === 'final') continue;
        $datum = substr((string)($m['date'] ?? ''), 0, 10);
        if ($datum !== '' && $datum < $speelbaarVanaf) continue;  // afgelast, telt niet meer
        $h = (string)($m['home']['id'] ?? '');
        $a = (string)($m['away']['id'] ?? '');
        if (isset($rem[$h])) $rem[$h]++;
        if (isset($rem[$a])) $rem[$a]++;
        if ($h === $tid && isset($remVsUs[$a])) $remVsUs[$a]++;
        if ($a === $tid && isset($remVsUs[$h])) $remVsUs[$h]++;
    }
    $finished = array_sum($rem) === 0;

    $ours = $rows[$tid];
    $naam = (string)($ours['team']['name'] ?? 'dit team');
    $pts  = (int)($ours['points'] ?? 0);
    $rank = kansen_rank($rows, $tid);
    $maxT = $pts + 3 * $rem[$tid];

    // Concurrenten met hun maximum, en hun maximum als wij alles winnen
    // (resterende onderlinge duels verliezen ze dan van ons)
    $rivals = [];
    foreach ($rows as $id => $row) {
        if ((string)$id === $tid) continue;  // PHP maakt van numerieke string-keys ints
        $p = (int)($row['points'] ?? 0);
        $rivals[$id] = [
            'name'   => (string)($row['team']['name'] ?? '?'),
            'points' => $p,
            'rem'    => $rem[$id],
            'max'    => $p + 3 * $rem[$id],
            'max_bij_winst' => $p + 3 * ($rem[$id] - $remVsUs[$id]),
        ];
    }

    $stats = [
        'finished'   => $finished,
        'rank'       => $rank,
        'points'     => $pts,
        'played'     => (int)($ours['played'] ?? 0),
        'remaining'  => $rem[$tid],
        'max_points' => $maxT,
        'eigen_hand' => null,
    ];

    // Eenmansposities (geen concurrentie) — direct kampioen
    if (!$rivals) {
        return ['verdict' => 'kampioen', 'stats' => $stats,
                'reasons' => ["$naam is het enige team in deze poule."]];
    }

    $leider  = kansen_top($rivals, 'points');  // hoogste huidige punten
    $dreiger = kansen_top($rivals, 'max');     // hoogste haalbare punten

    // ── Competitie volledig gespeeld ──────────────────────────
    if ($finished) {
        if ($rank === 1) {
            $reasons = ["De competitie is gespeeld: $naam eindigde bovenaan met $pts punten "
                      . "({$stats['played']} wedstrijden)."];
            if ($leider['points'] === $pts) {
                $reasons[] = "Nummer 2 ({$leider['name']}) heeft evenveel punten; de eindvolgorde "
                           . 'is bepaald door ' . KANSEN_TIEBREAKS . '.';
            } else {
                $reasons[] = "De voorsprong op nummer 2 ({$leider['name']}) was "
                           . ($pts - $leider['points']) . ' punten.';
            }
            return ['verdict' => 'kampioen', 'stats' => $stats, 'reasons' => $reasons];
        }
        return ['verdict' => 'nee', 'stats' => $stats, 'reasons' => [
            "De competitie is gespeeld: $naam eindigde als {$rank}e met $pts punten.",
            "Kampioen werd {$leider['name']} met {$leider['points']} punten.",
        ]];
    }

    // ── Al zeker kampioen (niemand kan er nog overheen) ───────
    if ($pts > $dreiger['max']) {
        return ['verdict' => 'kampioen', 'stats' => $stats, 'reasons' => [
            "$naam is met $pts punten niet meer in te halen: de naaste achtervolger "
            . "({$dreiger['name']}, {$dreiger['points']} punten, nog {$dreiger['rem']} te spelen) "
            . "komt maximaal op {$dreiger['max']} punten.",
        ]];
    }

    // ── Bijna: niemand komt er in punten overheen, gelijk kan wel ──
    if ($pts >= $dreiger['max']) {
        $gelijkers = array_filter($rivals, fn($r) => $r['max'] === $pts);
        $namen = implode(', ', array_column($gelijkers, 'name'));
        $reasons = [
            "Geen enkel team kan $naam ($pts punten) nog in punten passeren, maar $namen kan "
            . 'nog wel gelijk eindigen.',
            'Bij gelijke punten beslist ' . KANSEN_TIEBREAKS . '.',
        ];
        if ($rem[$tid] > 0) {
            $reasons[] = "Eén overwinning in de resterende {$rem[$tid]} wedstrijd(en) maakt "
                       . 'het kampioenschap definitief.';
        }
        return ['verdict' => 'bijna', 'stats' => $stats, 'reasons' => $reasons];
    }

    // ── Uitgeschakeld: zelfs alles winnen is niet genoeg ──────
    if ($maxT < $leider['points']) {
        return ['verdict' => 'nee', 'stats' => $stats, 'reasons' => [
            "$naam staat op $pts punten en kan er met nog {$rem[$tid]} wedstrijd(en) "
            . "maximaal $maxT halen.",
            "{$leider['name']} heeft al {$leider['points']} punten — inhalen kan wiskundig niet meer.",
        ]];
    }

    // ── Nog mogelijk ──────────────────────────────────────────
    $reasons = [
        "$naam staat {$rank}e met $pts punten en kan er met nog {$rem[$tid]} wedstrijd(en) "
        . "maximaal $maxT halen.",
    ];

    $eigenHand = true;        // alles winnen volstaat (strikt meer punten dan elke rivaal)
    $tiebreakKans = false;    // alles winnen geeft hoogstens gelijke punten met een rivaal
    $sterksteRivaal = null;   // rivaal die ook bij onze volledige winst hoger kan uitkomen
    foreach ($rivals as $r) {
        if ($r['max_bij_winst'] > $maxT) {
            $eigenHand = false;
            if ($sterksteRivaal === null || $r['max_bij_winst'] > $sterksteRivaal['max_bij_winst']) {
                $sterksteRivaal = $r;
            }
        } elseif ($r['max_bij_winst'] === $maxT) {
            $eigenHand = false;
            $tiebreakKans = true;
            $sterksteRivaal ??= $r;
        }
    }
    $stats['eigen_hand'] = $eigenHand;

    if ($eigenHand) {
        $reasons[] = "Het kampioenschap is in eigen hand: wint $naam alle resterende "
                   . 'wedstrijden, dan kan geen ander team er nog overheen.';
    } elseif ($tiebreakKans && $sterksteRivaal['max_bij_winst'] === $maxT) {
        $reasons[] = "Zelfs als $naam alles wint kan {$sterksteRivaal['name']} nog op hetzelfde "
                   . 'puntenaantal uitkomen; dan beslist ' . KANSEN_TIEBREAKS . '.';
    } else {
        $reasons[] = "$naam heeft hulp nodig: ook als alles gewonnen wordt kan "
                   . "{$sterksteRivaal['name']} nog {$sterksteRivaal['max_bij_winst']} punten halen "
                   . 'en moet die dus punten laten liggen.';
    }
    $reasons[] = 'Dit is de theoretische kans; resterende onderlinge duels van concurrenten '
               . 'kunnen het in de praktijk lastiger maken.';

    return ['verdict' => 'mogelijk', 'stats' => $stats, 'reasons' => $reasons];
}

// Plek in de stand: uit de API-rank, anders afgeleid van punten
function kansen_rank(array $rows, string $tid): int
{
    $rank = (int)($rows[$tid]['rank'] ?? 0);
    if ($rank > 0) return $rank;
    $beter = 0;
    $pts = (int)($rows[$tid]['points'] ?? 0);
    foreach ($rows as $id => $row) {
        if ((string)$id !== $tid && (int)($row['points'] ?? 0) > $pts) $beter++;
    }
    return $beter + 1;
}

// Rivaal met de hoogste waarde voor $key (bij gelijke waarde: de eerste)
function kansen_top(array $rivals, string $key): array
{
    $best = null;
    foreach ($rivals as $r) {
        if ($best === null || $r[$key] > $best[$key]) $best = $r;
    }
    return $best;
}
