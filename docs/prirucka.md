# Příručka Kalety

Návod pro toho, kdo web spravuje: od instalace přes stavitel stránek až po napojení na AI.
[English version](guide.md)

## 1. Instalace a první kroky

1. Nahrajte soubory na hosting (PHP 8.4+, MySQL 8 / MariaDB 10.6+), založte prázdnou databázi a otevřete `/install.php`.
2. Ve formuláři vyberte **ukázkový web**: *Firemní web*, *Řemeslo a služby*, nebo *Poradenství a agentura*. Každý
   přinese vlastní vzhled a stránky Úvod, O nás, Služby a Kontakt z hotových sekcí s ukázkovými texty.
3. Po přihlášení vás na **Přehledu** provedou **První kroky**: vzhled webu, údaje o firmě, stránky, první novinka a pošta.

Administrace je na adrese `/admin.php`. Role: **správce** smí všechno, **editor** spravuje obsah, **autor novinek** píše
jen své novinky a vydat je smí jen s právem vydávat.

## 2. Stavitel stránek

Stavitel otevřete v **Stránky → Stavitel** (nebo odkazem „Upravit zde“ na webu). Uprostřed je **plátno – skutečná stránka
webu**, vlevo panel **Přidat / Struktura**, vpravo vlastnosti vybraného prvku.

- **Vkládání:** klepněte na prvek nebo hotovou sekci v panelu Přidat, nebo je **přetáhněte na plátno** – modrá čára
  ukáže, kam dopadnou (rámeček = dovnitř kontejneru). Po najetí na hotovou sekci uvidíte její náhled.
- **Přesun:** vybraný prvek má na plátně úchyt ⠿ – přetáhněte ho jinam. Přetahovat jde i ve Struktuře.
- **Text:** dvojklikem upravíte nadpis nebo text přímo na plátně; delší úpravy v panelu **Obsah**.
- **Styl:** panel **Styl** – rozložení, rozměry, mezery, typografie, pozadí. Nahoře přepínáte **Počítač / Tablet / Mobil**:
  hodnota pro menší obrazovku přepíše tu větší jen tam. Přepínač **Najetí myší** nastaví vzhled při najetí.
- **Třídy:** v záložce **Pokročilé** dáte prvku třídu (např. `karta`). Styl třídy platí pro všechny prvky s touto třídou
  na celém webu – ideální pro opakovaný vzhled.
- **Zkratky:** Ctrl+Z zpět, Ctrl+Shift+Z znovu, Ctrl+D duplikovat, Ctrl+C / Ctrl+V kopírovat a vložit (i mezi stránkami),
  Delete smazat, Esc vybrat nadřazený prvek.
- **Ukládání a publikování:** změny se průběžně ukládají jako **koncept** – návštěvníci vidí publikovanou verzi, dokud
  nestisknete **Publikovat**. **Zahodit změny** vrátí publikovanou podobu, **Verze** nabídne posledních 20 publikací.
  Když stránku mezitím upraví někdo jiný, editor nabídne načíst novější verzi, nebo ji přepsat. Před publikováním
  **Kontrola** upozorní na tlačítka bez odkazu, obrázky bez popisu a chybějící hlavní nadpis.
- **Prvky navíc:** ikona, galerie s prohlížečkou fotek, záložky, akordeon, karusel, mapa (načte se až po klepnutí),
  vyskakovací okno (otevře ho tlačítko s odkazem `#kotva-okna`) a drobečková navigace.

Stránku, která byla dosud jen textem, stavitel převede na stavbu sám. Zpět k textu ji vrátíte v nastavení stránky.

**Stránky** mají vlastní titulek a obrázek pro vyhledávače a sdílení i volbu noindex (Nastavení stránky → Vyhledávače
a sdílení). Smazaná stránka jde do **koše** a 30 dní ji jde obnovit; **Duplikovat** vytvoří skrytou kopii i se stavbou.

## 3. Vzhled webu

**Vzhled → Vzhled webu** mění celý web najednou:

- **Předvolby** – hotový vzhled jedním klikem (barvy, písma, velikosti), pak ho dolaďte.
- **Barvy** – hlavní, doplňková, text, pozadí, plocha. Odstíny se dopočítají samy. Blok **Čitelnost** hlídá kontrast
  podle WCAG; červeně označenou dvojici barev změňte.
- **Tmavý režim**, **písma**, **velikosti** (základní písmo na telefonu a monitoru, poměr nadpisů, šířka obsahu),
  **zaoblení rohů**, logo a ikona.

Vpravo je živý náhled úvodní stránky (počítač / telefon). Nic se neukládá, dokud nestisknete Uložit vzhled.

## 4. Části webu: záhlaví, patička, obálky

**Vzhled → Části webu.** Dokud část nepublikujete ze stavitele, kreslí ji šablona.

- **Záhlaví a patička** jsou na každé stránce. Prvky **Logo**, **Navigace** (na telefonu se schová za tlačítko) a
  **Údaje firmy** se plní samy.
- **Obálky** (detail novinky, výpis novinek, stránka 404) přidají sekce kolem obsahu, který skládá systém. Prvek
  **Obsah stránky** označuje místo, kam systém obsah vloží.
- **Varianty:** u záhlaví a patičky zvolte **Přidat variantu**, pojmenujte ji a zaškrtněte stránky. Varianta platí jen
  tam. Prázdná varianta část skryje – hodí se pro landing page.

**Vzhled → Menu** sestaví hlavní menu a menu v patičce: stránky, vlastní odkazy, novinky a skupiny, pod každou položkou
jedno podmenu. Pořadí měníte přetažením nebo šipkami. Dokud menu neuložíte, skládá se samo ze stránek „v navigaci“.

## 5. Komponenty

Blok, který používáte na víc místech (karta služby, kontaktní pruh), uložte jako **komponentu**: vyberte ho ve staviteli
a v hlavičce panelu klepněte na ikonu „Uložit jako komponentu“. Úpravy ve **Vzhled → Komponenty** se projeví všude.

Co se má u každého použití lišit, nastavte jako **vlastnosti** (např. Nadpis, Odkaz) a v komponentě je vložte značkou
`{{nadpis}}`. U použití pak v panelu Obsah vyplníte hodnoty; prázdné pole = výchozí hodnota.

## 6. Kolekce

**Obsah → Kolekce** jsou seznamy podobných věcí s vlastními poli – reference, tým, produkty, pobočky, ceník.

1. Založte kolekci a pole (krátký text, delší text, formátovaný text, obrázek, odkaz, číslo, datum).
2. Přidejte položky.
3. Ve staviteli vložte prvek **Výpis kolekce**. Jeho vnitřek je vzor jedné karty – do textů, obrázků a odkazů vložte
   značky `{{nazev}}`, `{{url}}` (stránka položky) nebo vlastní pole. Nápověda se značkami se ukáže v panelu Obsah.

Výpis umí **řazení** (i podle pole, např. ceny), **pevný filtr**, **tlačítka filtru** pro návštěvníky a **stránkování**.
Když u kolekce zapnete **stránky položek**, každá položka má adresu `/kolekce/polozka`. Vzhled té stránky navrhnete
ve staviteli přes **Šablona detailu**.

## 7. Formuláře a poptávky

Prvek **Formulář** (nebo hotová sekce *Poptávkový formulář*) vloží poptávkový formulář. V panelu Obsah nastavíte pole
(text, e-mail, telefon, výběr, přepínače, datum, číslo, souhlas), text tlačítka, poděkování nebo děkovnou stránku,
potvrzení odesílateli a e-mail pro upozornění. Proti spamu chrání skrytá pole a limit odeslání bez CAPTCHA a cookies.
Novou poptávku umí web poslat i do CRM nebo Make/Zapier (Nastavení → Základní → Webhook nové poptávky); měření
konverzí dostane událost `kaleta:odeslano` (a záznam do `dataLayer`).

Odeslané zprávy jsou v **Obsah → Poptávky**: stav (nová, přečtená, vyřízená), odpověď e-mailem, export do CSV.
Poptávky obsahují osobní údaje, proto se po nastaveném počtu měsíců (výchozí 24) samy mažou.

## 8. Údaje o firmě

**Nastavení → Firma:** obchodní firma, druh podniku, IČO, DIČ, adresa, telefon, **otevírací doba** (po řádcích, např.
„Po–Pá 8:00–17:00“, „So 9–12“, „Ne zavřeno“), odkaz na mapu a souřadnice. Prvek **Údaje firmy** je vypíše kdekoli
na webu a vyhledávače je dostanou jako strukturovaná data – Google tak ukáže otevírací dobu i adresu.

## 9. Novinky, SEO a jazyky

**Novinky** jsou blog firmy: kategorie, štítky, plánované vydání, koš, historie verzí. SEO se stará samo (mapa webu,
kanonické adresy, strukturovaná data, `llms.txt` pro AI vyhledávače). Další jazykové verze (`/en/…`) zapnete v
**Rozšíření → Jazykové verze webu** a jazyky vyberete v Nastavení.

## 10. AI asistent a Claude

**Asistent** zapnete v **Rozšíření**. Vyberte poskytovatele (Anthropic Claude, OpenAI, Google Gemini, Mistral), vložte
klíč API a model. Asistent jen navrhuje. Text odchází k poskytovateli, jen když klepnete na jeho tlačítko.

- Ve staviteli: **✨ Vytvořit sekci s AI** (popište, co má sekce obsahovat) a **Přepsat s AI** u textu prvku (kratší,
  delší, formálněji, přátelštěji, opravit chyby). Výsledek zkontrolujte, fakta doplňte sami. Ctrl+Z změnu vrátí.
- V novinkách: titulky, perex, SEO popis, štítky, korektura, popisy obrázků a překlad do jiného jazyka webu.

**Napojení na Claude (MCP)** zapnete v Rozšířeních. V **Můj účet** si vytvořte přístupový token. V Claude Code pak spusťte

```bash
claude mcp add --transport http kaleta https://vas-web.cz/mcp --header "Authorization: Bearer <token>"
```

V aplikaci Claude přidejte vlastní konektor s adresou `https://vas-web.cz/mcp` a stejnou hlavičkou. Claude pak s právy
vašeho účtu staví stránky a sekce, upravuje záhlaví, patičku, komponenty, kolekce a vzhled a píše novinky. Všechno
vzniká jako **koncept** a publikuje se jen na váš pokyn.

## 11. Přechod z WordPressu

**Správa → Import a export → WordPress:** nahrajte export z WordPressu (Nástroje → Export, soubor XML). Import převede
příspěvky na novinky, stránky volitelně **rovnou do stavitele**, stáhne obrázky do Médií a založí přesměrování ze starých
adres. Import jde spustit znovu – co už převedl, přeskočí.

## 12. Zálohy, aktualizace, export

**Nastavení → Zálohy a aktualizace:** automatické zálohy (i mimo server přes FTP nebo S3) a podepsané aktualizace.
**Import a export → Export webu** vytvoří balík s obsahem (stránky, novinky, kolekce, části webu, třídy) a médii pro
přenos jinam. Poptávky a účty se neexportují.
