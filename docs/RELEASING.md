# Vydávání Kalety a podpisové klíče

Instalace Kalety přijmou aktualizaci jen tehdy, když ji podepsal vydavatel. Bezpečnostní vydání se při výchozím nastavení
instalují **sama, bez kliknutí správce** – podpisový klíč je proto nejcitlivější věc v celém projektu. Tenhle dokument říká,
kde klíče leží, jak se vydává a co dělat, když se klíč ztratí nebo unikne.

## Dva klíče

| klíč | soubor (soukromý) | kde má ležet | k čemu |
| --- | --- | --- | --- |
| **provozní** | `tools/klice/vydavatel.key` | počítač vydavatele + šifrovaná záloha | podepisuje každé vydání |
| **záložní** | `tools/klice/zalozni.key` | **jen offline** – správce hesel a druhá kopie na papíře nebo USB mimo počítač | nepoužívá se; slouží k výměně provozního klíče |

Veřejné protějšky jsou v `system/aktualizace.pub` (na řádek jeden, za klíčem volitelný popis, řádky s `#` jsou poznámky).
Podpis platí, když sedí na **kterýkoli** z nich (`Core\Signature`). Soubor je součást balíčku, takže ho každá aktualizace přepíše –
tím se nové klíče dostanou do instalací a odvolané z nich zmizí.

Podepisuje se řetězec `verze|sha256 balíčku|bezne nebo bezpecnostni` a zvlášť seznam souborů jádra (`system/soubory.json`).
Příznak bezpečnostního vydání je tedy krytý podpisem: kdo by ovládl jen web s manifestem, nemůže běžné vydání prohlásit
za bezpečnostní a vynutit jeho automatickou instalaci. Od 3.9 nese manifest navíc **podpis v2** přes všechna pole, podle
kterých web jedná – i kanál a `min_php` (viz [Podpis manifestu v2](#podpis-manifestu-v2-39)).

Soukromé klíče **nikdy** nepatří do gitu (hlídá `.gitignore`), do balíčku ani do cloudové synchronizace. Pozor: pokud složka
projektu leží v synchronizované složce (iCloud Drive, Dropbox), synchronizuje se i `tools/klice/` – přesuňte klíče jinam
a do `tools/klice/` dejte jen symbolický odkaz, nebo klíč předávejte proměnnou prostředí `KALETA_KLIC`.

## Před prvním veřejným vydáním (1.0)

- [x] Repozitář `phprs-cms/kaletacms` na GitHubu, veřejný od vydání 1.0 (rozhodnutí vlastníka).
- [x] Git identita KaletaCMS <info@kaletacms.com>, historie přepsaná, první push.
- [x] Provozní i záložní klíč vygenerované (24. 9. 2026, id 3c68e740 a 1b2b7bea) v `~/.kaleta-klice` mimo iCloud (`tools/klice` je na ně odkaz), `system/aktualizace.pub` commitnutý. **Záložní klíč uložit do správce hesel a z disku smazat.**
- [ ] Web kaletacms.com běží a vystavuje `aktualizace.json`; v `.github/workflows/denni-kontrola.yml` zapnuté kontroly (`if: false` pryč).
- [x] V `SECURITY.md` kontakt info@kaletacms.com, na GitHubu zapnuté soukromé hlášení (Security → Private vulnerability reporting).
- [x] V `README.md` (anglicky) a `README.cs.md` odstraněná věta „před vydáním 1.0 … nepoužívejte na produkčních webech“.
- [x] Kandidát `1.0.0-rc1` nainstalovaný na kaletacms.com (Blueboard, Apache, PHP 8.4): instalace, HTTPS, 2FA a přihlašovací klíče ověřené.
- [ ] Ověřit na nginx a cron (`/tasks`, starší `/ulohy`) na skutečném hostingu.

## Založení záložního klíče (jednou, před prvním veřejným vydáním)

```bash
php tools/release.php --novy-klic=zalozni
```

1. Soubor `tools/klice/zalozni.key` uložte do správce hesel a druhou kopii mimo počítač. Pak ho z disku smažte.
2. `system/aktualizace.pub` (přibyl řádek) commitněte. Instalace záložní klíč poznají od prvního vydání, které ho obsahuje –
   proto to udělejte **před** prvním veřejným vydáním, ať ho mají všechny.

## Běžné vydání

Všechno, co jde na GitHub a s vydáním do instalací, je **anglicky**: commit, tag, poznámky k vydání (`gh release edit --notes`)
i popis změn `--zmena` (správci ho vidí v administraci u nabídky aktualizace).


0. Pusťte `tools/test.sh`, `tools/test-english.sh`, `tools/test-browser.sh` a `tools/test-migrations.sh` (aktualizace databáze z v1.0.0; workflow Vydání ji pouští taky).
1. V `system/bootstrap.php` zvyšte `KALETA_VERSION`, změnu commitněte, označte tagem `vX.Y.Z` a pushněte (workflow Vydání
   spustí testy a založí koncept vydání).
2. `php tools/release.php X.Y.Z --url=https://github.com/phprs-cms/kaletacms/releases/download/vX.Y.Z/kaleta-X.Y.Z.zip --zmena="…" [--bezpecnostni]`
   Pak podepsaný balíček vyzkoušejte jako aktualizaci předchozího vydání: `PACKAGE=dist/kaleta-X.Y.Z.zip tools/test-update.sh`
   (a třeba `FROM=v1.2.0` pro starší) – starý web ověří skutečný podpis, nainstaluje a projde administraci i web.
3. `gh release upload vX.Y.Z dist/kaleta-X.Y.Z.zip dist/aktualizace.json` a koncept zveřejněte jako **latest**
   (`gh release edit vX.Y.Z --draft=false --latest`).
4. Víc nic: `https://kaletacms.com/aktualizace.json` je na webu projektu přesměrování (Kaleta → Přesměrování, 302) na
   `https://github.com/phprs-cms/kaletacms/releases/latest/download/aktualizace.json`, instalace si novou verzi najdou samy.
   Soubor `aktualizace.json` proto ve `www` webu projektu **nesmí ležet** – server by ho podal místo přesměrování.
5. Na kaletacms.com ověřte, že se aktualizace nabídne a nainstaluje.
6. Na kaletacms.com přidejte vydání do kolekce **Releases** (stránka `/changelog`), anglicky: přes Clauda
   `uloz_polozku_kolekce` s `kolekce: "releases"`, název „Kaleta X.Y.Z“ a poli `version`, `released` („25 September 2026“),
   `kind` (First / Feature / Fix / Security release), `summary`, `changes` (HTML seznam), `notes` (odkaz na vydání na GitHubu)
   a `number` (X·10000 + Y·100 + Z, podle něj se řadí), `zobrazit: true`.

`--bezpecnostni` používejte jen pro skutečné bezpečnostní opravy: taková vydání se instalují sama a správci dostanou e-mail.

**Nejstarší podporované PHP** je `KALETA_MIN_PHP` v `system/bootstrap.php` (od 3.7 **8.3**). `tools/release.php` ho zapíše
do manifestu jako `min_php`; web na starším PHP takové vydání nenabídne (v administraci i ve Stavu systému napíše, jaké PHP
potřebuje), nenainstaluje ho na pozadí ani na pokyn konzole webů a ruční instalaci odmítne. Manifest bez `min_php` platí
jako vydání pro PHP 8.4 (tak tomu bylo do 3.6). Zvýšit minimum znamená změnit spolu `KALETA_MIN_PHP`, `phpVersion`
v `phpstan.neon.dist`, matici PHP v `.github/workflows/kontrola.yml` a `denni-kontrola.yml` a řádky požadavků v README
a v příručce (souhlas hlídá `tools/unit-tests.php`) – a předem ověřit, že weby, které se mají aktualizovat, na novém PHP běží.
Na PHP 8.3 dodává HTML5 DOM z PHP 8.4 (`Dom\HTMLDocument`) složka `system/compat` (vlastní parser `Kaleta\Compat\Html5Parser`);
na 8.4+ se nenačítá.
PHP 8.3 s JIT po funkcích na horké čítače (`opcache.jit = 1235`, výchozí v setup-php) po čase spadne na stránkách builderu
(chyba enginu 8.3, bisekce vede na `Builder/Build.php` jako celek); výchozí `tracing`, `function` i 8.4 projdou. CI proto
spouští 8.3 s `opcache.jit=tracing` a `Health::riskyJit()` hlásí ten režim ve Stavu systému.

Podepisujte **lokálně**, ne v GitHub Actions. V CI by klíčem mohl podepisovat každý, kdo smí měnit workflow, a bezpečnost
všech instalací by stála na zabezpečení jednoho účtu. CI sestavuje a testuje; podpis je jeden příkaz na počítači vydavatele.

## Kanály vydání: Latest a Stable (3.8, rozhodnutí D3)

Web si v *Nastavení → Zálohy a aktualizace* volí kanál (nastavení `update_channel`):

- **Latest** (`latest`, výchozí pro každý existující i nový web) čte `aktualizace.json` – každý týden nová minor verze.
- **Stable** (`stable`) čte druhý podepsaný manifest **vedle něj**, `aktualizace-stable.json`: stejný klíč, stejný formát,
  navíc `"kanal": "stable"`. Vydavatel ho mění jen dvakrát: **bezpečnostní záplata stabilní řady** (patch z udržovací větve)
  a **vědomé povýšení** na novější minor (zhruba jednou za měsíc, až se minor osvědčí na Latest).

Jak to funguje (`Core\Updater`):

- Adresa stabilního manifestu je vždy „dvojče“ adresy `aktualizace.json` (`Updater::stableUrl`): pro web projektu
  `https://kaletacms.com/aktualizace-stable.json`, pro vlastní zdroj (zrcadlo) soubor vedle jeho `aktualizace.json`.
  Vlastní zdroj s jiným názvem souboru stabilní dvojče nemá – web se jím řídí dál, ať zvolí jakýkoli kanál (`custom`).
  Nastavení `update_url` se přepnutím kanálu nikdy nemění.
- Výběr verze je jedna čistá funkce `Updater::choose()` (jednotkové testy): nabídne se **jen novější** verze, než web
  běží; vydání pro novější PHP se nenabídne (`min_php`, 3.7). Na kanálu Stable musí manifest říkat `"kanal": "stable"` –
  když na stabilní adrese omylem leží manifest Latest (špatné přesměrování), web nenabídne ani nenainstaluje nic a řekne proč.
  Pole `kanal` a `min_php` jsou od 3.9 podepsaná (podpis v2, N38-3 a N37-4): kdo ovládne stabilní adresu, už nemůže pravé
  vydání Latest přeznačit na Stable. Weby do 3.8 čtou jen podpis v1 a tohle riziko pro ně zůstává (přijaté v 3.8).
- **Web napřed před stabilní řadou** (přepnul z Latest, když běžel na novější minor): žádný downgrade. Nic se nenabízí,
  dokud stabilní řada jeho verzi nepředežene; administrace, Stav systému (řádek *Kanál aktualizací*, varování) i MCP
  `get_health` (`update.ahead_of_stable`) to říkají. Bezpečnostní opravy k němu do té doby dorazí jen na Latest.
- Automatická instalace (úloha `updates`) se nemění: sama instaluje jen vydání s příznakem `bezpecnostni` (krytým podpisem)
  nebo verzi povolenou konzolí webů. Povýšení stabilního kanálu bez příznaku tedy správce instaluje tlačítkem; web, který
  povýšení vynechal, dostane novou řadu s její první bezpečnostní záplatou.
- Kanál ukazují: administrace (karty Nejnovější/Stabilní), Stav systému, měsíční zpráva (řádek *Verze Kalety*), MCP
  `site_info` (`update_channel`) a `get_health` (`update`), heartbeat konzole webů (`update_channel`).
- `tools/check-channel.php` (i denní kontrola) ověří oba manifesty: podpis v1 i v2, balíček, klíče, `"kanal"` a že Stable
  není napřed před Latest. Dokud stabilní manifest neexistuje (404), kontroluje se jen Latest.

### Kde stabilní manifest leží

Stejně jako Latest: `https://kaletacms.com/aktualizace-stable.json` je na webu projektu **přesměrování 302** (Kaleta →
Přesměrování) na `https://github.com/phprs-cms/kaletacms/releases/download/stable-channel/aktualizace-stable.json`.
`stable-channel` je jedno pevné vydání na GitHubu (pre-release, nikdy „latest“), které nese **jen** tenhle soubor;
zveřejnění = `gh release upload stable-channel dist/aktualizace-stable.json --clobber`. Adresa `releases/latest/download/`
použít nejde: „latest“ se každý týden posune na nové vydání, které soubor nemá.

Tag `stable-channel` musí ukazovat na **první commit repozitáře**, ne na `main`: `tools/test-update.sh` i workflow berou
předchozí vydání z `git describe --tags`, a tag na novějším commitu by ho přebil. Workflow Vydání reaguje jen na `v*`.

### Bezpečnostní záplata stabilní řady (např. 3.8 → 3.8.1)

Každá stabilní řada má udržovací větev `stable-X.Y` z tagu, který je ve stabilním manifestu.

1. `git switch stable-3.8`, oprava přes `git cherry-pick` z `main`, `KALETA_VERSION = '3.8.1'` (další volný patch řady),
   commit, tag `v3.8.1`, push větve i tagu. Testy jako u běžného vydání.
2. `php tools/release.php 3.8.1 --channel=stable --bezpecnostni --url=https://github.com/phprs-cms/kaletacms/releases/download/v3.8.1/kaleta-3.8.1.zip --zmena="Security fix: …"`
   – zapíše `dist/aktualizace-stable.json` (Latest manifest se nemění). **Udržovací větev starší než 3.9** (`stable-3.8`)
   má `release.php` bez podpisu v2: manifest pak podepište znovu nástrojem z `main` – `git switch main` a
   `php tools/release.php 3.8.1 --channel=stable --bezpecnostni --package=dist/kaleta-3.8.1.zip --url=… --zmena="…"`
   (`dist/` git nesleduje, balíček zůstane; nic se nestaví, jen se podepíše manifest v1 + v2). Bez toho by denní kontrola
   selhala a weby od 3.9 na kanálu Stable by manifest odmítly. Balíček vyzkoušejte jako aktualizaci:
   `PACKAGE=dist/kaleta-3.8.1.zip MANIFEST=dist/aktualizace-stable.json FROM=v3.8.0 tools/test-update.sh`.
3. `gh release upload v3.8.1 dist/kaleta-3.8.1.zip` a vydání zveřejněte **bez** označení latest:
   `gh release edit v3.8.1 --draft=false --latest=false`. **Pozor:** kdyby se v3.8.1 stalo „latest“, přesměrování
   `aktualizace.json` (`releases/latest/download/…`) by vedlo na vydání bez manifestu a všechny weby na Latest by hlásily chybu.
4. `gh release upload stable-channel dist/aktualizace-stable.json --clobber`, pak `php tools/check-channel.php`.
5. Stejnou opravu vydejte i na Latest jako běžnou bezpečnostní záplatu z `main` (např. 3.10.1 `--bezpecnostni`).
   Je-li stabilní řada zrovna stejná minor jako Latest, stačí jeden balíček: vydat ho na Latest a pak ho podepsat i pro
   Stable (`--channel=stable --package=dist/kaleta-X.Y.Z.zip`, viz povýšení).

### Povýšení stabilního kanálu na novější minor

1. Vyberte minor, který běží na Latest aspoň dva týdny bez regresí, a jeho poslední patch (např. `v3.10.2`).
2. Balíček ze skutečného vydání (stejný soubor = stejný otisk): `gh release download v3.10.2 -p kaleta-3.10.2.zip -D dist`.
3. `php tools/release.php 3.10.2 --channel=stable --package=dist/kaleta-3.10.2.zip --url=https://github.com/phprs-cms/kaletacms/releases/download/v3.10.2/kaleta-3.10.2.zip --zmena="Stable channel moves to 3.10: …"`
   – nic nestaví, jen podepíše manifest pro existující balíček. Odmítne verzi, která stabilní kanál sama nezná (před 3.8).
4. `gh release upload stable-channel dist/aktualizace-stable.json --clobber`, `php tools/check-channel.php`.
5. `git branch stable-3.10 v3.10.2 && git push origin stable-3.10`; větev `stable-3.8` už nedostává opravy.

### První stabilní manifest

Doporučená první stabilní řada je **3.8**: je to první vydání, které kanál samo zná – web na Stable, který by nainstaloval
starší vydání (3.7), by kanál zapomněl a četl zase Latest (`release.php` takové vydání na Stable odmítne). Zveřejněte ho,
až 3.8 poběží na Latest bez regresí a vyjde 3.9:

1. Jednou: `gh release create stable-channel --prerelease --latest=false --target "$(git rev-list --max-parents=0 HEAD)" --title "Stable update channel" --notes "Holds aktualizace-stable.json, the manifest of the Stable update channel. Not a release."`
2. `gh release download v3.8.N -p kaleta-3.8.N.zip -D dist` (poslední patch 3.8) a
   `php tools/release.php 3.8.N --channel=stable --package=dist/kaleta-3.8.N.zip --url=https://github.com/phprs-cms/kaletacms/releases/download/v3.8.N/kaleta-3.8.N.zip --zmena="First release of the Stable channel"`
3. `gh release upload stable-channel dist/aktualizace-stable.json --clobber`
4. Na kaletacms.com: Přesměrování `/aktualizace-stable.json` → 302 →
   `https://github.com/phprs-cms/kaletacms/releases/download/stable-channel/aktualizace-stable.json`
5. `php tools/check-channel.php` – musí vypsat obě verze; `git branch stable-3.8 v3.8.N && git push origin stable-3.8`.
6. Na zkušebním webu přepněte kanál na Stabilní a ověřte nabídku (a že web na 3.9 hlásí „napřed před stabilní řadou“).

## Podpis manifestu v2 (3.9)

Manifest (`aktualizace.json` i `aktualizace-stable.json`) nese od 3.9 **dva podpisy** stejným klíčem:

- `podpis` (v1, beze změny od 1.0): řetězec `verze|sha256|bezne nebo bezpecnostni`. Instalované verze 1.0–3.8 čtou jen
  tenhle, proto zůstává v každém manifestu, dokud takové weby existují.
- `podpis2` (v2): přes všechna pole, podle kterých web jedná – `verze`, `vydano`, `url`, `sha256`, `min_php`,
  `bezpecnostni`, `kanal`, `klic` a `zmeny` (`Core\Signature::MANIFEST_FIELDS`). Podepisuje se hlavička
  `kaleta-manifest-v2\n` a pak pro každé pole v tomhle pořadí řádek `jméno=<délka v bajtech>:<hodnota>\n`; hodnota je ta,
  kterou web čte (chybějící text = prázdný, příznak `true`/`false`, otisk malými písmeny, každá změna seznamu
  `<délka>:<text>`). Díky délkám nemůže žádná hodnota „přetéct“ do jiného pole. Platí jen klíč uvedený v `klic`.
  Starší weby pole `podpis2` neznají a přeskočí ho.

Web od 3.9 manifest ověří hned po stažení, dřív než cokoli nabídne (`Core\Updater::verified`):

1. **Manifest s `podpis2`**: podpis musí platit. Neplatí-li, web manifest odmítne – nic nenabídne, nic nenainstaluje
   a v administraci napíše proč.
2. **Manifest bez `podpis2` s verzí 3.9.0 a novější**: odmítne ho – podpis v2 někdo odstranil, aby ho obešel.
3. **Manifest bez `podpis2` se starší verzí** (vydání před 3.9, staré zrcadlo): čte se jako dřív podle v1, jen jeho
   nepodepsaný `kanal` se zahodí – na kanálu Stable takový manifest nic nenabídne. Nepodepsané `min_php` rozhoduje jen
   o tom, co se nabídne; instalace před zápisem prvního souboru ověří PHP, které potřebuje **sám balíček**
   (`KALETA_MIN_PHP` v jeho `system/bootstrap.php`, krytý podepsaným otiskem). Web od 3.9 starší verzi stejně nedostane
   (nabízí se jen novější), takže v praxi jedná jen podle manifestů s platným v2.
4. Podpis v1 se při instalaci ověřuje dál jako dřív (v obou případech).

Co to znamená pro vydavatele:

- `tools/release.php` od 3.9 zapisuje oba podpisy sám (`Core\Signature::signManifest`). **Běžné vydání se nemění.**
- Podepsaný manifest **nikdy neupravujte ručně** – ani `url`, ani řádek změn. Podpis v2 by přestal platit a weby od 3.9
  by ho odmítly. Spusťte `release.php` znovu.
- Stabilní manifest a každý manifest verze 3.9.0+ musí mít platný v2 – hlídá to `tools/check-channel.php` (i denní
  kontrola). Záplatu stabilní řady z větve starší než 3.9 proto podepište nástrojem z `main` (viz
  [Bezpečnostní záplata stabilní řady](#bezpečnostní-záplata-stabilní-řady-např-38--381)).
- Vlastní zdroj aktualizací (zrcadlo) musí manifest podávat beze změny: `url` je podepsaná, takže zrcadlo, které ji
  přepíše na svůj server, weby od 3.9 neaktualizuje (balíček musí ležet na adrese z podepsaného manifestu).
- `PACKAGE=dist/kaleta-X.Y.Z.zip tools/test-update.sh` nejdřív ověří oba podpisy manifestu. Test pak přesměruje `url`
  na svůj místní kanál; vydání, které samo kontroluje v2 (od 3.9), proto dostane v2 podepsaný znovu dočasným klíčem
  (v1 zůstává vydavatele a web ho ověří). `TRUST_KEY=<veřejný klíč>` zkouší balíček podepsaný dočasným klíčem (suchý
  běh `release.php` s `KALETA_KLIC`).

## Plánovaná výměna provozního klíče

1. Starý `tools/klice/vydavatel.key` přesuňte do archivu (nemažte ho, dokud výměna neproběhne).
2. `php tools/release.php --novy-klic=provozni` – do `system/aktualizace.pub` přibude nový řádek. Starý řádek zatím ponechte.
3. Vydejte verzi podepsanou **starým** klíčem (dočasně ho vraťte na místo, nebo použijte `KALETA_KLIC`). Přinese instalacím nový klíč.
4. V dalším vydání, už podepsaném novým klíčem, starý řádek z `system/aktualizace.pub` odstraňte.

## Ztráta provozního klíče

1. Vyzvedněte záložní klíč a uložte ho jako `tools/klice/zalozni.key`.
2. `php tools/release.php --novy-klic=provozni`, ztracený klíč z `system/aktualizace.pub` odstraňte.
3. `php tools/release.php X.Y.Z --klic=zalozni --url=…` – vydání podepsané záložním klíčem přinese nový provozní.
4. Záložní klíč vraťte offline. Další vydání už podepisuje nový provozní klíč.

## Únik provozního klíče (nebo jen podezření)

Postup je stejný jako při ztrátě, jen **hned** a vydání označte `--bezpecnostni`, aby se instalovalo samo. Dokud instalace
aktualizaci nepřijmou, kompromitovanému klíči věří – útočník ale k útoku potřebuje ještě ovládnout `kaletacms.com/aktualizace.json`.
Proto zároveň změňte přístupy k hostingu webu a ke GitHubu a uživatele informujte.

Unikne-li **záložní** klíč, založte nový (`--novy-klic=zalozni` po přesunutí starého souboru), starý řádek odstraňte a vydejte
verzi podepsanou provozním klíčem.

## Když přijdete o oba klíče

Automatická cesta pak neexistuje. Instalace jde aktualizovat ručně (nahrát soubory přes FTP), a první ručně nahraná verze
přinese nový `system/aktualizace.pub`. Proto záložní klíč zálohujte na dvou nezávislých místech.

## Denní kontrola a bezpečnostní záplaty (1.0.x)

Každou noc běží `.github/workflows/denni-kontrola.yml`. **Nic nevydává ani nepodepisuje** – podpisový klíč zůstává mimo GitHub –
jen včas řekne, že je potřeba jednat:

| kontrola | co odhalí |
| --- | --- |
| **Kanál aktualizací** (`tools/check-channel.php`) | `aktualizace.json` na kaletacms.com není podepsaný naším klíčem, balíček neodpovídá otisku nebo nese cizí veřejný klíč – tedy podvržení nebo poškození toho, co si instalace stahují |
| **Testy** na podporovaných verzích PHP a na připravované (`nightly`, smí selhat) | změnu v PHP, která systém rozbije, dřív než dorazí na hostingy |
| **Statická analýza** (Semgrep s denně čerstvými pravidly, Gitleaks) | nově popsané zranitelné vzory v našem kódu; nálezy jdou do *Security → Code scanning*, kam vidí jen správci – záznam běhu je záměrně tichý, protože je u veřejného repozitáře veřejný |
| **Web a demo zvenku** | chybějící bezpečnostní hlavičky, otevřený `config.php`, `system/`, `storage/`, `.git/` |

Když něco selže, založí se (nebo oživí) úkol „Denní kontrola selhala“ s odkazem na běh a GitHub pošle e-mail.
Spustit ji jde i ručně: *Actions → Denní kontrola → Run workflow*.

### Od nálezu k záplatě

1. **Posoudit** (do 3 pracovních dnů, viz `SECURITY.md`): je to skutečná zranitelnost? Koho se týká? Dá se zneužít bez přihlášení?
   Planý nález v Code scanning zavřít s důvodem, ať se nevrací.
2. **Neřešit veřejně.** Založit *Security → Advisories → New draft* (soukromé); oprava vzniká v soukromé větvi, kterou k oznámení GitHub nabídne.
   Hlášení od lidí chodí stejnou cestou (*Report a vulnerability*).
3. **Opravit a otestovat** – `tools/test.sh`, k chybě přidat test, který by ji příště chytil.
4. **Vydat záplatu** z udržované řady: číslo `1.0.x`, a pokud jde o bezpečnost, s příznakem, který ji instalacím nainstaluje samu:
   `php tools/release.php 1.0.x --url=… --zmena="Bezpečnostní oprava: …" --bezpecnostni`
   Podpis je lokální; potom ZIP do GitHub Releases a `aktualizace.json` na web (viz Běžné vydání).
5. **Ověřit** na demu, že se záplata nainstalovala sama, a ručně pustit Denní kontrolu – musí projít kanál aktualizací.
6. **Zveřejnit oznámení** (advisory) s popisem, zasaženými verzemi a poděkováním nálezci.

Po vydání 1.0.0 se opravy dělají na `main` a přenášejí do větve `1.0` (`git cherry-pick`), ze které se vydávají verze 1.0.x;
nové funkce jdou jen do `main` a vyjdou jako 1.1. Od 3.8 dostává bezpečnostní záplaty nejnovější minor (Latest, z `main`)
a stabilní řada (Stable, z větve `stable-X.Y`) – postup v části *Kanály vydání*.
