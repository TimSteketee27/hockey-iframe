<?php
declare(strict_types=1);

require_once __DIR__ . '/hapi.php';

/**
 * KNHB / HockeyWeerelt API-functies voor de widget.
 *
 * Sinds 2026 draait het publieke matchcenter op app.hockeyweerelt.nl (zie
 * hapi.php). Clubs worden geïdentificeerd met een federation_reference_id
 * (bijv. 'HH11HQ7'), teams en poules met numerieke id's.
 */

// Extract the data array from a response, handling different envelope shapes
function unwrap(mixed $response): array
{
    if (!is_array($response)) return [];
    if (isset($response['data']) && is_array($response['data'])) return $response['data'];
    if (array_is_list($response)) return $response;
    return [];
}

// ── Public API functions ──────────────────────────────────────

function get_team_matches(string $teamId): ?array
{
    $resp = hapi_get('/matches/team', ['team_id[]' => $teamId]);
    if ($resp === null) return null;
    return unwrap($resp);
}

function get_upcoming_matches(): ?array
{
    $matches = get_team_matches(TEAM_ID);
    if ($matches === null) return null;
    $matches = array_values(array_filter($matches, fn($m) => ($m['status'] ?? '') === 'scheduled'));
    usort($matches, fn($a, $b) => strcmp(match_datetime($a), match_datetime($b)));
    return $matches;
}

function get_past_matches(): ?array
{
    // /matches/team geeft alleen komende wedstrijden; gespeelde wedstrijden
    // (met uitslag) zitten in de poule-respons.
    $matches = [];
    if (POULE_ID) {
        $resp = hapi_get('/poules/' . rawurlencode(POULE_ID));
        if ($resp === null) return null;
        $all = $resp['data']['matches'] ?? [];
        $matches = array_filter($all, function ($m) {
            $involved = (string)($m['home']['id'] ?? '') === (string)TEAM_ID
                     || (string)($m['away']['id'] ?? '') === (string)TEAM_ID;
            return $involved && ($m['status'] ?? '') !== 'scheduled';
        });
    } else {
        $all = get_team_matches(TEAM_ID);
        if ($all === null) return null;
        $matches = array_filter($all, fn($m) => ($m['status'] ?? '') !== 'scheduled');
    }
    $matches = array_values($matches);
    usort($matches, fn($a, $b) => strcmp(match_datetime($b), match_datetime($a)));
    return $matches;
}

function get_standing(): ?array
{
    if (!POULE_ID) return [];
    $resp = hapi_get('/poules/' . rawurlencode(POULE_ID));
    if ($resp === null) return null;
    $data = $resp['data'] ?? [];

    // Standings kunnen direct op de poule staan of in competition.poules[].
    if (!empty($data['standings']) && is_array($data['standings'])) {
        return $data['standings'];
    }
    foreach (($data['competition']['poules'] ?? []) as $poule) {
        if ((string)($poule['id'] ?? '') === (string)POULE_ID && !empty($poule['standings'])) {
            return $poule['standings'];
        }
    }
    foreach (($data['poules'] ?? []) as $poule) {
        if ((string)($poule['id'] ?? '') === (string)POULE_ID && !empty($poule['standings'])) {
            return $poule['standings'];
        }
    }
    return [];
}

// Search clubs by name (used in setup.php); filtert client-side, de API
// geeft altijd de volledige clublijst terug.
function search_clubs(string $name): ?array
{
    $resp = hapi_get('/clubs');
    if ($resp === null) return null;
    $clubs = unwrap($resp);
    $needle = mb_strtolower($name);
    $hits = array_filter($clubs, function ($c) use ($needle) {
        $hay = mb_strtolower(($c['name'] ?? '') . ' ' . ($c['friendly_name'] ?? '') . ' ' . ($c['city'] ?? ''));
        return str_contains($hay, $needle);
    });
    // 'id' aliassen voor weergave in setup.php
    return array_values(array_map(function ($c) {
        $c['id'] = $c['federation_reference_id'] ?? '';
        return $c;
    }, $hits));
}

// Get club detail incl. teams for a club reference (e.g. 'HH11HQ7')
function get_club(string $clubRef): ?array
{
    $resp = hapi_get('/clubs/' . rawurlencode($clubRef));
    if ($resp === null) return null;
    return $resp['data'] ?? null;
}

// Get teams for a club reference (used in setup.php and inval.php)
function get_club_teams(string $clubRef): ?array
{
    $club = get_club($clubRef);
    if ($club === null) return null;
    return $club['teams'] ?? [];
}

/**
 * Poule-info voor een team: ['class_name' => ..., 'competition_name' => ...]
 * Kiest uit de poules van het team de reguliere competitie (geen Cup/play-off)
 * waarvan de class_name op een klasse lijkt.
 */
function get_team_competition(string|int $teamId, string|int $pouleId): ?array
{
    $resp = hapi_get('/poules/' . rawurlencode((string)$pouleId) . '/teams/' . rawurlencode((string)$teamId));
    if ($resp === null) return null;

    $poules = $resp['data']['team']['poules'] ?? [];
    $best = null;
    foreach ($poules as $p) {
        $comp = $p['competition'] ?? [];
        $name = (string)($comp['national_competition_name'] ?? $comp['name'] ?? '');
        $cls  = (string)($comp['class_name'] ?? '');
        $entry = ['class_name' => $cls, 'competition_name' => $name];
        $isCup = stripos($name . ' ' . $cls, 'cup') !== false
              || stripos($name . ' ' . $cls, 'play') !== false
              || stripos($name . ' ' . $cls, 'KO ') !== false;
        if ($isCup) continue;
        if ((string)($p['id'] ?? '') === (string)$pouleId) return $entry;
        $best ??= $entry;
    }
    return $best;
}

// ── Match field helpers (nieuwe API-vorm, defensief) ──────────

function match_datetime(array $m): string
{
    return $m['date'] ?? $m['datetime'] ?? $m['date_time'] ?? '';
}

function match_home_name(array $m): string
{
    return $m['home']['name'] ?? $m['homeTeam']['name'] ?? $m['home_team']['name'] ?? '–';
}

function match_away_name(array $m): string
{
    return $m['away']['name'] ?? $m['awayTeam']['name'] ?? $m['away_team']['name'] ?? '–';
}

function match_is_played(array $m): bool
{
    return ($m['status'] ?? '') !== 'scheduled';
}

function match_home_score(array $m): ?int
{
    if (!match_is_played($m)) return null;
    $v = $m['score']['home'] ?? $m['homeScore'] ?? $m['home_score'] ?? null;
    return $v === null ? null : (int)$v;
}

function match_away_score(array $m): ?int
{
    if (!match_is_played($m)) return null;
    $v = $m['score']['away'] ?? $m['awayScore'] ?? $m['away_score'] ?? null;
    return $v === null ? null : (int)$v;
}

function match_location(array $m): string
{
    return $m['location']['facility']['name'] ?? $m['location']['name'] ?? $m['venue'] ?? '';
}

function match_competition(array $m): string
{
    return $m['competition_name'] ?? $m['poule_name'] ?? $m['competition']['name'] ?? '';
}

// Returns whether our team is playing at home
function match_is_home(array $m): bool
{
    $homeId = $m['home']['id'] ?? $m['homeTeam']['id'] ?? null;
    return $homeId !== null && (string)$homeId === (string)TEAM_ID;
}

// Ranking row helpers (standings uit /poules/{id})
function rank_team_name(array $row): string
{
    return $row['team']['name'] ?? $row['name'] ?? '–';
}

function rank_team_id(array $row): string
{
    return (string)($row['team']['id'] ?? $row['id'] ?? '');
}

function rank_val(array $row, string ...$keys): int
{
    foreach ($keys as $k) {
        if (isset($row[$k])) return (int)$row[$k];
    }
    return 0;
}
