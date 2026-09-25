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
Podpis platí, když sedí na **kterýkoli** z nich (`Core\Podpis`). Soubor je součást balíčku, takže ho každá aktualizace přepíše –
tím se nové klíče dostanou do instalací a odvolané z nich zmizí.

Podepisuje se řetězec `verze|sha256 balíčku|bezne nebo bezpecnostni` a zvlášť seznam souborů jádra (`system/soubory.json`).
Příznak bezpečnostního vydání je tedy krytý podpisem: kdo by ovládl jen web s manifestem, nemůže běžné vydání prohlásit
za bezpečnostní a vynutit jeho automatickou instalaci.

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
- [ ] Ověřit na nginx a cron (`/ulohy`) na skutečném hostingu.

## Založení záložního klíče (jednou, před prvním veřejným vydáním)

```bash
php tools/vydani.php --novy-klic=zalozni
```

1. Soubor `tools/klice/zalozni.key` uložte do správce hesel a druhou kopii mimo počítač. Pak ho z disku smažte.
2. `system/aktualizace.pub` (přibyl řádek) commitněte. Instalace záložní klíč poznají od prvního vydání, které ho obsahuje –
   proto to udělejte **před** prvním veřejným vydáním, ať ho mají všechny.

## Běžné vydání

Všechno, co jde na GitHub a s vydáním do instalací, je **anglicky**: commit, tag, poznámky k vydání (`gh release edit --notes`)
i popis změn `--zmena` (správci ho vidí v administraci u nabídky aktualizace).


1. V `system/bootstrap.php` zvyšte `KALETA_VERSION`, změnu commitněte, označte tagem `vX.Y.Z` a pushněte (workflow Vydání
   spustí testy a založí koncept vydání).
2. `php tools/vydani.php X.Y.Z --url=https://github.com/phprs-cms/kaletacms/releases/download/vX.Y.Z/kaleta-X.Y.Z.zip --zmena="…" [--bezpecnostni]`
3. `gh release upload vX.Y.Z dist/kaleta-X.Y.Z.zip dist/aktualizace.json` a koncept zveřejněte jako **latest**
   (`gh release edit vX.Y.Z --draft=false --latest`).
4. Víc nic: `https://kaletacms.com/aktualizace.json` je na webu projektu přesměrování (Kaleta → Přesměrování, 302) na
   `https://github.com/phprs-cms/kaletacms/releases/latest/download/aktualizace.json`, instalace si novou verzi najdou samy.
   Soubor `aktualizace.json` proto ve `www` webu projektu **nesmí ležet** – server by ho podal místo přesměrování.
5. Na kaletacms.com ověřte, že se aktualizace nabídne a nainstaluje.

`--bezpecnostni` používejte jen pro skutečné bezpečnostní opravy: taková vydání se instalují sama a správci dostanou e-mail.

Podepisujte **lokálně**, ne v GitHub Actions. V CI by klíčem mohl podepisovat každý, kdo smí měnit workflow, a bezpečnost
všech instalací by stála na zabezpečení jednoho účtu. CI sestavuje a testuje; podpis je jeden příkaz na počítači vydavatele.

## Plánovaná výměna provozního klíče

1. Starý `tools/klice/vydavatel.key` přesuňte do archivu (nemažte ho, dokud výměna neproběhne).
2. `php tools/vydani.php --novy-klic=provozni` – do `system/aktualizace.pub` přibude nový řádek. Starý řádek zatím ponechte.
3. Vydejte verzi podepsanou **starým** klíčem (dočasně ho vraťte na místo, nebo použijte `KALETA_KLIC`). Přinese instalacím nový klíč.
4. V dalším vydání, už podepsaném novým klíčem, starý řádek z `system/aktualizace.pub` odstraňte.

## Ztráta provozního klíče

1. Vyzvedněte záložní klíč a uložte ho jako `tools/klice/zalozni.key`.
2. `php tools/vydani.php --novy-klic=provozni`, ztracený klíč z `system/aktualizace.pub` odstraňte.
3. `php tools/vydani.php X.Y.Z --klic=zalozni --url=…` – vydání podepsané záložním klíčem přinese nový provozní.
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
| **Kanál aktualizací** (`tools/over-kanal.php`) | `aktualizace.json` na kaletacms.com není podepsaný naším klíčem, balíček neodpovídá otisku nebo nese cizí veřejný klíč – tedy podvržení nebo poškození toho, co si instalace stahují |
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
   `php tools/vydani.php 1.0.x --url=… --zmena="Bezpečnostní oprava: …" --bezpecnostni`
   Podpis je lokální; potom ZIP do GitHub Releases a `aktualizace.json` na web (viz Běžné vydání).
5. **Ověřit** na demu, že se záplata nainstalovala sama, a ručně pustit Denní kontrolu – musí projít kanál aktualizací.
6. **Zveřejnit oznámení** (advisory) s popisem, zasaženými verzemi a poděkováním nálezci.

Po vydání 1.0.0 se opravy dělají na `main` a přenášejí do větve `1.0` (`git cherry-pick`), ze které se vydávají verze 1.0.x;
nové funkce jdou jen do `main` a vyjdou jako 1.1.
