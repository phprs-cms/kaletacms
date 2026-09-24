/* Kaleta - světlý / tmavý režim administrace: nastaví se před vykreslením stránky, aby neproblikla.
 * Samostatný soubor kvůli Content-Security-Policy administrace (žádné inline skripty). */
try { var t = localStorage.getItem('kaleta-tema'); if (t) { document.documentElement.setAttribute('data-tema', t); } } catch (e) {}
