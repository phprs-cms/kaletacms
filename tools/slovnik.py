#!/usr/bin/env python3
"""Doplní překlady do slovníku MiroCMS a správně je escapuje (apostrof v překladu jinak rozbije PHP soubor).

Použití: tools/slovnik.py system/jazyky/admin-en.php < radky    (řádek = "česky|překlad")
         tools/slovnik.py image/jazyky/admin-en.js < radky       (slovník skriptů administrace, funkce T())
Existující klíče přeskočí; položky shodné s češtinou nezapisuje.
"""
import json
import sys


def php(text: str) -> str:
    return "'" + text.replace('\\', '\\\\').replace("'", "\\'") + "'"


def js(text: str) -> str:
    return json.dumps(text, ensure_ascii=False)


cesta = sys.argv[1]
skript = cesta.endswith('.js')
obsah = open(cesta, encoding='utf-8').read()
nove = []
for radek in sys.stdin.read().splitlines():
    if '|' not in radek:
        continue
    cesky, preklad = radek.split('|', 1)
    klic = js(cesky) + ':' if skript else php(cesky) + ' =>'
    if cesky == preklad or klic in obsah:
        continue
    nove.append('\t' + js(cesky) + ': ' + js(preklad) if skript else '    ' + php(cesky) + ' => ' + php(preklad) + ',\n')
if skript and nove:
    # objekt window.MIROCMS_PREKLAD = { … }; – poslední položka nemá čárku, nové se připojí za ni
    konec = obsah.rindex('};')
    pred = obsah[:konec].rstrip()
    obsah = pred + (',' if not pred.endswith('{') else '') + '\n' + ',\n'.join(nove) + '\n' + obsah[konec:]
elif nove:
    konec = obsah.rindex('];')
    obsah = obsah[:konec] + ''.join(nove) + obsah[konec:]
open(cesta, 'w', encoding='utf-8').write(obsah)
print(f'{cesta}: doplněno {len(nove)} položek')
