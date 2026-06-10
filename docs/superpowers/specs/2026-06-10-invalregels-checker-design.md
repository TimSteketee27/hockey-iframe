# Design: KNHB Invalregels-checker voor HC Hilvarenbeek

Datum: 2026-06-10
Status: vastgesteld (autonome goal-sessie; aannames gemarkeerd met ⚠)

## Doel

De widget uitbreiden met een **Invalcheck**: selecteer het eigen team (waarin wordt
ingevallen) en het team waar de invaller vandaan komt, beide van HC Hilvarenbeek.
De applicatie zegt of de speler mag invallen en zo niet, waarom niet — op basis van
het KNHB Bondsreglement 2025-2026 (versie 8 jan 2026) en de Tabel Klassengrenzen
veldhockey 2025-2026 (beide gearchiveerd in `docs/`).

## Bronnen

- `docs/Bondsreglement-2025.pdf` (+ `bondsreglement-2025.txt`) — artikelen 3, 4.3, 5.3, 6.3
- `docs/Tabel-Klassengrenzen-veld-25-26.pdf` — klasse-equivalenties tussen categorieën
- knhb.nl/competitie/invallen (navigatiepagina, verwijst naar bovenstaande)

## Belangrijke ontdekking: oude API is dood

`publicaties.hockeyweerelt.nl/mc` geeft een permanente redirect-loop (KNHB/HockeyWeerelt
is in 2026 naar een nieuw platform gemigreerd). De vervangende publieke matchcenter-API is
`https://app.hockeyweerelt.nl` met anonieme device-registratie + request-signing:

1. `POST /device/register` `{uuid, os: "Web"}` → `{token}` (JWT, lang geldig)
2. Elke GET krijgt headers: `X-HAPI-Authorization: <token>`, `X-HAPI-Timestamp: <unix>`,
   `X-HAPI-Signature: sha1(timestamp + sanitizedPath + sanitizedQuery + reverse(uuid))`,
   `X-HAPI-Version: 7`
3. Endpoints (geverifieerd werkend):
   - `GET /clubs` — alle clubs; HC Hilvarenbeek = `federation_reference_id` **HH11HQ7**
   - `GET /clubs/{ref}` — clubdetail incl. `teams[]` (id, name, short_name, hockey_type
     VE/ZA, category_group_name, recent_poule_id)
   - `GET /poules/{id}/teams/{teamId}` — team + poules incl. `competition.class_name`
   - `GET /poules/{id}` — poule incl. `standings[]` (voor Stand-tab)
   - `GET /matches/team?team_id[]={id}` — wedstrijden (status, score.home/away, location)

De bestaande tabs (Programma/Uitslagen/Stand) worden mee-geport naar deze API, anders
blijft de hele widget kapot.

## Overwogen aanpakken

- **A. Volledig API-gedreven** — klasse en categorie volledig automatisch. Fragiel:
  klasse-parsing kan falen, API kan wegvallen.
- **B. Hybride (gekozen)** — teamlijst en klasse automatisch uit de API; de regels-engine
  is puur en werkt op een "teamprofiel" (geslacht, leeftijdscategorie, klasse-niveau,
  teamtype). Bij onbekende klasse kan de gebruiker die handmatig kiezen. Onzekerheden
  worden als voorwaarde/waarschuwing getoond i.p.v. een fout oordeel.
- **C. Volledig handmatig** — geen API. Voldoet niet aan het doel ("team selecteren").

## Architectuur

```
includes/hapi.php    Nieuwe HockeyWeerelt-client: device-token (gecached in cache/),
                     gesigneerde GET's, zelfde file-cache als voorheen.
includes/api.php     Bestaande publieke functies behouden, intern geport naar hapi.php.
includes/rules.php   Pure regels-engine, geen I/O. Unit-getest.
inval.php            Invalcheck-pagina (GET-formulier, geen JS-dependency).
index.php            Extra tab "Invalcheck" → inval.php.
tests/rules_test.php Assertions, draait via `php tests/rules_test.php`.
config.php           + CLUB_ID ('HH11HQ7').
```

### rules.php — datamodel

`inval_team_profile(array $team, ?string $className): array` leidt uit short_name
(bijv. `D2`, `H30-1`, `DO25-1-O`, `MO16-2`, `DDW1`, `MO9-Geel`, prefix `z` = zaal)
en `class_name` af:

- `gender`: m / v / mix(?) — D*/MO*/DDW = v, H*/JO* = m, `-mix` = onbekend
- `age`: senior | O25 | 30+ | 45+ | O18 | O16 | O14 | O12 | O11 | jongste-jeugd
- `kind`: standaard (H1/D1) | reserve | o25 | 30plus | 45plus | junior | jongstejeugd
  | doordeweeks (DDW) | opleidingsteam (suffix -O)
- `categorie`: 1 (standaard, Gold/Silver Cup, jeugd Landelijk/Super/Topklasse/Subtop*)
  | 2 (reserve, O25, 30+, 45+, overige jeugd) | 3 (jongste jeugd, doordeweeks, trim)
- `level`: numeriek niveau volgens Tabel Klassengrenzen veld (lager = hoger niveau):

| niveau | O11/O12 | O14 | O16 | O18 | O25 | Reserve | 30+ | 45+ |
|--------|---------|-----|-----|-----|-----|---------|-----|-----|
| 1 | | | | | | Res. Hoofdklasse | | |
| 2 | | | | | | Res. Overgangskl. | Hoofdkl. | |
| 3 | | | | Landelijk/Super/Topkl. | Overgangskl. | Res. 1e | Overgangskl. | Hoofdkl. |
| 4 | | | Landelijk/Super/Topkl. | Subtopkl. | 1e | Res. 2e | 1e | Overgangskl. e.l. |
| 5 | | Super/IDC/Topkl. | Subtopkl. | 1e | 2e | Res. 3e | 2e | |
| 6 | | Subtopkl. | 1e | 2e | 3e | Res. 4e | 3e e.l. | |
| 7 | Topkl. | 1e | 2e | 3e | 4e | Res. 5e e.l. | | |
| 8 | 1e | 2e | 3e | 4e | 5e e.l. | | | |
| 9 | 2e | 3e | 4e | 5e e.l. | | | | |
| 10 | 3e | 4e | 5e e.l. | | | | | |
| 11 | 4e | 5e e.l. | | | | | | |
| 12 | 5e e.l. | | | | | | | |

`check_inval(array $bron, array $doel, array $opts): array` →
`['verdict' => 'ja'|'mits'|'nee'|'onbekend', 'reasons' => [], 'conditions' => [],
'warnings' => [], 'refs' => []]`

`$opts`: `keeper` (bool), `spelers_beschikbaar` ('lte11'|'gt11'|null),
`alternatief_beschikbaar` (bool|null), `leeftijd_ok` (bool|null),
`beslissingswedstrijd` (bool|null).

### Regelvolgorde in check_inval (veldhockey)

1. **Zaalteam** (bron of doel) → verdict `onbekend`: zaal heeft eigen regels; checker
   dekt veldhockey (art. 5.3.5 zaal-varianten staan in het reglement maar UI filtert
   op veldteams).
2. **Zelfde team** → nee (geen invalsituatie).
3. **Geslacht** ongelijk (en geen mix-team) → **nee** — heren/dames en jongens/meisjes
   niet zonder toestemming competitieleiding (toelichting Tabel Klassengrenzen;
   procedure teamindeling). Mix-team betrokken → voorwaarde i.p.v. nee.
4. **Doel = categorie III** (jongste jeugd, doordeweeks) → **ja** met noot: geen
   niveauregels (art. 6.3); doordeweeks ook geen leeftijdsgrenzen (6.2.1); jongste
   jeugd leeftijdsgrenzen 6.2.2.
5. **Jeugd → 30+/45+** → **nee** (art. 5.3.5.3: jeugd mag nooit bij 30+/45+).
6. **Senior(achtig) bron → junioren doel** → voorwaarde "speler moet zelf de
   juiste leeftijd voor {O##} hebben" (kan alleen bij jeugdspelers die in een
   seniorenteam spelen); plus niveaucheck hieronder.
7. **Doel = standaardteam (H1/D1)** → **mits**: jeugdspelers mogen altijd invallen bij
   standaardteams (5.3.5.3); senioren mogen omhoog invallen (5.3.5/4.3.5). Voorwaarden:
   clubgebonden speler (4.1), let op vastspelen (4.3.3/4.3.5.1), na 15 maart 1e/2e
   Klasse-regel (4.4.2), beslissingswedstrijden (4.9).
8. **Bron = standaardteam** → **nee, tenzij**: teamopgave-positie 1-9 mag nooit lager
   (4.4.1); 10-18 alleen opleidingsteam (4.10); 19+ valt onder cat II-regels → verdict
   `mits` met die uitleg; als doel het opleidingsteam is (suffix -O): mits positie 10-18.
9. **O12/O14-jeugd → O25/senioren** → **ja** (5.3.5.3: mag altijd, veld).
10. **Specifieke matrices** (veld):
    - jeugd O18/O16 → senioren reserve/O25: toegestane doelrange per bronklasse
      (5.3.5.3); buiten range → nee.
    - reserve → O25/30+/45+ (5.3.5.4); buiten range → nee.
    - O25/30+/45+ → reserve (5.3.5.5); buiten range → nee.
11. **Generieke niveauvergelijking** (4.3.6/5.3.5.1/5.3.5.2) via `level`:
    - bron.level ≥ doel.level → **ja** (invallen op gelijk of hoger spelend team mag
      altijd; bij invallen in lagere leeftijdscategorie: voorwaarde juiste leeftijd).
    - bron.level = doel.level − 1 (bron speelt één klasse hoger) → **mits**:
      (a) doelteam ≤ 11 beschikbare spelers (n.v.t. voor keepers), (b) aantoonbaar
      geen invallers uit gelijk/lager niveau, (c) max 2 zulke invallers per wedstrijd
      incl. vaste keeper.
    - junioren-uitzondering: beide teams junioren én beide 4e klasse of lager → zelfde
      mits-voorwaarden, ook bij >1 klasse verschil (5.3.5.2).
    - anders → **nee** (meer dan één klasse hoger).
12. **Altijd-waarschuwingen** indien relevant: vastspelen/doorspelen (5.3.4: wie even
    vaak of vaker hoger speelt, speelt zich vast), beslissingswedstrijden (5.3.6:
    niveaubepaling vereist; laatste rondes), cat I-bron of -doel (strenger regime,
    art. 4), niet-clubgebonden spelers via flexplatform (5.1).

⚠ Aannames (gemarkeerd in UI met "let op"):
- Teamopgave-posities, teamlijsten en gespeelde wedstrijden van individuele spelers
  zijn niet via de publieke API beschikbaar; speler-specifieke regels (niveaubepaling,
  vastspelen, 50%-regel) worden als voorwaarde/waarschuwing getoond, niet beslist.
- "6e klasse" e.d. worden op "5e klasse en lager" gemapt conform de tabel.
- Promotieklasse (standaard) wordt als cat I standaard behandeld.

### inval.php — UI-flow

GET-formulier, server-side gerenderd (geen JS nodig, consistent met de widget):

1. Dropdown **"Team waarin wordt ingevallen"** en **"Team van de invaller"** — veldteams
   van HC Hilvarenbeek uit `/clubs/HH11HQ7` (zaalteams en jongste-jeugd blijven kiesbaar
   maar krijgen passende uitkomst). Klasse per team via poule-lookup, gecached.
2. Optionele verfijning (checkboxes/selects): keeper?, ≤11 spelers?, alternatief uit
   gelijk/lager niveau?, juiste leeftijd?, beslissingswedstrijd?
3. Resultaatkaart: groot ✓ / ⚠ / ✗ + redenen, voorwaarden, waarschuwingen en
   artikelverwijzingen. Footer-link naar Bondsreglement.

Fallback: API onbereikbaar → melding + handmatige modus (categorie/klasse-dropdowns).

### Teststrategie

`tests/rules_test.php` codeert alle voorbeelden uit het Bondsreglement als asserts,
o.a.: JO18-2 Subtop→JO18-1 Top (ja), MO16-3 2e→MO16-1 Subtop (ja), H4 2e←H3 2e (ja),
D2 2e←D5 3e (ja), JO18-2 3e←JO14-2 1e (ja), MO16-3 2e←MO18-3 3e (mits leeftijd),
res. 2e←res. 1e (mits), JO16-2 1e←JO18-3 1e (mits), JO16-3 3e←JO18-3 2e (nee),
JO16-3 3e←JO18-4 3e (mits), MO14-6 6e←MO14-5 4e (mits, junioren-uitzondering),
jeugd→30+ (nee), geslacht (nee), O14→senioren (ja), DDW (ja).

## Scope-afbakening

- Veldhockeyregels; zaalteams worden herkend en uitgefilterd/gemeld.
- Geen speler-administratie (geen DWF/teamopgave-data beschikbaar).
- Dispensaties competitieleiding worden genoemd, niet gemodelleerd.
