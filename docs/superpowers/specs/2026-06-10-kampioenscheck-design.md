# Kampioenscheck — design

**Datum:** 2026-06-10
**Doel:** een pagina die voor álle teams van HC Hilvarenbeek controleert of ze in hun
laatste/lopende competitie al kampioen zijn, het nog kunnen worden, of niet meer — met uitleg waarom.

## Context

- Data komt uit de HockeyWeerelt HAPI (`includes/hapi.php` + `includes/api.php`).
- `GET /clubs/HH11HQ7` → 51 teams, elk met `recent_poule_id`, `hockey_type` (VE/ZA),
  `category_group_name` (Senioren / Junioren / Jongste jeugd).
- `GET /poules/{id}` → `standings[]` (rank, points, played, wins, draws, losses,
  goals_for, goals_against, team.id) en `matches[]` (status `final`/`scheduled`,
  score, home.id, away.id).

## Reglement (Bondsreglement 2025-2026, art. 2.6 / 2.6.1)

- Punten: winst 3, gelijk 1, verlies 0.
- Bij gelijke punten beslist achtereenvolgens: (1) meeste gewonnen wedstrijden,
  (2) doelsaldo, (3) doelpunten vóór, (4) onderling resultaat, (5) play-off.
- Jongste jeugd: uitslagen en ranglijsten worden niet gepubliceerd — geen kampioenschap (art. 2.6).

## Architectuur

Zelfde patroon als de Invalcheck: pure rekenmodule zonder I/O + dunne pagina.

1. **`includes/kansen.php`** — pure engine, unit-testbaar.
   - `kampioen_check(array $standings, array $matches, string|int $teamId): array`
     → `['verdict' => ..., 'reasons' => [...], 'stats' => [...]]`
   - Berekent per team: huidige punten/rank uit standings; resterende wedstrijden per
     team uit `matches[]` (status ≠ `final` telt als nog te spelen, behálve wedstrijden
     met een datum meer dan een week in het verleden — die zijn de facto afgelast;
     zonder die uitzondering lijken afgeronde zaalpoules nog open te staan).
   - Verdicts:
     - `kampioen` — punten van ons team > maximaal haalbare punten van elk ander team
       (of: competitie klaar en rank 1).
     - `bijna` — geen enkel team kan ons in punten passeren, maar minstens één team kan
       gelijk eindigen → beslissing valt op de tiebreaks van art. 2.6.1 (uitgelegd in reasons).
     - `mogelijk` — max haalbare punten ≥ huidige punten van elk ander team. Onderscheid
       in de uitleg: "eigen hand" (alles winnen volstaat, rekening houdend met onderlinge
       duels) vs. "hulp nodig" (ook afhankelijk van puntverlies van concurrenten).
     - `nee` — max haalbare punten < huidige punten van minstens één team (inhalen kan
       wiskundig niet meer), of competitie klaar en niet 1e.
     - `onbekend` — geen standings beschikbaar (bv. jongste jeugd) of team niet in de stand.
   - Pairwise-bound-kanttekening: de `mogelijk`-check is een noodzakelijke voorwaarde
     (concurrenten die nog tegen elkaar spelen kunnen het in werkelijkheid al eerder
     onmogelijk maken); exacte eliminatie is rekenkundig zwaar en wordt bewust niet gedaan.
     De uitleg formuleert het daarom als "theoretisch nog mogelijk".
   - "Eigen hand"-check pairwise correct: als wij alles winnen verliest concurrent O zijn
     resterende onderlinge duels tegen ons, dus O haalt max `punten_O + 3·(rest_O − rest_O_vs_ons)`.

2. **`kampioen.php`** (veld) + **`kampioen-zaal.php`** (zaal) — twee aparte pagina's
   (aanvulling 2026-06-10: was eerst één gecombineerde pagina).
   - `kampioen-zaal.php` zet `$KAMP_TYPE = 'ZA'` en include't `kampioen.php`; de pagina
     filtert de clubteams op `hockey_type` en toont een pill-switch Buiten ↔ Zaal.
   - Haalt clubteams op via `get_club_teams(CLUB_ID)`.
   - Jongste jeugd: geen API-calls, direct uitleg-notice (geen ranglijsten, art. 2.6).
   - Voor Senioren/Junioren: per team `GET /poules/{recent_poule_id}`, engine erop, resultaat
     gegroepeerd per categorie, met verdict-badge (zelfde kleurtaal als de Invalcheck),
     kerncijfers (plek, punten, nog te spelen) en een uitklapbare "waarom"-uitleg per team.
   - **Streaming met voortgang:** output buffering uit; elke teamrij wordt geflusht zodra
     de poule binnen is, een teller "x / y teams" loopt mee via kleine inline scripts en de
     samenvattingschips worden na afloop per script ingevuld. Zonder JavaScript renderen
     de rijen gewoon; alleen teller en samenvatting blijven dan leeg/staan.
   - Eerste load is ~17–21 API-calls per pagina (≈11 s, zichtbaar als voortgang); de
     bestaande file-cache (CACHE_TTL) vangt vervolgladingen op.
   - Link in de tab-navigatie van `index.php` ("Kampioen?"), naast de Invalcheck.

3. **`tests/kansen_test.php`** — plain PHP assertions (zelfde stijl als `rules_test.php`)
   met synthetische poules: zeker kampioen, klaar-en-1e, klaar-en-niet-1e, uitgeschakeld,
   eigen hand, hulp nodig, gelijk-op-max (tiebreak-uitleg), lege standings.

4. **`kampioen-embed.php`** — stand-alone iframe voor [www.hilverhockey.nl](https://www.hilverhockey.nl)
   (aanvulling 2026-06-10; de embed-modus stond eerst buiten scope).
   - Zelfde opzet en stijl als `inval.php?embed=1`: `assets/inval-embed.css`
     (+ nieuwe `.verdict-mogelijk`-kleur), Montserrat/Open Sans, pill-knop.
   - Flow: eerst competitievorm kiezen (veld/zaal), dan team (jongste jeugd
     uitgesloten, per categorie gegroepeerd), dan het oordeel als verdict-card
     met dezelfde reasons als de lijstpagina's. Titel bij `nee` hangt af van
     `finished`: "Geen kampioen geworden" vs. "Kan geen kampioen meer worden".
   - Auto-submit bij elke wijziging (vormwissel wist de teamkeuze); knop als
     no-JS-fallback. Geen streaming nodig: één team = één poule-call.
   - Hoogte naar parent via postMessage `{type:'kampioenscheck:height'}`;
     plak-snippet toegevoegd aan `embed-voorbeeld.html`.

## Buiten scope

- Promotie/degradatie (aparte jaarlijkse regeling, niet in de API).
- Exacte eliminatie-analyse over het hele resterende speelschema.
