<?php
// ============================================================
// Hockey-iframe configuratie
// Gebruik setup.php om je team-ID en poule-ID te vinden:
//   http://localhost/hockey-iframe/setup.php
// ============================================================

// Jouw club (verplicht voor de Invalcheck-tab)
// Dit is de federation_reference_id van de club — zie setup.php
define('CLUB_ID',   'HH11HQ7');   // HC Hilvarenbeek

// Jouw team (verplicht voor Programma/Uitslagen)
define('TEAM_ID',   '393');       // Hilvarenbeek D1 — zie setup.php

// Poule voor de stand (optioneel, leeglaten = geen standtab)
define('POULE_ID',  '173371');    // 2e klasse Dames, poule C — zie setup.php

// Weergavenamen
define('CLUB_NAME', 'HC Hilvarenbeek');
define('TEAM_NAME', 'Dames 1');

// Kleuren (CSS hex-codes)
define('COLOR_PRIMARY', '#003087');   // Hoofdkleur (achtergrond header/tabs)
define('COLOR_ACCENT',  '#ffd700');   // Accentkleur (actieve tab, highlights)
define('COLOR_BG',      '#ffffff');   // Achtergrond widget
define('COLOR_TEXT',    '#1a1a1a');   // Tekstkleur

// Cache (seconden — 0 = uitgeschakeld)
define('CACHE_TTL',  300);
define('CACHE_DIR',  __DIR__ . '/cache');
