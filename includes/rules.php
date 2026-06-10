<?php
declare(strict_types=1);

/**
 * KNHB invalregels-engine (veldhockey).
 *
 * Gebaseerd op het Bondsreglement 2025-2026 (versie 8 jan 2026), artikelen
 * 3, 4.3, 5.3 en 6.3, en de Tabel Klassengrenzen veldhockey 2025-2026.
 * Zie docs/Bondsreglement-2025.pdf en docs/superpowers/specs/ voor het ontwerp.
 *
 * Pure functies, geen I/O. De engine vergelijkt twee TEAM-profielen binnen
 * dezelfde vereniging; spelerspecifieke feiten (leeftijd, teamopgave-positie,
 * aantal gespeelde wedstrijden) zijn niet uit de API af te leiden en worden
 * als voorwaarde of waarschuwing teruggegeven.
 */

// ── Teamprofiel ───────────────────────────────────────────────

/**
 * Bouw een teamprofiel uit een API-teamrecord en (optioneel) de class_name
 * van de reguliere competitiepoule.
 *
 * @return array{
 *   name:string, short:string, zaal:bool, gender:string, age:string,
 *   kind:string, opleiding:bool, volgnr:?int, klasse:?string,
 *   klasse_n:?int, level:?int, categorie:?int
 * }
 */
function inval_team_profile(array $team, ?string $className = null): array
{
    $name  = (string)($team['name'] ?? '');
    $short = (string)($team['short_name'] ?? $name);
    $catGroup = (string)($team['category_group_name'] ?? '');

    $zaal = (($team['hockey_type'] ?? '') === 'ZA') || str_starts_with($short, 'z');
    $s = ltrim($short, 'z');

    $opleiding = (bool)preg_match('/-O$/', $s);
    $s = preg_replace('/-O$/', '', $s);

    $mix = (bool)preg_match('/-?mix$/i', $s);

    $gender = 'x';
    $age    = 'onbekend';
    $kind   = 'onbekend';
    $volgnr = null;

    if (preg_match('/^(J|M)O(\d{1,2})-/i', $s, $m)) {
        // Jeugd: JO18-1, MO14-2, MO10-Geel ...
        $gender = strtoupper($m[1]) === 'J' ? 'm' : 'v';
        $o = (int)$m[2];
        if ($o >= 11) {
            $age  = 'o' . $o;
            $kind = 'junior';
        } else {
            $age  = 'jj';
            $kind = 'jongstejeugd';
        }
        if (preg_match('/^(?:J|M)O\d{1,2}-(\d+)/i', $s, $mm)) $volgnr = (int)$mm[1];
    } elseif (preg_match('/^(D|H)O25-(\d+)/i', $s, $m)) {
        $gender = strtoupper($m[1]) === 'H' ? 'm' : 'v';
        $age    = 'o25';
        $kind   = 'o25';
        $volgnr = (int)$m[2];
    } elseif (preg_match('/^(D|H)(30|35|40|45|50)-?(\d+)?/i', $s, $m)) {
        $gender = strtoupper($m[1]) === 'H' ? 'm' : 'v';
        $kind   = ((int)$m[2]) >= 45 ? '45plus' : '30plus';
        $age    = $kind;
        $volgnr = isset($m[3]) ? (int)$m[3] : null;
    } elseif (preg_match('/^(D|H)DW(\d+)?/i', $s, $m)) {
        // Doordeweekse (trim)competitie — categorie III
        $gender = strtoupper($m[1]) === 'H' ? 'm' : 'v';
        $age    = 'senior';
        $kind   = 'ddw';
        $volgnr = isset($m[2]) ? (int)$m[2] : null;
    } elseif (preg_match('/^(D|H)(\d+)$/i', $s, $m)) {
        $gender = strtoupper($m[1]) === 'H' ? 'm' : 'v';
        $age    = 'senior';
        $volgnr = (int)$m[2];
        $kind   = $volgnr === 1 ? 'standaard' : 'reserve';
    } elseif (stripos($catGroup, 'jongste') !== false) {
        $age  = 'jj';
        $kind = 'jongstejeugd';
    }

    if ($mix) $gender = 'x';

    [$klasse, $klasseN] = inval_parse_klasse($className);
    $level = inval_klasse_level($kind, $age, $klasse, $klasseN);

    $categorie = match (true) {
        $kind === 'jongstejeugd', $kind === 'ddw' => 3,
        $kind === 'standaard'                     => 1,
        $kind === 'junior' && in_array($klasse, ['landelijk', 'super', 'topklasse', 'subtopklasse', 'idc'], true) => 1,
        $kind === 'onbekend'                      => null,
        default                                   => 2,
    };

    return [
        'name'      => $name,
        'short'     => $short,
        'zaal'      => $zaal,
        'gender'    => $gender,
        'age'       => $age,
        'kind'      => $kind,
        'opleiding' => $opleiding,
        'volgnr'    => $volgnr,
        'klasse'    => $klasse,
        'klasse_n'  => $klasseN,
        'level'     => $level,
        'categorie' => $categorie,
    ];
}

/**
 * Herken de klasse uit een class_name/competitienaam.
 * @return array{0:?string,1:?int} [token, ordinaal]  bijv. ['klasse', 2] of ['subtopklasse', null]
 */
function inval_parse_klasse(?string $className): array
{
    if ($className === null || trim($className) === '') return [null, null];
    $c = mb_strtolower($className);

    if (str_contains($c, 'hoofdklasse'))            return ['hoofdklasse', null];
    if (str_contains($c, 'promotieklasse'))         return ['promotieklasse', null];
    if (str_contains($c, 'overgangsklasse'))        return ['overgangsklasse', null];
    if (str_contains($c, 'subtopklasse') || str_contains($c, 'subtop')) return ['subtopklasse', null];
    if (str_contains($c, 'topklasse'))              return ['topklasse', null];
    if (str_contains($c, 'landelijk'))              return ['landelijk', null];
    if (str_contains($c, 'super'))                  return ['super', null];
    if (str_contains($c, 'idc'))                    return ['idc', null];
    if (preg_match('/(\d+)\s*e\s*klasse/', $c, $m)) return ['klasse', (int)$m[1]];

    // Voluit geschreven: "Tweede Klasse Dames"
    static $woorden = ['eerste' => 1, 'tweede' => 2, 'derde' => 3, 'vierde' => 4,
                       'vijfde' => 5, 'zesde' => 6, 'zevende' => 7, 'achtste' => 8];
    foreach ($woorden as $w => $n) {
        if (str_contains($c, $w)) return ['klasse', $n];
    }
    return [null, null];
}

/**
 * Niveau-index volgens de Tabel Klassengrenzen veldhockey (1 = hoogste).
 * Eén rij in de tabel = één niveau; klassen in verschillende (leeftijds-)
 * categorieën op dezelfde rij zijn gelijkwaardig.
 */
function inval_klasse_level(string $kind, string $age, ?string $klasse, ?int $n): ?int
{
    if ($klasse === null && $n === null) return null;

    $top = ['hoofdklasse', 'promotieklasse'];

    switch (true) {
        case $kind === 'reserve':
            if (in_array($klasse, $top, true))     return 1;
            if ($klasse === 'overgangsklasse')     return 2;
            if ($klasse === 'klasse' && $n !== null) return min(2 + $n, 7);
            return null;

        case $kind === 'o25':
            if (in_array($klasse, $top, true))     return 2;
            if ($klasse === 'overgangsklasse')     return 3;
            if ($klasse === 'klasse' && $n !== null) return min(3 + $n, 8);
            return null;

        case $kind === '30plus':
            if (in_array($klasse, $top, true))     return 2;
            if ($klasse === 'overgangsklasse')     return 3;
            if ($klasse === 'klasse' && $n !== null) return min(3 + $n, 6);
            return null;

        case $kind === '45plus':
            if (in_array($klasse, $top, true))     return 3;
            return 4; // Overgangsklasse en lager

        case $kind === 'junior':
            $base = match ($age) {
                'o18' => 3,  // Landelijk/Super/Topklasse
                'o16' => 4,
                'o14' => 5,  // Super/IDC/Topklasse
                'o12', 'o11' => 7, // Topklasse
                default => null,
            };
            if ($base === null) return null;
            $cap = $base + ($age === 'o18' ? 6 : ($age === 'o16' ? 6 : ($age === 'o14' ? 6 : 5)));
            if (in_array($klasse, ['landelijk', 'super', 'topklasse', 'idc'], true)) return $base;
            if ($klasse === 'subtopklasse') return $base + 1;
            if ($klasse === 'klasse' && $n !== null) return min($base + 1 + $n, $cap);
            return null;
    }
    return null;
}

// ── Kernoordeel ───────────────────────────────────────────────

/**
 * Beoordeel of een speler uit $bron mag invallen bij $doel (zelfde club, veld).
 *
 * $opts (alle optioneel, null = onbekend):
 *  - keeper            bool   invaller is (vaste) doelverdediger
 *  - lte11             ?bool  doelteam heeft ≤ 11 beschikbare spelers
 *  - geen_alternatief  ?bool  geen invallers beschikbaar uit gelijk/lager niveau
 *  - leeftijd_ok       ?bool  speler voldoet aan leeftijdsgrens doelcategorie
 *  - beslissingswedstrijd ?bool
 *
 * @return array{verdict:string, reasons:string[], conditions:string[], warnings:string[], refs:string[]}
 */
function check_inval(array $bron, array $doel, array $opts = []): array
{
    $keeper  = (bool)($opts['keeper'] ?? false);
    $lte11   = $opts['lte11'] ?? null;
    $geenAlt = $opts['geen_alternatief'] ?? null;
    $leeftijdOk = $opts['leeftijd_ok'] ?? null;
    $beslissing = $opts['beslissingswedstrijd'] ?? null;

    $reasons = $conditions = $warnings = $refs = [];

    $nee = function (string $r, string $ref = '') use (&$reasons, &$refs, &$warnings) {
        $reasons[] = $r;
        if ($ref) $refs[] = $ref;
        return ['verdict' => 'nee', 'reasons' => $reasons, 'conditions' => [], 'warnings' => $warnings, 'refs' => $refs];
    };

    // 0. Zelfde team / zaal / onherkenbaar
    if ($bron['name'] === $doel['name']) {
        return $nee('Bron- en doelteam zijn hetzelfde team; er is dan geen sprake van invallen.');
    }
    if ($bron['zaal'] || $doel['zaal']) {
        return [
            'verdict' => 'onbekend',
            'reasons' => ['Voor zaalhockey gelden eigen invalregels (o.a. 6 spelers i.p.v. 11). Deze check dekt alleen veldhockey.'],
            'conditions' => [], 'warnings' => [], 'refs' => ['BR art. 5.3.5 (zaalbepalingen)'],
        ];
    }
    if ($bron['kind'] === 'onbekend' || $doel['kind'] === 'onbekend') {
        return [
            'verdict' => 'onbekend',
            'reasons' => ['Het teamtype kon niet uit de teamnaam worden afgeleid. Controleer de regels handmatig in het Bondsreglement.'],
            'conditions' => [], 'warnings' => [], 'refs' => ['BR hoofdstuk 3 t/m 6'],
        ];
    }

    // 1. Geslacht (toelichting Tabel Klassengrenzen / procedure teamindeling)
    if ($bron['gender'] !== 'x' && $doel['gender'] !== 'x' && $bron['gender'] !== $doel['gender']) {
        return $nee(
            'Heren/jongens en dames/meisjes mogen niet zonder toestemming van de competitieleiding bij elkaar invallen.',
            'Toelichting Tabel Klassengrenzen; procedure teamindeling'
        );
    }
    if ($bron['gender'] === 'x' || $doel['gender'] === 'x') {
        $warnings[] = 'Bij een mix-team is niet automatisch te bepalen of de geslachtsregel wordt nageleefd (heren/dames en jongens/meisjes niet door elkaar zonder toestemming).';
    }

    // 2. Doel in categorie III: geen niveauregels
    if ($doel['categorie'] === 3) {
        $refs[] = 'BR art. 6.3 (geen niveauregels in categorie III)';
        if ($doel['kind'] === 'jongstejeugd' && $bron['kind'] !== 'jongstejeugd') {
            return $nee('De speler valt buiten de leeftijdsgrenzen van de jongste jeugd (O7–O10).', 'BR art. 6.2.2');
        }
        if ($doel['kind'] === 'ddw') {
            $warnings[] = 'Voor doordeweekse competities gelden geen leeftijdsgrenzen en geen niveauregels.';
            $refs[] = 'BR art. 6.2.1';
        }
        return ['verdict' => 'ja', 'reasons' => ['Het doelteam speelt in categorie III; daar gelden geen niveau- of invalregels.'],
                'conditions' => [], 'warnings' => $warnings, 'refs' => $refs];
    }

    // 3. Jeugd mag nooit invallen bij 30+/45+
    $bronJeugd = in_array($bron['kind'], ['junior', 'jongstejeugd'], true);
    if ($bronJeugd && in_array($doel['kind'], ['30plus', '45plus'], true)) {
        return $nee('Jeugdspelers mogen nooit invallen bij 30+ en 45+ teams (leeftijdsgrenzen).', 'BR art. 5.3.5.3');
    }

    // 4. Jongste jeugd
    if ($bron['kind'] === 'jongstejeugd') {
        if ($doel['kind'] === 'junior') {
            return ['verdict' => 'ja',
                'reasons' => ['Een speler uit de jongste jeugd mag altijd invallen bij 11-tallen (incl. O11), ongeacht het speelniveau.'],
                'conditions' => [], 'warnings' => $warnings, 'refs' => ['Toelichting Tabel Klassengrenzen']];
        }
        return $nee('Een speler uit de jongste jeugd voldoet niet aan de leeftijdsgrenzen voor senioren-/O25-teams.', 'BR art. 3.1');
    }

    // Leeftijdsvoorwaarden (worden hieronder als voorwaarde toegevoegd)
    $ageRank = fn(string $a): int => match ($a) {
        'o11' => 11, 'o12' => 12, 'o14' => 14, 'o16' => 16, 'o18' => 18,
        default => 99, // senioren-achtig
    };
    $needsAgeCheck = false;
    $ageText = '';
    $ageRef  = '';
    if ($doel['kind'] === 'junior' && $ageRank($doel['age']) < $ageRank($bron['age'])) {
        $needsAgeCheck = true;
        $ageText = 'De speler moet zelf voldoen aan de leeftijdsgrens van ' . strtoupper($doel['age'])
                 . ' (peildatum 1 oktober).';
        $ageRef  = 'BR art. 3.1.1';
    } elseif ($doel['kind'] === 'o25' && !$bronJeugd) {
        $needsAgeCheck = true;
        $ageText = 'De speler moet op 1 oktober maximaal 25 jaar zijn (per wedstrijd mogen max. 2 spelers tot 28 jaar meedoen).';
        $ageRef  = 'BR art. 5.2.1';
    } elseif ($doel['kind'] === '30plus' && !$bronJeugd) {
        $needsAgeCheck = true;
        $ageText = 'De speler moet op 1 oktober minimaal 30 jaar zijn (per wedstrijd mogen max. 2 spelers van 27–29 jaar meedoen).';
        $ageRef  = 'BR art. 5.2.2';
    } elseif ($doel['kind'] === '45plus' && !$bronJeugd) {
        $needsAgeCheck = true;
        $ageText = 'De speler moet op 1 oktober minimaal 45 jaar zijn (per wedstrijd mogen max. 4 spelers van 40–44 jaar meedoen).';
        $ageRef  = 'BR art. 5.2.3';
    }
    if ($needsAgeCheck) {
        if ($leeftijdOk === false) {
            return $nee($ageText . ' Deze speler voldoet daar niet aan.', $ageRef);
        }
        if ($leeftijdOk === null) {
            $conditions[] = $ageText;
            $refs[] = $ageRef;
        }
    }

    // 5. Doel = standaardteam (H1/D1): invallen omhoog mag altijd binnen de club
    if ($doel['kind'] === 'standaard') {
        $warnings[] = 'Het standaardteam valt onder categorie I: wie even vaak of vaker voor het standaardteam uitkomt dan voor het eigen team, speelt zich vast (na 3 gespeelde wedstrijden).';
        $refs[] = 'BR art. 4.3.3 / 4.3.5.1';
        if (($doel['klasse_n'] ?? 0) === 1 || ($doel['klasse_n'] ?? 0) === 2) {
            $warnings[] = 'Standaardteams 1e/2e Klasse: na 15 maart is een speler alleen speelgerechtigd als hij op de teamopgave staat of vóór 15 maart al voor dit team uitkwam (jeugdspelers uitgezonderd).';
            $refs[] = 'BR art. 4.4.2';
        }
        if ($beslissing === true) {
            $warnings[] = 'Voor beslissingswedstrijden (kampioenschappen, play-offs, aangewezen wedstrijden) gelden extra eisen.';
            $refs[] = 'BR art. 4.9';
        }
        return ['verdict' => empty($conditions) ? 'ja' : 'mits',
            'reasons' => ['Invallen bij een hoger of gelijk spelend team van de eigen vereniging mag altijd; jeugdspelers mogen altijd invallen bij standaardteams.'],
            'conditions' => $conditions, 'warnings' => $warnings, 'refs' => array_merge($refs, ['BR art. 4.3.5 / 5.3.5.3'])];
    }

    // 6. Bron = standaardteam: positie op de teamopgave bepaalt alles
    if ($bron['kind'] === 'standaard') {
        $refs[] = 'BR art. 4.4.1 / 4.5.2';
        if ($doel['opleiding']) {
            $conditions[] = 'Alleen spelers op positie 10 t/m 18 van de teamopgave/spelerslijst mogen naast het standaardteam voor het opleidingsteam uitkomen.';
            $refs[] = 'BR art. 4.10';
            return ['verdict' => 'mits',
                'reasons' => ['Het doelteam is het opleidingsteam van de vereniging.'],
                'conditions' => $conditions, 'warnings' => $warnings, 'refs' => $refs];
        }
        $conditions[] = 'Spelers op positie 1 t/m 9 van de teamopgave mogen nooit lager uitkomen; positie 10 t/m 18 alleen voor het opleidingsteam. Alleen voor spelers op positie 19 of hoger gelden de categorie II-regels (art. 5.3).';
        return ['verdict' => 'mits',
            'reasons' => ['De speler komt uit het standaardteam (categorie I); zijn positie op de teamopgave bepaalt of hij lager mag uitkomen.'],
            'conditions' => $conditions, 'warnings' => $warnings, 'refs' => $refs];
    }

    // 7. O12-/O14-jeugd (veld) mag altijd invallen bij O25- en seniorenteams
    if (in_array($bron['age'], ['o12', 'o11', 'o14'], true)
        && in_array($doel['kind'], ['reserve', 'o25'], true)) {
        return ['verdict' => empty($conditions) ? 'ja' : 'mits',
            'reasons' => ['Een speler uit de O12- of O14-jeugd mag altijd invallen bij een O25- en/of seniorenteam (veldhockey).'],
            'conditions' => $conditions, 'warnings' => $warnings, 'refs' => array_merge($refs, ['BR art. 5.3.5.3'])];
    }

    // Niveau nodig vanaf hier
    if ($bron['level'] === null || $doel['level'] === null) {
        return ['verdict' => 'onbekend',
            'reasons' => ['De klasse van ' . ($bron['level'] === null ? $bron['name'] : $doel['name'])
                . ' kon niet worden bepaald. Kies de klasse handmatig om een oordeel te krijgen.'],
            'conditions' => $conditions, 'warnings' => $warnings, 'refs' => $refs];
    }

    $diff = $doel['level'] - $bron['level']; // > 0: doel speelt lager dan bron
    $uitleg = inval_tabel_uitleg($bron, $doel);

    // 8. Specifieke matrices (art. 5.3.5.3–5.3.5.5): alleen zijwaarts of omhoog
    $matrix =
        ($bron['kind'] === 'junior' && in_array($doel['kind'], ['reserve', 'o25'], true)) ||
        ($bron['kind'] === 'reserve' && in_array($doel['kind'], ['o25', '30plus', '45plus'], true)) ||
        (in_array($bron['kind'], ['o25', '30plus', '45plus'], true) && $doel['kind'] === 'reserve');

    if ($matrix) {
        $ref = $bron['kind'] === 'junior' ? 'BR art. 5.3.5.3'
             : ($bron['kind'] === 'reserve' ? 'BR art. 5.3.5.4' : 'BR art. 5.3.5.5');
        if ($diff > 0) {
            $res = $nee(
                sprintf('%s (%s) speelt op een hoger niveau dan %s (%s): volgens het reglement mag een speler uit %s alleen invallen in teams die op gelijk of hoger niveau spelen.',
                    $bron['name'], inval_level_label($bron), $doel['name'], inval_level_label($doel), $bron['name']),
                $ref
            );
            if ($uitleg) $res['reasons'][] = $uitleg;
            return $res;
        }
        $refs[] = $ref;
        $reden = $diff === 0
            ? sprintf('%s (%s) en %s (%s) spelen op gelijk niveau; invallen is dan toegestaan.',
                $bron['name'], inval_level_label($bron), $doel['name'], inval_level_label($doel))
            : sprintf('%s (%s) speelt op een lager niveau dan %s (%s); invallen is dan toegestaan.',
                $bron['name'], inval_level_label($bron), $doel['name'], inval_level_label($doel));
        return ['verdict' => empty($conditions) ? 'ja' : 'mits',
            'reasons' => array_values(array_filter([$reden, $uitleg])),
            'conditions' => $conditions, 'warnings' => inval_std_warnings($bron, $doel, $warnings, $beslissing, $refs), 'refs' => $refs];
    }

    // 9. Generieke niveauvergelijking (art. 4.3.6/4.3.7 en 5.3.5.1/5.3.5.2)
    if ($diff <= 0) {
        // Bron speelt gelijk of lager: altijd toegestaan
        $refs[] = 'BR art. 5.3.5.1';
        $reden = $diff === 0
            ? sprintf('%s (%s) en %s (%s) spelen op gelijk niveau; een team mag altijd invallers lenen uit een gelijk of lager spelend team.',
                $bron['name'], inval_level_label($bron), $doel['name'], inval_level_label($doel))
            : sprintf('%s (%s) speelt op een lager niveau dan %s (%s); een team mag altijd invallers lenen uit een gelijk of lager spelend team.',
                $bron['name'], inval_level_label($bron), $doel['name'], inval_level_label($doel));
        return ['verdict' => empty($conditions) ? 'ja' : 'mits',
            'reasons' => array_values(array_filter([$reden, $uitleg])),
            'conditions' => $conditions, 'warnings' => inval_std_warnings($bron, $doel, $warnings, $beslissing, $refs), 'refs' => $refs];
    }

    $beideJunior4e = $bron['kind'] === 'junior' && $doel['kind'] === 'junior'
        && ($bron['klasse_n'] ?? 0) >= 4 && ($doel['klasse_n'] ?? 0) >= 4;

    if ($diff === 1 || $beideJunior4e) {
        // Lenen uit een (één klasse) hoger spelend team: alleen onder voorwaarden
        $refs[] = 'BR art. 5.3.5.2';
        if ($lte11 === false && !$keeper) {
            return $nee('Het doelteam heeft meer dan 11 beschikbare spelers; lenen uit een hoger spelend team is dan niet toegestaan.', 'BR art. 5.3.5.2');
        }
        if ($geenAlt === false) {
            return $nee('Er zijn invallers beschikbaar uit een gelijk of lager spelend niveau; die gaan voor.', 'BR art. 5.3.5.2');
        }
        if ($lte11 === null && !$keeper) {
            $conditions[] = 'Het doelteam heeft aantoonbaar maximaal 11 beschikbare spelers (eigen spelers + invallers uit gelijk/lager niveau).';
        }
        if ($geenAlt === null) {
            $conditions[] = 'Er zijn aantoonbaar geen invallers beschikbaar uit een gelijk of lager spelend niveau.';
        }
        $conditions[] = 'Er mogen maximaal 2 spelers invallen die hoger spelen, inclusief een vaste doelverdediger.';
        if ($keeper) {
            $warnings[] = 'Voor het inlenen van een doelverdediger geldt het maximum aantal eigen spelers niet.';
        }
        $reason = $beideJunior4e && $diff > 1
            ? sprintf('Beide teams spelen in de junioren 4e klasse of lager; daar mag onderling worden ingevallen, mits aan de voorwaarden is voldaan.')
            : sprintf('%s (%s) speelt één klasse hoger dan %s (%s); lenen uit een hoger spelend team mag alleen onder voorwaarden.',
                $bron['name'], inval_level_label($bron), $doel['name'], inval_level_label($doel));
        return ['verdict' => 'mits', 'reasons' => array_values(array_filter([$reason, $uitleg])),
            'conditions' => $conditions,
            'warnings' => inval_std_warnings($bron, $doel, $warnings, $beslissing, $refs), 'refs' => $refs];
    }

    $res = $nee(
        sprintf('%s (%s) speelt meer dan één klasse hoger dan %s (%s); invallen is dan niet toegestaan (ook niet met weinig spelers).',
            $bron['name'], inval_level_label($bron), $doel['name'], inval_level_label($doel)),
        'BR art. 5.3.5.2'
    );
    if ($uitleg) $res['reasons'][] = $uitleg;
    return $res;
}

// Standaard-waarschuwingen die bij elk positief oordeel horen
function inval_std_warnings(array $bron, array $doel, array $warnings, ?bool $beslissing, array &$refs): array
{
    if ($doel['level'] !== null && $bron['level'] !== null && $doel['level'] < $bron['level']) {
        $warnings[] = 'Let op vastspelen: wie binnen de vereniging even vaak of vaker uitkomt voor het hogere team dan voor het eigen team, krijgt de hogere niveaubepaling en mag niet meer lager uitkomen.';
        $refs[] = 'BR art. 5.3.4';
    }
    if ($beslissing === true) {
        $warnings[] = 'Dit is een beslissingswedstrijd: alleen spelers met een passende niveaubepaling mogen meedoen (o.a. laatste competitierondes, kampioenschappen).';
        $refs[] = 'BR art. 5.3.6';
    }
    if (($doel['categorie'] ?? null) === 1 || ($bron['categorie'] ?? null) === 1) {
        $warnings[] = 'Eén van de teams speelt in categorie I; daar gelden strengere regels (o.a. clubgebonden spelers, beslissingswedstrijden, aanvullende jeugdbepalingen art. 4.3.8/4.8).';
        $refs[] = 'BR hoofdstuk 4';
    }
    if ($doel['opleiding'] || $bron['opleiding']) {
        $warnings[] = 'Opleidingsteam: hiervoor gelden aanvullende regels (art. 4.10), o.a. dat de laatste 3 wedstrijden beslissingswedstrijden zijn.';
        $refs[] = 'BR art. 4.10';
    }
    return $warnings;
}

// Leesbaar klasse-label, bijv. "2e klasse"; het interne niveau-getal
// (de rij in de Tabel Klassengrenzen) tonen we bewust niet aan de gebruiker.
function inval_level_label(array $p): string
{
    return match ($p['klasse']) {
        'klasse'  => ($p['klasse_n'] ?? '?') . 'e klasse',
        null      => 'klasse onbekend',
        default   => ucfirst((string)$p['klasse']),
    };
}

// Kolom in de Tabel Klassengrenzen waar dit team onder valt, bijv. "O14",
// "senioren" of "30+". Klassenummers zijn alleen binnen dezelfde kolom
// direct vergelijkbaar; tussen kolommen vergelijkt de tabel rij voor rij.
function inval_tabel_kolom(array $p): string
{
    return match ($p['kind']) {
        'junior'               => strtoupper($p['age']),
        'reserve', 'standaard' => 'senioren',
        'o25'                  => 'O25',
        '30plus'               => '30+',
        '45plus'               => '45+',
        default                => $p['kind'],
    };
}

/**
 * Extra uitlegzin voor oordelen waarbij bron en doel in verschillende
 * kolommen van de Tabel Klassengrenzen vallen (andere leeftijdscategorie of
 * teamsoort). Een 2e klasse O14 kan dan gelijk staan aan een 4e klasse O18;
 * zonder deze zin lijkt de niveauvergelijking voor de lezer tegenstrijdig.
 * Geeft null binnen dezelfde kolom of als een niveau onbekend is.
 */
function inval_tabel_uitleg(array $bron, array $doel): ?string
{
    if ($bron['level'] === null || $doel['level'] === null) return null;
    $kolomBron = inval_tabel_kolom($bron);
    $kolomDoel = inval_tabel_kolom($doel);
    if ($kolomBron === $kolomDoel) return null;

    $diff    = $bron['level'] - $doel['level']; // > 0: bron speelt lager
    $tellen  = [1 => 'één', 2 => 'twee', 3 => 'drie', 4 => 'vier', 5 => 'vijf', 6 => 'zes'];
    $n       = $tellen[abs($diff)] ?? (string)abs($diff);
    $klassen = abs($diff) === 1 ? 'klasse' : 'klassen';
    $relatie = match (true) {
        $diff === 0 => 'op gelijke hoogte met',
        $diff > 0   => "$n $klassen lager dan",
        default     => "$n $klassen hoger dan",
    };
    $lidBron = str_contains(mb_strtolower(inval_level_label($bron)), 'klasse') ? 'de ' : '';
    $lidDoel = str_contains(mb_strtolower(inval_level_label($doel)), 'klasse') ? 'de ' : '';
    return sprintf(
        'De teams spelen in verschillende leeftijdscategorieën; hun klassen zijn daarom niet één-op-één te vergelijken. '
        . 'Volgens de Tabel Klassengrenzen veldhockey staat %s%s bij de %s %s %s%s bij de %s.',
        $lidBron, inval_level_label($bron), $kolomBron, $relatie, $lidDoel, inval_level_label($doel), $kolomDoel
    );
}
