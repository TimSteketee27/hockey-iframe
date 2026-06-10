# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Context

Lightweight PHP iframe widget that fetches Dutch hockey match data from the KNHB / HockeyWeerelt API and displays it in a clean, embeddable widget with five tabs: Programma (upcoming matches), Uitslagen (results), Stand (standings), **Invalcheck** (KNHB substitute-rules checker for HC Hilvarenbeek) and **Kampioen?** (per club team: already champion, still possible, or eliminated — with reasons; two pages: veld and zaal).

**No framework, no Composer, no build step.** Plain PHP 8.1+, one CSS file, no JS dependencies.

## Local Development

- **Server:** XAMPP — start via XAMPP Control Panel (Apache), or `c:\xampp\php\php.exe -S 127.0.0.1:8123 -t .`
- **Widget URL:** `http://localhost/hockey-iframe/`
- **Invalcheck:** `http://localhost/hockey-iframe/inval.php`
- **Kampioenscheck:** `http://localhost/hockey-iframe/kampioen.php` (veld) / `kampioen-zaal.php` (zaal) / `kampioen-embed.php` (iframe)
- **Setup helper:** `http://localhost/hockey-iframe/setup.php` — use this to find CLUB_ID and TEAM_ID
- **PHP version required:** 8.1+ (uses `match`, named args)
- **Tests:** `c:\xampp\php\php.exe tests\rules_test.php` — invalregels engine against the literal examples from the Bondsreglement; `c:\xampp\php\php.exe tests\kansen_test.php` — championship-chances engine

## File Structure

```text
hockey-iframe/
├── config.php          Configuration: CLUB_ID, TEAM_ID, POULE_ID, colors
├── index.php           Widget entry point — tab routing + HTML rendering
├── inval.php           Invalcheck: may a player substitute between two club teams?
│                       (`?embed=1` = stand-alone iframe styled like hilverhockey.nl)
├── kampioen.php        Kampioenscheck veld: per club team — champion / still possible / eliminated + why
├── kampioen-zaal.php   Kampioenscheck zaal (sets $KAMP_TYPE='ZA', includes kampioen.php)
├── kampioen-embed.php  Kampioenscheck iframe for hilverhockey.nl: pick veld/zaal, then team → verdict
├── embed-voorbeeld.html  How to embed the Invalcheck on hilverhockey.nl (Duda HTML widget)
├── setup.php           Club/team ID discovery tool (local use only)
├── includes/
│   ├── hapi.php        HockeyWeerelt client: device registration + signed requests + cache
│   ├── api.php         Widget-facing API functions and field helpers (uses hapi.php)
│   ├── rules.php       Pure KNHB invalregels engine (no I/O), unit-tested
│   └── kansen.php      Pure championship-chances engine (no I/O), unit-tested
├── tests/
│   ├── rules_test.php  Invalregels tests (Bondsreglement examples), plain PHP assertions
│   └── kansen_test.php Kampioenscheck tests (synthetic poule scenarios)
├── assets/
│   ├── style.css       All widget styles (uses CSS custom properties)
│   └── inval-embed.css Invalcheck embed styles matching the hilverhockey.nl Duda theme
├── docs/
│   ├── Bondsreglement-2025.pdf / .txt          KNHB Bondsreglement 2025-2026 (8 jan 2026)
│   ├── Tabel-Klassengrenzen-veld-25-26.pdf/.txt  Class-equivalence table (veld)
│   ├── Tabel-Klassengrenzen-zaal-25-26.pdf/.txt  Class-equivalence table (zaal)
│   └── superpowers/specs/                      Design docs
└── cache/              Auto-created; JSON responses per CACHE_TTL + hapi_device.json
```

## API (HockeyWeerelt "HAPI", since 2026)

The old public API (`publicaties.hockeyweerelt.nl/mc`) is **dead** (permanent redirect loop).
The current public matchcenter API is `https://app.hockeyweerelt.nl` and requires anonymous
device auth, implemented in `includes/hapi.php`:

1. `POST /device/register` `{uuid, os:"Web"}` → `{token}` (cached in `cache/hapi_device.json`)
2. Every GET is signed: `X-HAPI-Authorization: <token>`, `X-HAPI-Timestamp: <unix>`,
   `X-HAPI-Signature: sha1(ts . cleanPath . cleanQuery . strrev(uuid))`, `X-HAPI-Version: 7`
   (path stripped to `[a-zA-Z0-9-/]`, query pairs to `key=value` concatenated without separators;
   array params must be passed with bracket keys, e.g. `['team_id[]' => $id]`).

Endpoints used:

- `GET /clubs` — all clubs (no server-side name filter); club key is `federation_reference_id` (HC Hilvarenbeek = `HH11HQ7`)
- `GET /clubs/{ref}` — club detail incl. `teams[]` (id, name, short_name, hockey_type VE/ZA, category_group_name, recent_poule_id)
- `GET /matches/team?team_id[]={id}` — **upcoming** matches only
- `GET /poules/{id}` — poule incl. `standings[]` and **all** `matches[]` (played ones carry `score.home/away`) — used for Uitslagen + Stand
- `GET /poules/{pouleId}/teams/{teamId}` — team's poules incl. `competition.class_name` (e.g. "2e klasse") — used by Invalcheck for class detection

All field access goes through helpers in `api.php` (`match_home_name()`, `match_datetime()`, etc.).

## Invalcheck (inval.php + includes/rules.php)

Answers: may a player from team X substitute ("invallen") in team Y of the same club?
Based on **Bondsreglement 2025-2026** art. 3 (leeftijden), 4.3 (cat I), 5.3 (cat II), 6.3
(cat III) and the **Tabel Klassengrenzen veldhockey** (both archived in `docs/`).

Key design points:

- `inval_team_profile()` parses team short names (`D2`, `H30-1`, `DO25-1-O`, `MO16-2`, `DDW1`,
  `zMO18-1` = zaal, `-mix` = unknown gender) + the poule `class_name` into a profile:
  gender / age category / kind (standaard, reserve, o25, 30plus, 45plus, junior,
  jongstejeugd, ddw) / klasse / **level** (numeric row in the Tabel Klassengrenzen,
  1 = highest; equal level = equivalent class across age categories).
- `check_inval(bron, doel, opts)` returns `verdict` (`ja`/`mits`/`nee`/`onbekend`) +
  reasons/conditions/warnings/refs. Core logic: substituting sideways/up (bron.level ≥
  doel.level) is always allowed; borrowing from one class higher only under conditions
  (≤11 available players, no equal/lower alternative, max 2 such substitutes, keeper
  exempt from the player count); >1 class higher is forbidden except juniors 4e klasse
  and lower among themselves. Cross-group moves (jeugd→senioren, reserve↔O25/30+/45+)
  follow the explicit matrices of art. 5.3.5.3–5.3.5.5, which all reduce to
  "doel.level ≤ bron.level". Gender mixing and jeugd→30+/45+ are hard "nee".
  Category III targets (jongste jeugd, doordeweeks) have no niveauregels.
- Player-specific facts (age, teamopgave position, vastspelen, 50% rule for
  beslissingswedstrijden) cannot be derived from the public API → surfaced as
  conditions/warnings, optionally sharpened via form inputs.
- Engine covers **veldhockey**; zaal teams yield `onbekend` (zaal has its own matrices).

When the KNHB publishes a new Bondsreglement (each August), re-check: the level table,
the matrices in 5.3.5.3–5.3.5.5, and the category I competition list in art. 4.

## Kampioenscheck (kampioen.php + includes/kansen.php)

Answers per club team: already champion, almost, still possible, or eliminated — and why.
Based on Bondsreglement art. 2.6 (3-1-0 points) and 2.6.1 (tiebreaks: most wins,
goal difference, goals for, head-to-head, play-off).

- Two pages: `kampioen.php` (veld/buiten) and `kampioen-zaal.php` (sets `$KAMP_TYPE='ZA'`
  and includes kampioen.php); a pill switch on the page links between them.
- The page lists the club teams of that hockey_type (from `/clubs/{ref}`), fetches each
  team's `recent_poule_id` via `/poules/{id}` (standings + all matches) and runs
  `kampioen_check(standings, matches, teamId)` from `includes/kansen.php` (pure, no I/O).
- The page **streams**: output buffering is disabled and each team row is flushed as soon
  as its poule is fetched, with a live progress counter ("x / y teams") updated via tiny
  inline `<script>` chunks; the summary chips are filled in by script at the end
  (without JS the rows still render, only the summary stays empty).
- Verdicts: `kampioen` (unreachable, or season finished as #1), `bijna` (nobody can pass
  in points, but someone can equal → tiebreaks decide), `mogelijk` (max attainable ≥
  everyone's current points; explanation distinguishes "in eigen hand" vs needs help),
  `nee` (mathematically impossible / finished lower), `onbekend` (no standings).
- Heuristics, documented in the engine docblock: the "still possible" check is a
  pairwise bound (rivals' remaining head-to-head games among themselves are ignored, so
  it says "theoretisch mogelijk"); non-`final` matches dated >7 days in the past count
  as cancelled (typically finished zaal poules); the "eigen hand" check does subtract
  remaining direct duels against us from a rival's maximum.
- Jongste jeugd is skipped (KNHB publishes no rankings for them, art. 2.6).
- First uncached load makes ~17–21 poule calls per page (~11 s), visible as streamed
  progress; CACHE_TTL covers reloads.
- Promotion/relegation is out of scope (separate yearly KNHB regulation, not in the API).
- **`kampioen-embed.php`** is the stand-alone iframe variant for hilverhockey.nl, built
  like `inval.php?embed=1` (same `assets/inval-embed.css`, Duda look, auto-submit on
  change, height via postMessage `{type:'kampioenscheck:height'}`): pick veld or zaal,
  then a team (jongste jeugd excluded), and get one verdict card — only one poule call
  per check, so no streaming needed. Embed snippet lives in `embed-voorbeeld.html`.

## Configuration

Edit `config.php` to set:

- `CLUB_ID` — club `federation_reference_id`; required for the Invalcheck tab ('HH11HQ7')
- `TEAM_ID` — required for Programma/Uitslagen; find via setup.php
- `POULE_ID` — optional; enables the Stand tab and is the source for Uitslagen
- `COLOR_PRIMARY` / `COLOR_ACCENT` / `COLOR_BG` / `COLOR_TEXT` — club branding
- `CACHE_TTL` — seconds to cache API responses (0 = disabled)

## Embedding as iframe

```html
<iframe src="http://yoursite.nl/hockey-iframe/" width="100%" height="500" frameborder="0"></iframe>
```

The widget has no outer margins and adapts to the iframe width. On screens < 480px the location column is hidden.

### Invalcheck stand-alone on hilverhockey.nl

`inval.php?embed=1` renders the Invalcheck without widget chrome (no header/tabs/footer),
styled to blend into [www.hilverhockey.nl](https://www.hilverhockey.nl) (Duda site): Montserrat headings, Open Sans body,
club red `#ce0000`, pill buttons with dark hover — see `assets/inval-embed.css`. The page
posts its height to the parent via `postMessage` (`{type:'invalcheck:height', height}`) so
the iframe can grow without scrollbars; `embed-voorbeeld.html` contains the snippet to paste
into a Duda HTML widget. The form carries a hidden `embed=1` input so the mode survives
submits (GET).

The check runs automatically (both modes): a small script hides the submit button and
submits the form on every change once both teams are selected; a hidden `extra` input
keeps the "Extra gegevens" panel open across these reloads (`toggle` events fire
async — the handler syncs the input before any subsequent change/submit). Without
JavaScript the button remains as fallback.

## Caching

File-based JSON cache in `cache/`. Files are named by `md5(url)`; the device token lives in
`cache/hapi_device.json`. To force-refresh, delete files in `cache/` or set `CACHE_TTL=0`
temporarily.

## Security Note

`setup.php` exposes your KNHB club structure. Block or remove it in production:

```apache
# .htaccess
<Files "setup.php">
  Require local
</Files>
```
