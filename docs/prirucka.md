# Příručka Kalety

Návod pro toho, kdo web spravuje: od instalace přes builder stránek až po napojení na AI.
[English version](guide.md)

## 1. Instalace a první kroky

1. Nahrajte soubory na hosting (PHP 8.3+, MySQL 8 / MariaDB 10.6+), založte prázdnou databázi a otevřete `/install.php`.
2. Ve formuláři vyberte **startovací web**: *Firemní web*, *Řemeslo a služby*, nebo *Poradenství a agentura*. Každý
   přinese vlastní styl a stránky Úvod, O nás, Služby a Kontakt z hotových sekcí s ukázkovými texty.
3. Vyberte, **co chcete mít zapnuté**, jedním ze tří profilů: *Web* (stránky, novinky, statistika, přesměrování
   a napojení na Clauda), *Web s poptávkami* (totéž s formuláři a schránkou Poptávky) nebo *Kancelář a prodej* (poptávky
   se zákazníky, obchody a nabídkami). **Vyberu si sám** ukáže celý seznam k zaškrtnutí po jednom. Zákazníci a Obchody
   zůstanou vypnuté, pokud nezvolíte Kancelář a prodej; Rezervace a Oznamovatelé začínají vypnuté vždy. Každou funkci jde
   později zapnout a vypnout ve **Funkcích**; vypnutím se nic nesmaže.
4. Instalace založí i skrytou kostru **Zásad ochrany osobních údajů** v jazyce webu s odkazem v patičce, v cookie liště a u souhlasu ve formuláři. Doplňte údaje v hranatých závorkách a stránku zveřejněte. Nová stránka ze šablony **Zásady ochrany osobních údajů** (Stránky → Nová stránka) se řídí tím, co má web zapnuté – poptávky, newsletter, statistika, měření a marketingové kódy, mapy – a doplní údaje o firmě. Vždy je to šablona ke kontrole, ne právní rada.
5. Instalátor je česky, anglicky, německy a španělsky (řídí se jazykem prohlížeče). Na konci ukáže adresu pro napojení Clauda a první zadání na vyzkoušení.
6. Po přihlášení vás na **Přehledu** provedou **První kroky**: vzhled webu, údaje o firmě, stránky, zásady a pošta.

Administrace je na adrese `/admin.php`.

**Přehled.** První obrazovka po přihlášení. Nahoře jsou **Nová stránka**, **Napsat novinku** a **Zobrazit web**, pod nimi
upozornění, která potřebují člověka (třeba adresy, které návštěvníci nenašli), každé s tlačítkem k nápravě, a co čeká na
vaši kontrolu. **První kroky** provedou nový web nastavením, dokud není hotový každý krok – kancelář má vlastní seznam –
a dlaždice počítají, co v které sekci čeká. Dole jsou návštěvy za posledních 14 dní, poslední poptávky a co se naposledy
upravovalo; každý řádek otevře svůj záznam.

**Najít cokoli (Ctrl K, na Macu ⌘K).** Paleta najde každý záznam, který smíte otevřít – stránky, novinky, položky kolekcí,
média, poptávky, zákazníky, obchody, nabídky, své úkoly, žádosti o práci, interní soubory – i sekce, záložky nastavení
a akce. Slova můžete psát v libovolném pořadí a bez diakritiky („dvorak“ najde Dvořáka); výsledky přijdou ve skupinách
po pěti, přesný název první, a **+ N dalších** otevře seznam sekce se stejným hledáním. Prázdná paleta ukáže, co jste
v tomto přihlášení naposledy otevřeli. Stejně hledají i **seznamy**: slovo z textu najde novinku, jméno z formuláře
poptávku, číslice telefonu („777-123“) zákazníka; seznam ukáže **Nalezeno: N**, hledaná slova zvýrazní a hledání drží
na každé straně. Claude hledá stejné záznamy nástrojem `search`, s vašimi právy.

**Funkce.** Správa → Funkce zapíná a vypíná vestavěné části Kalety – Novinky, Formuláře a poptávky, Zákazníky, Obchody,
Nabídky, Rezervace, Newsletter, Statistiku, jazykové verze, AI asistenta, napojení na Clauda a další. Vypnutá funkce zmizí
z menu, její prvky se v builderu přestanou nabízet a její adresy na webu odpovídají „nenalezeno“, ale nic se nesmaže:
zapněte ji znovu a všechno je zpátky. Některé funkce potřebují jinou (Obchody potřebují Zákazníky) a zapnou ji s sebou.

**Uživatelé a role.** Role: **správce** smí všechno, **editor** spravuje obsah, **člen týmu** pracuje v sekcích, které
dostal (novinky jen své, vydané jen s právem vydávat). Lidi přidáte v **Uživatelích** – každý dostane e-mail k nastavení
hesla a správce umí účet zablokovat nebo mu resetovat druhý krok přihlášení. V **Uživatelé → Role** si založíte **vlastní
role** – pojmenovanou sadu sekcí (třeba „Obchodník“ jen s Poptávkami); změna role se přenese na všechny její členy. Začít
můžete čtyřmi hotovými rolemi: **Klient** (stránky, novinky, kolekce, poptávky, zákazníci a obchody, statistika, údaje
o firmě a oblast vzhledu – nastavení a uživatelé zůstávají vám), **Autor textů** (novinky, které vydá někdo jiný), **HR**
(žádosti o místo a pracovní pozice v Kolekcích, nic ze zákazníků) a **Kancelář / prodej** (poptávky, zákazníci, obchody,
rezervace, nabídky a odběratelé).

**Nápověda na každé obrazovce.** Vedle nadpisu každé části administrace otevře **Jak s tím pracovat** její článek
v návodu (v jazyce administrace, pokud ho návod má); v builderu je článek v Nápovědě (**?**). Administrace je česky,
anglicky, německy a španělsky – každý uživatel si vybere v **Můj účet**. Nástroje Clauda (MCP) mluví vždy anglicky.

**Stavíte web pro klienta?** V **Nastavení → Agentura** vyplňte své jméno, web, e-mail,
telefon a logo: přihlašovací obrazovka a patička administrace klientovi ukážou, koho se zeptat. Před předáním ukáže
kontrolní seznam **Předání klientovi** v **Auditu webu**, kolik je hotovo (hotovo x z y), a každý řádek vede tam, kde se
nastavuje – e-mail webu, SMTP server, přes který už prošla zpráva (pošlete zkušební e-mail), kopie záloh mimo server,
údaje o firmě, indexování, ikona webu, měření bez cookie lišty, bezpečnostní kontakt, správci bez dvoufázového přihlášení
nebo passkey, nepoužívané přístupy, vlastní účet klienta (role Klient nebo role s oblastí Vzhled), měsíční zpráva pro
klienta, uložené mantinely v Nastavení Clauda, váš kontakt, rozhodnutí v Zápisníku a úlohy na pozadí.

**Nemůžete se přihlásit?** Zapomenuté heslo: **Zapomenuté heslo?** na přihlašovací stránce (mění jen heslo). Ztracený
telefon nebo klíč: záložní kód po hesle, nebo účet resetuje jiný správce v **Uživatelích**. Jediný správce bez klíče
a záložních kódů doplní do `config.php` řádek `define('KALETA_PASSKEY_ONLY_OFF', true);`: všechna pravidla „jen klíčem“
se vypnou a správce, jehož jediným druhým krokem je klíč, se přihlásí samotným heslem. Administrace na to upozorňuje na
každé stránce, dokud řádek nezmizí – v **Mém účtu** si znovu zaregistrujte klíč a řádek smažte.

## 2. Builder stránek

Builder otevřete v **Stránky → Builder** (nebo odkazem „Upravit zde“ na webu). Uprostřed je **plátno – skutečná stránka
webu**, vlevo panel **Přidat / Struktura**, vpravo vlastnosti vybraného prvku.

- **Vkládání:** klepněte na prvek nebo hotovou sekci v panelu Přidat, nebo je **přetáhněte na plátno** – modrá čára
  ukáže, kam dopadnou (rámeček = dovnitř kontejneru). Po najetí na hotovou sekci uvidíte její náhled. **Knihovna sekcí**
  v panelu Přidat má dvě části: **Hotové sekce** (hero, výhody, služby, FAQ…) a **Moje sekce** (co jste si uložili
  z libovolné stránky přes „Uložit do mých sekcí“). Každé vložení je samostatná kopie; blok, který má být všude stejný,
  je **komponenta** (Vzhled → Komponenty).
- **Přesun:** vybraný prvek má na plátně úchyt ⠿ – přetáhněte ho jinam. Přetahovat jde i ve Struktuře. Na tabletu
  a telefonu klepněte na **Přesunout** (čtyři šipky) a pak na místo, kam prvek patří.
- **Text:** dvojklikem upravíte nadpis nebo text přímo na plátně; delší úpravy v panelu **Obsah**.
- **Styl:** panel **Styl** – rozložení, rozměry, mezery, typografie, pozadí. Nahoře přepínáte **Počítač / Tablet / Mobil**:
  hodnota pro menší obrazovku přepíše tu větší jen tam. Přepínače **Najetí** a **Stisk** nastaví vzhled při najetí myší
  (i fokusu z klávesnice) a při stisknutí – i zvlášť pro tablet a mobil. **Typografický styl** (Nadpis sekce, Perex…)
  nastaví písmo jedním výběrem; stín a rámeček poskládáte tužkou ✎ vedle pole. U mřížky je **editor mřížky**: počet
  sloupců a řádků a pojmenované oblasti. **Kopírovat styl / Vložit styl** přenese vzhled na jiný prvek.
- **Třídy:** v záložce **Pokročilé** dáte prvku třídu (např. `karta`). Styl třídy platí pro všechny prvky s touto třídou
  na celém webu – ideální pro opakovaný vzhled.
- **Zkratky:** Ctrl+Z zpět, Ctrl+Shift+Z znovu, Ctrl+D duplikovat, Ctrl+C / Ctrl+V kopírovat a vložit (i mezi stránkami),
  Delete smazat, Esc vybrat nadřazený prvek, **?** nápověda. V nápovědě spustíte i **prohlídku editoru**.
- **Náhled:** vedle přepínače zařízení zvolíte **široký monitor (1920 px)** nebo přiblížení 50–100 %.
- **Podmínky zobrazení** (Pokročilé): prvek jen od–do data (akční banner) nebo jen pro nepřihlášené či přihlášené.
- **Ukládání a publikování:** změny se průběžně ukládají jako **koncept** – návštěvníci vidí publikovanou verzi, dokud
  nestisknete **Publikovat**. **Zahodit změny** vrátí publikovanou podobu, **Verze** nabídne posledních 20 publikací.
  **Sdílet** vytvoří odkaz na koncept pro kolegu nebo klienta: otevře se bez přihlášení, platí 1–7 dní a vyhledávače ho neindexují.
  Když stránku mezitím upraví někdo jiný, editor nabídne načíst novější verzi, nebo ji přepsat. Před publikováním
  **Kontrola** upozorní na tlačítka bez odkazu, obrázky bez popisu, chybějící hlavní nadpis a slabý kontrast textu.
- **Prvky navíc:** ikona, galerie s prohlížečkou fotek, záložky, akordeon, karusel, mapa (načte se až po klepnutí),
  drobečková navigace, **počítadlo**, **ukazatele průběhu**, **hodnocení** hvězdičkami, **odpočet**, **sociální sítě**,
  **vyhledávání**, **tlačítko nahoru** a **odběr novinek**. Prvek **vyhledávání** je pole s tlačítkem nebo **ikona**,
  která pole otevře (Vzhled) – hodí se do záhlaví. Sekce umí **video na pozadí**, video **plakát**, navigace
  **mega menu** a obrázek pozadí **paralaxu**.

Stránku, která byla dosud jen textem, builder převede na stavbu sám. Zpět k textu ji vrátíte v nastavení stránky.

**Stránky** mají vlastní titulek a obrázek pro vyhledávače a sdílení i volbu noindex (Nastavení stránky → Vyhledávače
a sdílení). Smazaná stránka jde do **koše** a 30 dní ji jde obnovit; **Duplikovat** vytvoří skrytou kopii i se stavbou.

## 3. Vzhled webu

**Vzhled → Vzhled webu** mění celý web najednou. Tři pojmy, všude ve stejném smyslu: **startovací web** je ukázkový
obsah se stylem (vybíráte ho při instalaci) a **styl** je hotová sada barev, písem, velikostí a zaoblení. Vlastní PHP
šablony Kaleta od verze 1.6 nepoužívá – vzhled určuje jen Vzhled webu, sdílené třídy a builder.

Stránka má záložky:

- **Styl** – styl vyberete jedním klikem a v dalších záložkách ho dolaďte. Styl, ze kterého web vychází, je označený
  jako *aktuální*. Vzhled podle vaší značky nechte navrhnout Clauda – barvy a písma uloží do design systému.
- **Barvy** – hlavní, doplňková, text, pozadí, plocha. Odstíny se dopočítají samy. Blok **Čitelnost** hlídá kontrast
  podle WCAG; červeně označenou dvojici barev změňte.
- **Tmavý režim** – vypnutý (vždy světlý), podle zařízení návštěvníka, nebo vždy tmavý – s vlastní barvou textu, pozadí
  a plochy. **Přepínač pro návštěvníky** přidá do záhlaví vedle jazyků volbu světlý / tmavý / podle zařízení; volba se
  pamatuje v prohlížeči návštěvníka.
- **Písmo a velikosti** – písmo titulků a textu (i vlastní soubory WOFF2), základní písmo na telefonu a monitoru, poměr
  nadpisů, šířka obsahu a **typografické styly** (Hlavní titulek, Nadpis sekce, Perex…), které v builderu vyberete u prvku.
- **Tvary** – zaoblení tlačítek, karet, obrázků a polí formulářů.
- **Logo a ikona**.
- **Import a export** – stažení a načtení vzhledu ve formátu W3C Design Tokens (DTCG) pro Figmu nebo Tokens Studio.

Vpravo je živý náhled úvodní stránky (počítač / telefon). Nic se neukládá, dokud nestisknete Uložit vzhled.

**Koncept vzhledu.** Změna design systému, sdílené třídy nebo menu se k návštěvníkům nedostane hned: jde do jednoho
**konceptu vzhledu** – ze Vzhledu webu, z builderu, z editoru menu i od Clauda. Dokud koncept existuje, každá obrazovka
administrace ukazuje lištu s tím, co mění, a třemi tlačítky:

- **Náhled celého webu** – podepsaný odkaz, který ukáže web s konceptem vzhledu a s koncepty všech stránek a částí webu,
  pro vás i pro kolegu (platí den). Náhled ukončíte lištou nahoře.
- **Publikovat vzhled** – všechno najednou. Předchozí vzhled zůstane ve **Vzhledu webu → Import a export → Dřívější
  vzhledy** (posledních 20); **Vrátit tento vzhled** ho znovu načte do konceptu.
- **Zahodit** – platí dál publikovaný vzhled.

Úplně nová sdílená třída platí hned – nic publikovaného nemění a nové stránky ji potřebují.

## 4. Části webu: záhlaví, patička, obálky, pop-up okna

**Vzhled → Části webu.** Dokud část nepublikujete z builderu, kreslí ji vestavěná výchozí podoba.

- **Začít ze šablony:** pět záhlaví (logo vlevo a menu vpravo, logo uprostřed, s kontaktní lištou, s vyhledáváním,
  minimální), čtyři
  patičky (sloupce, kompaktní, s tiráží, s výzvou k akci) a jednoduché nebo rozšířené obálky. Šablona je čistá kostra –
  barvy, písma a mezery si bere z design systému. Uloží se do konceptu části, takže web se změní až po publikování;
  zkontrolujte ji na celém webu přes **Náhled celého webu**.

- **Záhlaví a patička** jsou na každé stránce. Prvky **Logo**, **Navigace** (na telefonu se schová za tlačítko) a
  **Údaje firmy** se plní samy.
- **Obálky** (detail novinky, výpis novinek, stránka 404) přidají sekce kolem obsahu, který skládá systém. Prvek
  **Obsah stránky** označuje místo, kam systém obsah vloží.
- **Varianty:** u záhlaví a patičky zvolte **Přidat variantu**, pojmenujte ji a zaškrtněte stránky. Varianta platí jen
  tam. Prázdná varianta část skryje – hodí se pro landing page. V poli **Také na** může varianta místo jednotlivých stránek
  dostat druh obsahu: detail novinky, výpis novinek (s kategoriemi, štítky a hledáním), stránky položek kolekce nebo každou
  stránku pod nadřazenou stránkou. Zaškrtnutá stránka dostane svou variantu vždy; jinak vyhraje první varianta podle názvu,
  jejíž volba sedí. Claude dělá totéž nástrojem `save_part_variant` (`news_items`, `news_list`, `collections`, `under_pages`).

**Vzhled → Menu** sestaví hlavní menu a menu v patičce: stránky, vlastní odkazy, novinky a skupiny, pod každou položkou
jedno podmenu. Pořadí měníte přetažením nebo šipkami. Dokud menu neuložíte, skládá se samo ze stránek „v navigaci“.
Uložené menu jde do konceptu vzhledu stejně jako změna design systému, takže ho návštěvníci uvidí po zveřejnění vzhledu;
na webu s jazykovými verzemi má každá jazyková verze vlastní hlavní menu i menu v patičce, které vyberete nahoře v editoru.

**Vzhled → Pop-up okna** jsou okna přes stránku – přihlášení k newsletteru, materiál ke stažení za e-mail, oznamovací
lišta, sleva nebo pozvánka na akci. Začněte od hotového vzoru, obsah poskládejte v builderu a publikujte; v **Nastavení**
pak určete, kdy a kde se okno ukáže, a zapněte ho.

- **Typ:** okno uprostřed, vysouvací panel v rohu, lišta nahoře nebo dole, nebo celá obrazovka.
- **Spouštěč:** po zadaném počtu sekund, po odrolování části stránky, když se návštěvník chystá odejít, po nečinnosti,
  po zadaném počtu stránek v návštěvě – nebo jen odkazem či tlačítkem na `#popup-<adresa>` (ten okno otevře kdykoli).
- **Kde:** celý web, nebo vybrané stránky, stránky položek kolekcí a novinky; jazyková verze, období, jen telefon nebo
  počítač, návštěvníci z kampaně (`utm_*`) nebo z určitého webu.
- **Četnost:** jednou za návštěvu, jednou za N dní, dokud okno návštěvník nezavře nebo neodešle formulář v něm, nebo
  pokaždé. Pamatuje si to prohlížeč návštěvníka – bez cookies.
- **Výsledky:** zobrazení, zavření a konverze (odeslaný formulář nebo přihlášení k odběru v okně), počítané bez cookies.
  Formulář v okně jde do Poptávek jako každý jiný.

Každé okno zavře Esc i křížek, fokus se přesune do okna a po zavření se vrátí, animace respektují omezený pohyb a okno
počká, až návštěvník vyřídí lištu cookies.

## 5. Komponenty

Blok, který používáte na víc místech (karta služby, kontaktní pruh), uložte jako **komponentu**: vyberte ho v builderu
a v hlavičce panelu klepněte na ikonu „Uložit jako komponentu“. Úpravy ve **Vzhled → Komponenty** se projeví všude.

Co se má u každého použití lišit, nastavte jako **vlastnosti** (např. Nadpis, Odkaz) a v komponentě je vložte značkou
`{{nadpis}}`. U použití pak v panelu Obsah vyplníte hodnoty; prázdné pole = výchozí hodnota.

## 6. Kolekce

**Obsah → Kolekce** jsou seznamy podobných věcí s vlastními poli – reference, tým, produkty, pobočky, ceník.

1. Založte kolekci a pole (krátký text, delší text, formátovaný text, obrázek, odkaz, číslo, datum).
2. Přidejte položky.
3. V builderu vložte prvek **Výpis kolekce**. Jeho vnitřek je vzor jedné karty – do textů, obrázků a odkazů vložte
   značky `{{nazev}}`, `{{url}}` (stránka položky) nebo vlastní pole. Nápověda se značkami se ukáže v panelu Obsah.

**Rovnou z menu (3.18.3):** každá kolekce má navíc vlastní položku v levém menu, hned pod **Kolekcemi** – přímý odkaz
na její položky (produkty, volná místa…), zvýrazněný na každé obrazovce té kolekce. Kolekci, která tam být nemá,
vynecháte odškrtnutím *Zobrazit v menu administrace* v jejím nastavení. Menu ukáže nejvýš 5 kolekcí (podle názvu)
a pak *Další…*; ostatní zůstanou pod Kolekcemi a rychlé hledání (Ctrl K) najde každou kolekci podle názvu. Položky vidí jen ti, kdo
smějí do Kolekcí. Claude to přepíná parametrem `admin_menu` u `create_collection` a `update_collection`;
`list_collections` ho u každé kolekce vrací.

**Ikona kolekce (3.19):** v nastavení kolekce vyberte **Ikonu** z vestavěné sady (dům, auto, lidé, kalendář…) – ukáže se
u položky kolekce v levém menu a v seznamu kolekcí; *Seznam (výchozí)* nechá obecnou ikonu seznamu. Návštěvníci ji
nevidí. Claude ji nastaví parametrem `icon` u `create_collection` a `update_collection` (anglický název, např.
`"icon": "home"`; `""` vrátí ikonu seznamu) a `list_collections` ji vrací.

Výpis umí **řazení** (i podle pole, např. ceny), **pevný filtr**, **tlačítka filtru** pro návštěvníky a **stránkování**.
Když u kolekce zapnete **stránky položek**, každá položka má adresu `/kolekce/polozka`. Vzhled té stránky navrhnete
v builderu přes **Šablona detailu**.

Na webu s **jazykovými verzemi** dejte každé položce jazyk. Překlad položky má stejnou adresu, např. `/compare/wordpress`
a `/de/compare/wordpress`; přepínač jazyků a `hreflang` je propojí. Každý další jazyk má vlastní **Šablonu detailu (DE)**.
Začíná jako kopie výchozí šablony a dokud ji nepublikujete, položky v tom jazyce používají výchozí. Drobečky vedou na
překlad stránky s adresou kolekce (u `/compare` třeba `/de/vergleich`).

**Stránky položek jsou plnohodnotné stránky (1.9).** Každá položka má jako stránka **Vyhledávače a sdílení**: vlastní
titulek pro vyhledávače, popis, obrázek pro sdílení a *Skrýt před vyhledávači (noindex)* – taková položka zůstane
dostupná, ale vynechá se z vyhledávačů, mapy webu, `llms.txt` i hledání na webu. Bez nich se použije název, začátek
prvního delšího textu a první obrázek. Skrytá položka se umí **sama zveřejnit** v nastavený čas a každé uložení nechá
předchozí verzi v **Historii položky** (posledních 20), jedním kliknutím ji vrátíte.

**Strukturovaná data pro vyhledávače:** v nastavení kolekce řekněte, co položky jsou – služba, osoba, produkt, událost
nebo otázka s odpovědí – a která pole plní vlastnosti (cena, pracovní pozice, začátek…). Stránky položek pak ponesou
data schema.org vedle údajů o firmě. Nabídka potřebuje pole s cenou a měnu.

**Mnoho položek najednou (3.7):** **Kolekce → kolekce → Import z CSV nebo JSON** vezme soubor CSV (z Excelu nebo jiného
systému, oddělený čárkou, středníkem nebo tabulátorem; český Excel ve Windows-1250 se převede) nebo seznam JSON, až
5 000 řádků. Sloupce pojmenované jako pole (jeho klíčem nebo popiskem) nebo jako *name*, *slug*, *language*, *order* se
spárují samy; párování můžete změnit a náhled u každého řádku řekne, jestli se přidá, změní nebo odmítne a proč. Položka
se najde podle adresy, nebo podle adresy vytvořené z názvu, takže opakovaný import stejného souboru položky aktualizuje,
místo aby je přidal podruhé; prázdná buňka hodnotu nechá, jak je. Nové položky přijdou skryté, pokud nezaškrtnete
*Zobrazit nové položky na webu hned*. Sloupec obrázku nebo souboru s adresami `https://` se po uložení položek stáhne do
Médií po několika kusech a stránka pokračuje sama. Claude dělá totéž nástrojem `save_collection_items` – až 200 položek
na volání s pravidly jedné položky, `dry_run` ukáže, co by se změnilo, a `media` stáhne obrázky podle adresy (napojení
jen na koncepty uloží jen skryté položky).

**Kategorie (3.7).** **Kategorie** na obrazovce položek rozdělí kolekci do skupin s vlastními stránkami: kategorie a její
podkategorie (dvě úrovně), každá na `/kolekce/kategorie` a `/kolekce/kategorie/podkategorie`. Kategorie má název, adresu,
popis, obrázek, vlastní titulek a popis pro vyhledávače, pořadí a přepínač *zveřejněná na webu*; na webu s jazykovými
verzemi má každý jazyk vlastní název, adresu a texty, strom, pořadí a obrázek jsou společné. U každé položky zaškrtněte
kategorie (položka smí být ve více) – *Přidat položku* vedle kategorie ji zaškrtne za vás. Stránka kategorie ukáže
drobečky, popis, podkategorie jako karty a položky kategorie i jejích podkategorií, 12 na stránku (`?page=2`, každá
stránka s vlastní kanonickou adresou); stránky jsou v mapě webu s `hreflang` na překlady a nesou schema.org
`CollectionPage` a `BreadcrumbList`. Adresa nikdy není zároveň kategorií a položkou téže kolekce: uložení jedné odmítne
adresu druhé (adresa položky vytvořená z názvu dostane číslo) a kdyby se staré importované údaje přesto střetly, stránku
si nechá položka. Podkategorie zadaná v první úrovni (`/kolekce/podkategorie`) přesměruje na svou adresu, takže staré
odkazy na kategorie z WordPressu nejlépe namapujete přesměrováním podle vzoru z 3.6.

Stránku kategorie navrhnete v builderu přes **Šablonu stránky kategorie** (na obrazovce Kategorie; každý jazyk může mít
vlastní). Doplní `{{nazev}}`, `{{popis}}`, `{{obrazek}}`, `{{pocet}}` (viditelné položky včetně podkategorií)
a `{{nadrazena}}` / `{{nadrazena_url}}` (nadřazená kategorie). Zbytek udělají dvě nastavení **Výpisu kolekce**: *Výpis* –
položky nebo kategorie – a *Kategorie* – adresa, nebo `*` pro právě zobrazenou stránku kategorie. S `*` ukáže výpis
položek položky té kategorie (i stránky s `?page=`), výpis kategorií její podkategorie; na stránce položky znamená `*`
první kategorii položky, což dá výpis „další z této kategorie“. Jinde ukáže výpis kategorií s prázdnou *Kategorií* ty
nejvyšší – přehled katalogu na libovolné stránce.

**Předchozí / další položka (3.7).** Prvek tohoto jména odkáže na sousední položky na stránce položky – třeba *Předchozí
projekt* a *Další projekt* pod referencí. Řídí se pořadím v administraci (pořadí, pak název) nebo datem (předchozí =
starší), drží se kategorie položky, když nějakou má, umí ukázat názvy a náhledy (první obrázkové pole, nebo jiné podle
klíče) a má vlastní popisky. Je to navigační oblast s odkazy `rel="prev"` a `rel="next"`; mimo stránku položky neukáže nic.

Přes napojení na Clauda je totéž `list_collection_categories`, `save_collection_category` a `delete_collection_category`,
`categories` (adresy) u `save_collection_item`, `category` u `list_collection_items` a `category_template: true` u nástrojů
stavby. Napojení jen na koncepty uloží jen skryté kategorie.

## 7. Formuláře a poptávky

Prvek **Formulář** (nebo hotová sekce *Poptávkový formulář*) vloží poptávkový formulář. V panelu Obsah nastavíte pole
(text, e-mail, telefon, výběr, přepínače, více zaškrtávacích možností, datum, číslo, souhlas a skrytá hodnota – např.
produkt, kterého se formulář týká, odešle se s každou poptávkou, ale návštěvník ji nevidí), text tlačítka, poděkování nebo děkovnou stránku,
potvrzení odesílateli a e-mail pro upozornění. Proti spamu chrání skrytá pole a limit odeslání bez CAPTCHA a cookies.
Když spam přesto prochází, přidá **Nastavení → Soukromí a cookies → Kontrola spamu ve formulářích** navíc hCaptcha, Google
reCAPTCHA v3 nebo Cloudflare Turnstile (2.6): skript poskytovatele se načte jen na stránkách s formulářem, odpověď se ověří
na serveru, tajný klíč neopustí administraci a jednotlivý formulář jde z kontroly vyjmout v jeho nastavení.
Novou poptávku umí web poslat i do CRM nebo Make/Zapier (Nastavení → Webhooky → Webhook nové poptávky); měření
konverzí dostane událost `kaleta:odeslano` (a záznam do `dataLayer`).

**Google Tag Manager** (Nastavení → Měření, 2.6): zadejte ID kontejneru (`GTM-…`). Režim souhlasu je vestavěný – s vestavěnou
cookie lištou se kontejner spustí, až návštěvník povolí analytické nebo marketingové cookies. Kaleta posílá do datové vrstvy
události konverzí: `generate_lead` (odeslaný formulář), `sign_up` (newsletter), `popup_conversion`, `click_phone`,
`click_email` a `file_download`. Na záložce je ke stažení šablona kontejneru s těmito spouštěči a značkami pro GA4 a Google Ads.

**Nastavení → Webhooky** drží obě adresy webhooků (nová poptávka, vydaná novinka). Volání odchází až po odeslání stránky,
takže pomalý příjemce návštěvníka nikdy nezdrží, a každé je podepsané: hlavičky `X-Kaleta-Timestamp` a
`X-Kaleta-Signature` (`sha256=` HMAC-SHA256 z `časová značka.tělo` s tajným klíčem z této záložky) příjemci ověří, že
volání přišlo z vašeho webu. **Protokol doručení** ukazuje každé volání; nepovedené se zkusí znovu po 1, 5 a 30 minutách
a po 2 a 12 hodinách a vzdané jde jedním kliknutím poslat znovu. **Poslat zkušební volání** ověří spojení. Adresy webhooků
ani tajný klíč nejsou přes napojení na Clauda dostupné.

**Poptávky.** Odeslané zprávy jsou v Pracoviště → Poptávky: stav (nová, přečtená, vyřízená), odpověď e-mailem, export do CSV. Je-li formulář na
stránce, na kterou vede reklama nebo newsletter, poptávka ukáže i **kampaň** z adresy (`utm_source`, `utm_medium`, `utm_campaign`…) –
v administraci, v e-mailu s upozorněním, v CSV i ve webhooku, bez cookies.
Poptávky obsahují osobní údaje, proto se po nastaveném počtu měsíců (výchozí 24) samy mažou. Všechny lhůty uchování –
poptávky, žádosti o práci, zákazníci, nabídky, rezervace, oznámení, záznamy souhlasů – se nastavují na jednom místě,
**Nastavení → Soukromí a cookies → Jak dlouho se data uchovávají**; kratší lhůtu je nejdřív potřeba potvrdit.

**Odpovídání (3.49).** Odpověď odchází z webu s vaším e-mailem pro odpověď (Reply-To) a s vaším podpisem. **Uložené
odpovědi** (Poptávky → Uložené odpovědi, správci a editoři) jsou texty vaší firmy, záložka pro každou jazykovou verzi webu:
ve formuláři odpovědi jednu vyberete – nejdřív se nabízejí ty v jazyce poptávky – a doplní se `{{name}}`, `{{topic}}`
a `{{company}}`. Odpověď i e-mail nabídky mohou nést **přílohy**: nejvýš pět souborů, dohromady do 5 MB – interní soubory,
které smíte otevřít (soubory připojené k zákazníkovi poptávky nebo k nabídce se nabídnou nezaškrtnuté), nebo soubory z počítače.
Odejdou hned a nikde se neukládají; Claude nikdy nic nepřikládá. Dlouhé seznamy – zákazníci, firmy, obchody, produkty,
soubory – se vybírají napsáním několika písmen; bez JavaScriptu zůstanou obyčejným seznamem.

**Soubory u záznamu (3.49).** Na stránce zákazníka, firmy, obchodu nebo nabídky připojí **Připojit soubor** interní soubor –
ceník k obchodu, smlouvu k zákazníkovi. Každý vidí jen soubory ze složek, které jsou pro něj; stránka souboru ukáže, kde je
připojený. Smazání nebo anonymizace záznamu odstraní odkazy, nikdy soubor.

**Žádosti o referenci (3.51).** U vyřízené poptávky vytvoří **Požádat zákazníka o referenci** osobní odkaz na 30 dní;
co zákazník napíše, přijde jako skrytý koncept do Referencí se souhlasem, který dal. E-mailem web odkaz pošle jen
zákazníkovi – jeho zákaznický záznam má vyhraný obchod nebo nabídku, kterou zákazník přijal na její stránce – jednou na
poptávku a jen tehdy, když **Údaje firmy** obsahují název firmy a poštovní adresu, protože e-mail uvádí odesílatele.
Každý e-mail nese odkaz pro odmítnutí: „Nepište mi“ zapíše adresu do registru adres, na které nepíšeme, a ukončí její
odběr novinek. Ten odkaz je jen v e-mailu zákazníka: odkaz, který vám dá obrazovka, odkaz, který předáte sami, ani odkaz
žádosti z doby před 3.51 tohle nedokáže. Když poštovní server neodpoví, e-mail počká ve frontě pošty, která to zkouší zhruba
půl dne – obrazovka to řekne a mezitím se počítá jako ten jeden e-mail poptávky. Když neprojde vůbec, přestane se počítat a
můžete požádat znovu. Ve všech ostatních případech obrazovka (i Claude) řekne proč a dá vám odkaz, který předáte sami.

**Odkud poptávky přišly.** Zapněte **Nastavení → Cookies → Pamatovat si, odkud poptávky přišly** a každá poptávka i přihláška
k odběru uloží i **první stránku návštěvy**, její kampaň a web, ze kterého návštěvník přišel – i když je formulář na jiné
stránce. Pamatuje se jen pro danou záložku prohlížeče a jen u návštěvníků, kteří v cookie liště povolí marketing (zapnutím
se tato volba do lišty přidá). **Statistiky** pak ukážou, které stránky, kampaně, první stránky a weby přinášejí poptávky
a přihlášky, s konverzním poměrem, vedle návštěv, zařízení a konverzí pop-up oken; stejný přehled čte Claude nástrojem
`get_stats`. Prohlížeč, který posílá signál Global Privacy Control, platí za „jen nezbytné“, aniž by se lišta ptala.

Prvek **Vložení** ukáže rezervační kalendář, formulář nebo přehrávač z Calendly, Google Kalendáře, Google Forms,
Microsoft Forms, Tally, Typeformu, Airtable, Spotify nebo SoundCloudu – vložte adresu, kterou sdílíte, a zvolte výšku.
Načte se až po kliknutí, takže stránka do té doby službě nic nepošle, a vedle je vždy odkaz na službu. Správce může také
přidat **kód do hlavičky jedné stránky** (Nastavení stránky → Vyhledávače a sdílení), např. konverzní značku vstupní
stránky.

**Odběratelé.** Funkce Newsletter přidá prvek **Odběr novinek**: návštěvník zadá e-mail a potvrdí ho odkazem (double
opt-in). Potvrzené adresy najdete v **Obsah → Newsletter → Odběratelé** a vyexportujete je do CSV i s odkazem na odhlášení
pro váš rozesílací nástroj.

Nebo v **Rozšíření → Newsletter** napojte mailingovou službu, kterou už používáte: Brevo, MailerLite, Mailchimp, Ecomail,
SmartEmailing nebo jakoukoli jinou přes webhook (Make, Zapier, n8n). Zadejte klíč API a seznam; každý potvrzený odběratel
se pak přidá do seznamu a odhlášený se z něj odebere. Přenos běží na pozadí a nepovedené pokusy se opakují; v
**Odběratelích** vidíte stav každé adresy a stávající odběratele pošlete do služby jedním tlačítkem.

**Obsah → Newsletter** pošle potvrzeným odběratelům novinky přímo z webu. E-mailový builder tu není: jedna šablona
se řídí vaším design systémem – barvami, písmy, zaoblením a logem – a obsahuje předmět, text náhledu, úvod, nejnovější
(nebo vybrané) novinky, nepovinné tlačítko a údaje o firmě s odkazem na odhlášení. Uložte koncept, prohlédněte si náhled,
pošlete si zkoušku a pak newsletter odešlete hned nebo v nastavený čas (odeslání vyžaduje právo vydávat). Nejdřív je
potřeba nastavit dvě věci a obrazovka vám řekne, když chybějí:

- **SMTP server** v **Nastavení → Pošta** – Brevo, Amazon SES, Mailgun nebo vlastní schránku; hromadná pošta přes
  funkci `mail()` hostingu by skončila ve spamu. **Newslettery: e-mailů za hodinu** nastavte podle limitu své služby;
- **cron**, který každých pár minut volá adresu úloh z **Nastavení → Stav systému**: e-maily odcházejí po dávkách při
  každém volání, takže se newsletter nezasekne ani na málo navštěvovaném webu.

Každý e-mail nese vlastní odkaz na odhlášení a odhlášení jedním klikem v poštovním programu. Nic nesleduje otevření;
odkazy na váš web nesou parametry `utm`, takže **Statistika** ukáže návštěvy, které newsletter přivedl. Seznam příjemců
se drží jen po dobu odesílání – potom zůstanou jen počty a data. Koncept newsletteru a zkoušku umí i Claude; odběratelům
ho pošle jen na váš pokyn.

**Odběratelé: jazyky a adresy, na které nepíšeme.** Každý odběratel si nese jazykovou verzi, ve které se přihlásil;
**Odběratelé** ukážou sloupec Jazyk, počet potvrzených a čekajících v každém jazyce a export ho obsahuje. Adresa, kterou
poštovní server během newsletteru, který ostatním došel, odmítl jako neexistující, je **nedoručitelná**: zůstává
přihlášená a přeskakuje se, dokud správce nestiskne **Zrušit nedoručitelnost**. Člověk, který u žádosti o referenci
stiskl „Nepište mi“, **odmítl**: nedojde mu žádný newsletter ani žádost a vrátí ho jen jeho vlastní nové potvrzené
přihlášení (potvrzovací e-mail na takovou adresu odejde nejvýš jednou týdně). Registr drží jednosměrný otisk, nikdy
adresu; výmaz osobních údajů ho ponechá, aby se člověku už nepsalo (3.51).

**Odesílání: pozastavení, brzda a co nese patička.** Rozeslaný newsletter jde pozastavit, obnovit nebo zrušit, sám se
pozastaví, když odesílání opakovaně selhává, a jeho patička uvádí odesílatele – název firmy a poštovní adresu z Údajů
firmy – a proč e-mail přišel (3.51).

- **Pozastavit, obnovit, zrušit.** Během odesílání nabízí stránka newsletteru **Pozastavit odesílání** – nic dalšího
  neodejde, ani z volání cronu, které už běží – a **Zrušit newsletter**; pozastavený nabízí **Obnovit odesílání**. Zrušený
  zbytek už nikdy nepošle. Newsletter, který zůstane pozastavený 7 dní, se zruší sám. Claude umí odesílání na vaši žádost
  pozastavit (bez potvrzení – zastavit je vždy bezpečné); obnovit a zrušit ho můžete jen vy.
- **Brzda.** Když u poštovního serveru selže 5 z posledních 50 e-mailů (od začátku nebo od posledního obnovení), web
  odesílání sám pozastaví a napíše autorovi newsletteru. Adresy, které server odmítne jako neexistující, brzdu nespustí,
  dokud se ostatním doručuje – starý seznam doběhne normálně. Pozastavení v relaci Clauda zůstane, i když relaci vrátíte zpět. Když neprošlo vůbec nic (server odmítá celý web), vrátí se
  e-maily z toho úseku do fronty a odejdou po obnovení, až po adresách, na které ještě nedošlo (běžný další pokus u
  zaneprázdněného serveru nebo greylistingu jde dál ve svůj čas). Nejdřív zkontrolujte **Nastavení → Pošta** a **Stav systému**.
- **Nedoručitelné adresy.** Adresu, kterou server odmítl jako neexistující (5.1.1 a příbuzné), web vzdá hned, a když
  newsletter někomu doručil, zapamatuje si ji: další newslettery ji přeskočí. Server, který odmítá všechny, tenhle seznam
  nikdy nenaplní.
- **Patička.** Každý newsletter uvádí odesílatele – i s IČO a zápisem v rejstříku z Údajů firmy, když jsou vyplněné –,
  proč přišel, a odkazuje na zásady ochrany osobních údajů (**Nastavení → Soukromí a cookies**, odkaz dané jazykové
  verze). Bez názvu firmy a poštovní adresy v **Nastavení → Údaje
  firmy** newsletter odeslat nejde – naplánovaný se vrátí mezi koncepty a jeho autor dostane e-mail. Obě věty jsou výchozí
  znění v **Nastavení → Pošta**, pro každý jazyk zvlášť; `{{reason}}` v úvodu tam zopakuje větu s důvodem. Znění si
  nechte zkontrolovat pro svou zemi.
- **Počty.** Když newsletter skončí, platí příjemci = odesláno + nedoručeno + přeskočeno (mezitím se odhlásili, jsou na
  seznamu adres, na které nepíšeme, nebo nedošlo na ně, protože byl zrušen). Odkazy nesou název kampaně, jen když newsletter
  může dojít aspoň 5 lidem (adresy ze seznamu, na které nepíšeme, se nepočítají). Formulář pro odeslání upozorní – ale nezastaví vás –, když odesílací doména nemá záznam SPF nebo DMARC
  nebo když odběratelé jdou také do napojené mailingové služby.

**Online rezervace.** Se zapnutými Rezervacemi si návštěvníci objednají termín na webu prvkem **Rezervace**: vyberou
službu, člověka a volný čas a dostanou potvrzení e-mailem s odkazem na zrušení; web jim před termínem připomene. Správce
nastaví **Služby** (délka, přestávka po ní, text ceny, kdo ji dělá, jestli každou rezervaci nejdřív potvrzujete), **Lidi**
s jejich týdenní pracovní dobou a volnem a **Nastavení** – jak daleko dopředu a jak nejdříve se smí objednat, lhůtu pro
zrušení a připomínku. Seznam ukazuje nadcházející rezervace po dnech; v detailu rezervaci potvrdíte, odmítnete nebo
navrhnete jiný čas, označíte jako hotovou nebo nedostavenou, nebo přidáte rezervaci za zákazníka, který zavolal. Rezervace
jsou osobní údaje: řídí se dobou uchování poptávek a zákazník je má na časové ose.

### Oddělení, nadřízení a nepřítomnost

Správce zakládá oddělení v **Uživatelé → Oddělení** (název, vedoucí, případně nadřazené oddělení) a každému účtu
vybere oddělení a nadřízeného. Výsledek vidí všichni v **Můj účet → Organizační struktura** – jména, pozice, pracovní
telefony a e-maily jen aktivních účtů, bez skriptů a připravené k tisku.

Nepřítomnost si každý zadává v **Můj účet** (první a poslední den, zástupce, důvod z pevného seznamu – nikdy zdravotní
údaje); správce může zadat kohokoli v Uživatelích. Pravidlo je vždy stejné:

- když je někdo **dnes** pryč, každé upozornění na to, za co odpovídá (přidělení, komentáře, připomínky úkolů, termíny
  kontroly, připomínky nabídek a přihlášek), dostane dál on **a** kopii jeho zástupce s poznámkou „Zastupujete …“.
  Zmínka zůstává jen jeho;
- zástupcem je zástupce z dané nepřítomnosti – aktivní účet, sám ne pryč, který danou věc smí otevřít – jinak nadřízený
  za stejných podmínek, jinak nikdo (jeden krok, nikdy řetěz);
- oddělení může v poli **Nové poptávky z formulářů** jmenovat formuláře: novou poptávku z takového formuláře vyřizuje
  vedoucí, a když je pryč, jeho zástupce. Dokud žádný formulář nejmenujete, nic se nepřiděluje;
- úkol tři dny po termínu se jednou ohlásí nadřízenému toho, kdo ho má;
- kdo odpovídá za existující záznam, se nikdy nemění – mění se jen, kdo se o něm dozví a kdo dostane nové poptávky.

Claude vidí oddělení, nadřízené a do kdy je kdo pryč (nikdy důvod) v `list_colleagues`.

**Nepřítomnost zavře i termíny rezervací (3.49).** Je-li rezervační osoba propojená s účtem (Rezervace → Lidé → Účet),
každá nepřítomnost toho účtu – dovolená, služební cesta, školení, volno i mimo kancelář – zavře její volné termíny na celé ty dny:
návštěvníci, prvek Rezervace, navržené termíny ani Claude je nenabídnou. Nic se nekopíruje, smazání nepřítomnosti termíny
hned vrátí. Rezervace a nabídnuté termíny v těch dnech se nikdy nepřesouvají ani neruší – hláška po uložení nepřítomnosti
řekne, kolik jich je, a rezervace má štítek *člověk je nepřítomen*. **Propojit podle e-mailu** v seznamu Lidé propojí každou
rezervační osobu, jejíž e-mail patří právě jednomu účtu.

## 8. Údaje o firmě

**Nastavení → Firma:** obchodní firma, druh podniku, IČO, DIČ, adresa, telefon, **otevírací doba** (po řádcích, např.
„Po–Pá 8:00–17:00“, „So 9–12“, „Ne zavřeno“), odkaz na mapu a souřadnice. Prvek **Údaje firmy** je vypíše kdekoli
na webu a vyhledávače je dostanou jako strukturovaná data – Google tak ukáže otevírací dobu i adresu.
Pro **tiráž** (právní informace o provozovateli, v Německu Impressum) vyplňte zápis v rejstříku a kdo firmu zastupuje
a založte stránku podle šablony **Tiráž** (Stránky → Nová stránka) – prvek Údaje firmy v režimu *Tiráž* vypíše všechny
vyplněné údaje o provozovateli.

**Fakta a tvrzení.** Údaje o firmě → **Fakta** drží čísla a věty, které web uvádí – rok založení firmy, počet projektů,
cenu od, záruku, oblast, kde působíte. Fakt napíšete jednou a použijete všude jako `{{fact.key}}` v textu, tlačítku nebo
odkazu (`tel:{{fact.company_phone}}`): web hodnotu doplní při zobrazení stránky, takže změna tady změní každou stránku.
Seznam ukáže, kde se který fakt používá, a **Tvrzení** najdou věty s čísly, roky a částkami napsané ještě obyčejným
textem, abyste z nich udělali fakta dřív, než zastarají. Některá čísla se spočítají sama – roky od data, počty toho, co
je na webu. Claude fakta čte a mění nástroji `list_facts` a `save_fact`.

## 9. Novinky, SEO a jazyky

**Novinky** jsou blog firmy: kategorie, štítky, plánované vydání, koš, historie verzí. SEO se stará samo (mapa webu,
kanonické adresy, strukturovaná data, `llms.txt` pro AI vyhledávače). Další jazykové verze (`/de/…`) zapnete v
**Rozšíření → Jazykové verze webu** a jazyky vyberete v Nastavení – na výběr je kolem čtyřiceti jazyků včetně všech
jazyků EU. Texty pro návštěvníky (Hledat, Číst dál, formuláře, cookie lišta) jsou přeložené do češtiny, angličtiny,
němčiny, francouzštiny, španělštiny, italštiny, polštiny, slovenštiny, maďarštiny, portugalštiny, arabštiny a čínštiny;
ostatní jazyky je mají anglicky, datum ve svém tvaru. Jazyk webu zvolíte už při instalaci, nezávisle na jazyku administrace.

**Kategorie a štítky.** Každá novinka patří do jedné **kategorie** (Novinky → Kategorie): ta má název, adresu vytvořenou
z názvu, pořadí a na webu s jazykovými verzemi jazyk, do kterého její novinky patří – novinka přebírá jazyk své kategorie.
Kategorii nejde smazat, dokud má novinky. **Štítky** (Novinky → Štítky a témata) vznikají, jak je píšete v editoru
novinky; tady sloučíte dvě podoby do jedné (novinky dostanou druhý štítek a stará adresa přesměruje) nebo štítku dáte
popis, a jeho stránka se tím stane **tématem** – úvodem nad výpisem jeho novinek, který slouží i jako popis pro vyhledávače.

**Nastavení → Jazyky.** Jazyk vlastních textů webu (Hledat, Novinky, Číst dál…), který web hlásí i vyhledávačům, další
jazykové verze, když je funkce zapnutá, a název a popis webu v každé z nich – prázdné pole znamená stejný text jako ve
výchozím jazyce. Je tu i oslovení německých návštěvníků a přepínač *Stejná adresa ve všech jazycích*. Stránky, novinky,
kategorie a položky kolekcí dostávají jazyk ve vlastním nastavení.

**Nastavení → SEO a GEO.** Většinu web zařídí sám – adresy, popisy, mapu webu, strukturovaná data a `llms.txt` pro AI
vyhledávače. Záložka rozhoduje jen o podstatném: jestli smějí vyhledávače web vůbec indexovat, jestli smějí AI vyhledávače
a asistenti používat jeho obsah (slušní roboti pravidlo dodržují; technická ochrana to není), podobu adres (bez lomítka na
konci, s ním, nebo `.html` – ostatní podoby přesměrují kódem 301) a ověřovací kódy pro Google Search Console a Bing
i s adresou mapy webu, kterou tam zadáte.

Němčina má dvě oslovení, formální (Sie) a neformální (du), a volí se zvlášť pro administraci a pro web. Administrace se
řídí volbou každého uživatele (**Můj účet → Oslovení v němčině**; ptá se i instalátor). Texty pro návštěvníky se řídí
**Nastavení → Jazyky → Oslovení v němčině**, takže v administraci můžete tykat a zákazníky na webu přesto vykat. Claude
píše německé texty pro návštěvníky v oslovení webu, ať vám administrace říká cokoli.

Systémové adresy se řídí jazykem: česká verze má `/novinky` a `/hledani`, všechny ostatní `/news`, `/news/category/…`,
`/news/tag/…` a `/search`. Druhá podoba natrvalo přesměruje, takže staré odkazy i pozice ve vyhledávačích zůstanou.

**Hledání na webu.** `/hledani` (`/search`) vypíše stránky, položky kolekcí s vlastní stránkou a novinky jazyka
návštěvníka v jednom seznamu, nejlepší shodu první, s nalezenými slovy zvýrazněnými v krátkém úryvku. Najde tvary slov
v češtině, slovenštině, angličtině a němčině („stavbách“ najde „stavby“), už od dvou písmen; když žádný výsledek nemá
všechna slova, ukáže výsledky s některými z nich. Skryté stránky, koncepty a nic se *Skrýt před vyhledávači (noindex)*
se nikdy neukáže. Do záhlaví dáte hledání šablonou **S vyhledáváním** nebo prvkem Hledání jako ikonou. Claude hledá na
webu stejně nástrojem `search_site`.

**Stejná adresa ve všech jazycích (3.19):** stránka, novinka, kategorie i štítek smí použít stejnou adresu jednou
v každé jazykové verzi – `/kontakt` a `/en/kontakt` jsou dvě stránky, jako na vícejazyčném webu ve WordPressu. Od 3.19
takhle funguje každý web; systémové adresy a kódy jazyků zůstávají vyhrazené ve všech jazycích. **Štítky** patří také
k jazykové verzi: aktualizace dala každému jazyku vlastní štítek tam, kde jeden štítek používaly novinky ve více
jazycích (novinky o štítky nepřišly, každá má ten ve svém jazyce), stránka štítku vypíše novinky své verze a slučovat
lze jen štítky jedné verze. Když aktualizace web přepnout nemohla (dvě položky jednoho jazyka mají stejnou adresu, nebo
uživatel databáze nesmí měnit tabulky), web funguje jako dřív a **Stav systému → Adresy ve všech jazycích** řekne proč
a co udělat; po nápravě zaškrtněte *Stejná adresa ve všech jazycích* v **Nastavení → Jazyky**.

**Přesměrování porovnává starou adresu přesně (3.19):** velikost písmen a lomítka okolo dál nehrají roli, ale `/cafe`
a `/café` jsou teď dvě adresy. Přesměrování, jejichž staré adresy se dřív počítaly jako jedna, vypíše **Stav systému →
Staré adresy přesměrování** – nic se nesmazalo; v Přesměrování z každé skupiny nechte jedno.

**Překlad s Claudem:** požádejte Clauda o překlad stránky nebo celého webu. Přes napojení Clauda začíná překlad jako kopie
stavby původní stránky, takže rozložení zůstane stejné a mění se jen texty a odkazy. Záhlaví a patička nového jazyka
začínají jako kopie těch ve výchozím jazyce.
Jazyk, jehož úvodní stránka ještě nemá zveřejněný překlad, se návštěvníkům nenabízí: chybí v přepínači jazyků, `hreflang`
i v mapě webu, dokud jeho úvod nezveřejníte. Překlad tak můžete v klidu připravit.

Při první návštěvě web pošle návštěvníka, jehož prohlížeč dává přednost některému z jazyků webu, na tu verzi stránky (když
má překlad); jeho volbu v přepínači jazyků si pamatuje prohlížeč, bez cookies. Roboty vyhledávačů web nepřesměrovává.
Přepínač je výchozí vedle menu; do patičky ho přesunete prvkem **Přepínač jazyků** (rozbalovací nabídka, otevírá se nahoru)
a u prvku Navigace vypnete volbu *Přepínač jazyků*.

## 10. AI asistent a Claude

AI **asistenta** zapnete ve **Funkcích** – používá váš vlastní klíč. Vyberte poskytovatele (Anthropic Claude, OpenAI, Google Gemini, Mistral), vložte
klíč API a model. Asistent jen navrhuje. Text odchází k poskytovateli, jen když klepnete na jeho tlačítko.

- V builderu: **✨ Vytvořit sekci s AI** (popište, co má sekce obsahovat) a **Přepsat s AI** u textu prvku (kratší,
  delší, formálněji, přátelštěji, opravit chyby). Výsledek zkontrolujte, fakta doplňte sami. Ctrl+Z změnu vrátí.
- V novinkách: titulky, perex, SEO popis, štítky, korektura, popisy obrázků a překlad do jiného jazyka webu.

V nových instalacích je **napojení na Claude (MCP)** zapnuté (na starších webech ho zapnete v Rozšířeních). Pak máte dvě
možnosti:

- **Konektor v aplikaci Claude (doporučeno):** v Nastavení → Konektory přidejte vlastní konektor s adresou
  `https://vas-web.cz/mcp`. Claude vás pošle na web přihlásit a potvrdit přístup (OAuth), nic nekopírujete. Připojené
  aplikace uvidíte a odpojíte v **Můj účet**. Web musí běžet přes HTTPS; funguje i v podsložce – aplikace Claude si tam
  přihlášení najde, nástroji, který to neumí, dejte osobní token.
- **Přístupový token** (Claude Code, jiné nástroje): v **Můj účet** si vytvořte token a spusťte

```bash
claude mcp add --transport http kaleta https://vas-web.cz/mcp --header "Authorization: Bearer <token>"
```

Claude Code umí i přihlášení přes OAuth: stačí `claude mcp add --transport http kaleta https://vas-web.cz/mcp` bez
hlavičky a při prvním použití potvrdit přístup v prohlížeči.

Claude pak s právy vašeho účtu postaví celý web: nastaví vzhled (barvy, písma, sdílené třídy), nahraje obrázky a písma
do Médií, poskládá stránky, záhlaví, patičku a stránku položky kolekce, opraví jednotlivé prvky podle id, nastaví název webu, údaje firmy
a přesměrování starých adres a píše novinky. Umí také založit **variantu** záhlaví a patičky pro vybrané stránky, nastavit stránce
nadřazenou stránku, jazykovou verzi a plánované zveřejnění, vrátit do konceptu starší publikovanou stavbu a číst poptávky
(jen s právem k Poptávkám). Po každé úpravě stavby dostane **podepsaný odkaz na náhled** konceptu
(platí 60 minut, otevře ho i bez přihlášení) – zkontroluje si výsledek a odkaz může poslat i vám. S každým uložením dostane i **kontrolu před publikováním** (tlačítka bez odkazu, obrázky bez popisu,
osnova nadpisů), aby je mohl rovnou opravit. Stavby stránek
a částí webu i novinky vznikají jako **koncept** a publikuje je jen na váš pokyn (a jen s právem vydávat). Menu, design
systém a sdílené třídy jdou do **konceptu vzhledu**, který návštěvníci uvidí až po zveřejnění (vámi, nebo Claudem na váš
pokyn). Rovnou na webu se projeví nastavení webu, přesměrování, položky kolekce a text stránky – předchozí text stránky
i položky přitom jde do historie verzí. Stránku umí Claude přesunout do koše
(obnovíte ji 30 dní v administraci); e-mail webu, webhooky, poštu, zálohy ani zabezpečení přes napojení nezmění.

**Co smí napojení.** Při připojení Clauda (nebo při vytvoření tokenu) vyberete:

- **Vše, co smí váš účet** – stavět, upravovat i zveřejňovat, nikdy nad rámec vaší role;
- **Jen koncepty** – Claude staví stránky, píše koncepty novinek a připravuje změny vzhledu, zveřejníte je vy; nastavení,
  přesměrování ani nic jiného na živém webu nezmění;
- **Jen čtení** – Claude čte stránky, nastavení, audit a protokol změn a navrhuje úpravy.

**Které nástroje nabízí.** Vyberete také sadu nástrojů: **Základní** (výchozí při připojení Clauda) nabízí nástroje pro
stránky, stavby, vzhled, kolekce, média a požadavky – mnohem kratší seznam, takže každá konverzace začne rychleji a zbude
víc místa pro vaši práci. Ostatní nástroje (novinky, přesměrování, nastavení, rezervace…) si Claude najde přes
`find_tools` a spustí, až je bude potřebovat – čtení přes `run_read_tool`, koncepty a změny přes `run_tool` a mazání,
odesílání nebo cokoli mimo web přes `run_destructive_tool`, který si s vámi Claude nejdřív potvrdí (aplikace Claude se
ptá u každého z nich zvlášť) – se stejným přístupem i pravidly. **Všechny nástroje** nabízí
všechno najednou (výchozí pro osobní token – Claude Code a skripty) – v každé konverzaci asi 210 kB, proto takovému
napojení Můj účet i `site_info` připomenou, že ostatní sady jsou jen zlomek. Napojení z doby před 3.10 mají všechny nástroje dál;
kterékoli napojení přepnete v Můj účet.

Můj účet ukáže přístup a sadu nástrojů každé připojené aplikace a tokenu. **Protokol změn** u každé změny od Clauda uvede název napojení
a umí ukázat jen změny Clauda nebo jen lidí; Claude ho čte také (`list_changes`).

**Nastavení Clauda.** Claude → Nastavení Clauda začínají stavem napojení, adresou pro připojení a tím, co dál. Do
**Pokynů pro Clauda** napište, čeho se má Claude držet – jak oslovujete zákazníky, která slova používat a kterým se vyhnout,
pravidla domu; každé napojení je dostane při připojení. **Mantinely** říkají, co Claude na tomto webu nesmí nikdy, ani když
o to někdo požádá, a audit webu před předáním hlídá, že jsou uložené. Claude navíc nabízí hotové úkoly (níže).

**Zeptejte se Clauda.** Claude → Zeptejte se Clauda je pro kolegy, kteří Clauda sami nepoužívají: požadavek napíšou
obyčejnými slovy („doplň novou otevírací dobu na stránku Kontakt“) a ten počká, až ho vyzvedne připojený Claude – když ho
o to někdo požádá, nebo při naplánovaném běhu. Claude pracuje v konceptech a odepíše, co udělal; požadavek ukazuje svůj stav
a odpověď a kdo se ptal, dostane e-mail, až je hotovo. Stejný rámeček je na Přehledu; bez napojení požadavek uloží na později.

**Nastavení přes napojení.** Kromě názvu webu a údajů o firmě umí Claude zapnout rozšíření a jazykové verze, cookie lištu,
přepínače SEO, kódy analytiky a kód v `<head>` – když o to požádáte.

### Je Claude připojený?

Claude na webu pracuje, jen když ho zavolá – když se ho někdo na něco zeptá v aplikaci Claude nebo v Claude Code, nebo když
přijde čas naplánovaného běhu. Web nepozná, jestli je Claude „online“, ale ví, jestli napojení existuje, kdy Claude volal
naposledy a jestli se požadavky vyřizují samy. Horní lišta každé obrazovky administrace to ukazuje vedle vašeho účtu (na
telefonu jako tečku; slova jsou tam pro čtečky obrazovky) a vede do **Nastavení Clauda**:

| V horní liště | Co to znamená | Co udělat |
|---|---|---|
| **Claude připojený** (zelená tečka) | Napojení existuje a Claude web za posledních 7 dní volal; vedle je čas posledního volání. | Nic. |
| **Claude nečinný** (oranžová) | Napojení existuje, ale Claude 7 dní nevolal – nebo ještě vůbec. | Zeptejte se Clauda na něco o webu, nebo nastavte naplánovaný běh. |
| **Claude: požadavky čekají** (oranžová) | Naplánovaný běh, který vyřizuje požadavky, poslední běh zmeškal nebo nedokončil, nebo má přes 6 hodin zpoždění, a nové požadavky čekají. Když nic nečeká, stojí tam **plánovaný běh se zastavil**. | Zkontrolujte rutinu v Claudovi (Naplánované běhy). |
| **Claude nepřipojený** (červený kroužek) | Žádné platné napojení – nikdo ho nevytvořil, všechna byla zrušena nebo vypršela. | Připojte Clauda (adresu najdete v Nastavení Clauda). |

Bez napojení to **Zeptejte se Clauda** řekne dřív, než začnete psát: tlačítko se změní na **Uložit na později**, uložený
požadavek počká, dokud někdo Clauda nepřipojí, a stejně tak „Požádat Clauda o překlad“ u stránky a požadavek na šablonu
oboru. Když se požadavky vyřizují, jen když se někdo zeptá Clauda, rámeček to řekne i s časem posledního volání; když je
vyřizuje naplánovaný běh, řekne, kdy poběží příště. Výpis **Požadavky** má stejný řádek nahoře a zvýrazní nový požadavek,
který čeká přes 48 hodin, zatímco ho nikdo nevyřizuje. **Nastavení Clauda** začínají stavem, posledním voláním, tím, jak
se požadavky vyřizují, kdy končí první napojení a co dál. **Stav systému** upozorní, když nové požadavky čekají a Claude
není připojený nebo naplánovaný běh neběží, a když napojení skončí do 14 dní; `get_health` to vrací jako
`claude_connection` a konzole flotily to ukáže u každého webu (a takový web zařadí do své schránky).

### Hotové úkoly pro Clauda

Každé napojení Kalety přináší vlastní hotové úkoly – úplné postupy krok za krokem pro práce, o které lidé žádají
nejčastěji. Jsou součástí samotného napojení, takže fungují na každém webu s Kaletou a v každé aplikaci Claude, nic se
neinstaluje. V claude.ai a v desktopové aplikaci Claude otevřete **+** a vyberte úkol u konektoru svého webu; v Claude Code
napište `/` a vyberte ho jako `/mcp__kaleta__migrate_site` (uprostřed je název, který jste napojení dali). Některé úkoly se
nejdřív zeptají na údaj, například adresu starého webu nebo kód jazyka. Každý úkol pracuje v konceptech, drží se vašich
pokynů pro Clauda a před zveřejněním, smazáním nebo odesláním se zeptá.

| Úkol | Co Claude udělá |
|---|---|
| `migrate_site` | Přestěhuje web z WordPressu (i s WooCommerce, Polylangem nebo WPML) nebo z jiné platformy: import, vzhled, přestavěné stránky, kolekce, přesměrování se vzory a 410 a zprávu o přechodu. |
| `launch_site` | Dovede čerstvou instalaci k hotovému webu: údaje a fakta, plán oboru, vzhled, stránky, záhlaví, patičku, menu, právní šablony a kontrolu před spuštěním. |
| `add_language_version` | Přidá jazykovou verzi – i regionální nebo psanou zprava doleva – a přeloží stránky, kolekce, menu, záhlaví a patičku; zkontroluje písma; zveřejní ji, až řeknete. |
| `weekly_care` | Zdraví webu, co se změnilo, audit, nefunkční odkazy, nenalezené adresy, poptávky a překlady a tři věci, které udělat dál. |
| `newsletter_release` | Připraví newsletter z posledních novinek, pošle vám zkušební e-mail a odběratelům ho rozešle až po vašem výslovném souhlasu. |
| `set_up_bookings` | Nastaví online rezervace: služby, lidi, otevírací dobu a výjimky, pravidla a texty e-mailů, stránku s rezervací. |
| `compliance_check` | Záznam o zpracování, cookies, šablonu zásad ochrany osobních údajů, přístupnost a žádosti o osobní údaje – šablony, nikdy právní rada. |
| `build_page`, `translate_page`, `write_news` | Novou stránku, překlad jedné stránky, novinku – jako koncepty. |
| `audit_and_fix` | Spustí audit webu a opraví, co je bezpečné. |
| `work_requests`, `review_pending`, `scheduled_run` | Vyřídí požadavky kolegů, vypíše, co čeká na vaši kontrolu, provede naplánované běhy rutiny. |
| `draft_blueprint` | Připraví plán oboru pro druh podnikání, na který se žádný dodaný nehodí. |

Volitelný plugin „Kaleta for Claude“ pro Claude Code nese pět z těchto postupů jako dovednosti, vygenerované ze stejných
úkolů; když máte web připojený, nepotřebujete ho.

## 11. Přechod z WordPressu nebo z jiného webu na Kaletě

**Správa → Import a export → Import z webu** (2.6): zadejte adresu webu na jakékoli platformě – Wix, Webnode, Jimdo,
Squarespace, Joomla, Drupal nebo WordPress bez exportu. Kaleta přečte mapu webu (nebo projde odkazy), ukáže, co našla, a po
potvrzení z každé stránky udělá skrytou stránku v builderu s obrázky v Médiích; články blogu se stanou novinkami a staré
adresy se přesměrují na nové. Hlavička, patička, menu a cookie lišty starého webu se vynechají a vzhled převezme design
systém. Od 3.33 si stránka nechá, co z ní udělal její builder – ať je to Elementor, Divi, WPBakery, Gutenberg, Breakdance,
Wix, Squarespace nebo Webflow: z formuláře je prvek Formulář se stejnými poli (formulář, který kreslí skript, dostane
zástupný text a výsledek řekne kde), rozbalovací bloky a FAQ si nechají otázky i odpovědi, záložky, počítadla, videa
z YouTube, Vimea i soubory a mapy se stanou svými prvky, odkazované soubory (PDF a další dokumenty) jdou do Médií a původní
titulek stránky a obrázek pro sdílení se stanou jejím SEO titulkem a obrázkem pro sdílení. Totéž umí Claude nástrojem
`import_website`, starou stránku si přečte po sekcích s barvami a písmy (`read_old_page`) a pak sladí vzhled a upraví texty.

**Velké weby (3.7):** import i kontrola přesunu (**Import a export → Zkontrolovat přesun před spuštěním**, u Clauda
`migration_report`) čtou mapy starého webu po dávkách a projdou až 3 000 adres – obchod s tisícem produktů ve dvou
jazycích se vejde. Řídí se `robots.txt` starého webu: stránku, kterou zakazuje, nikdy nestáhnou (kontrola přesto ověří, že
její adresa tady funguje), jeho `Crawl-delay` dodrží (až dvě sekundy) a bez něj dělají mezi požadavky krátkou pauzu.
Dlouhou zprávu čte Claude po 100 problémech parametrem `offset`.

**Správa → Import a export → WordPress:** nahrajte export z WordPressu (Nástroje → Export, soubor XML). Import převede
příspěvky na novinky, stránky volitelně **rovnou do builderu**, stáhne obrázky do Médií a založí přesměrování ze starých
adres. Import jde spustit znovu – co už převedl, přeskočí.

Import přenese i **navigační menu** (3.6): odkazy na naimportované stránky, příspěvky a kategorie vedou na nové adresy,
vlastní odkazy zůstanou a podmenu se zachovají. Menu jdou do konceptu vzhledu, takže živá navigace se změní až po
zveřejnění vzhledu. Účty autorů z WordPressu se nezakládají – novinka patří uživateli s e-mailem autora, jinak vám.
Zaškrtnutím **vše skrytě** přijdou novinky jako koncepty a stránky skryté. Claude dělá totéž nástrojem `import_wordpress`,
vždy skrytě: vezme export nahraný přes `upload_file` (soubor zůstane soukromý, do Médií nikdy nejde) nebo adresu exportu,
pracuje po dávkách, které umí navázat, a vypíše, co přešlo, co se přeskočilo a proč, přesměrování i menu.

### Přesun dvojjazyčného arabského webu

Web na WordPressu v angličtině a arabštině (Polylang nebo WPML, často s obchodem WooCommerce) se přesune jedním importem:

1. **Exportujte vše** ve WordPressu (Nástroje → Export → Veškerý obsah). U WPML použijte jeho doplněk *Export and Import*,
   aby byly v souboru jazyky a skupiny překladů. Velký export nahrajte přes FTP do `storage/import/`.
2. **Importujte s přidáním jazykových verzí** – v náhledu zaškrtněte *přidat na web jazykové verze …* (Claude:
   `import_wordpress` s `add_languages: true`). Arabština se stane jazykovou verzí webu, každá stránka, příspěvek a produkt
   přijde do své verze a propojí se s anglickým originálem (přepínač jazyků i hreflang).
3. **Adresy zůstanou latinkou.** Arabský překlad produktu převezme adresu originálu (`/ar/product/knee-brace`); arabské
   stránky, příspěvky a produkty bez originálu dostanou přepsanou adresu (`/ar/mn-nhn`). Každá stará adresa přesměruje:
   `/ar/…` s arabským slugem, `?lang=ar`, `?p=123` i kategorie produktů pod `/product-category/…` z WooCommerce.
4. **Obchod se stane katalogem** z předvolby Produkty: kód, cena, dostupnost, parametry, shrnutí, popis a hlavní obrázek;
   kategorie produktů budou kategoriemi kolekce se stránkami v obou jazycích. Varianty, objednávky a kupóny zůstanou ve
   WordPressu (souhrn je vypíše). Obchod s vlastním základem adres kategorií potřebuje přesměrování podle vzoru – ukáže to
   kontrola přesunu.
5. **Nastavte úvodní stránku** na naimportovanou: arabská verze se na webu objeví (přepínač, hreflang, mapa webu), jakmile
   je zveřejněný její překlad úvodní stránky.
6. **Zvolte písmo s arabskými písmeny.** Vzhled webu i audit upozorní, když je písmo nadpisů nebo textu nemá; zvolte
   *Moderní bezpatkové* (systémové písmo zařízení) nebo *Arabské bezpatkové*, případně nahrajte jako vlastní písmo Noto Sans
   Arabic, IBM Plex Sans Arabic, Cairo nebo Tajawal.
7. **Spusťte kontrolu přesunu** proti starému webu před přepnutím domény; měla by tu najít každou starou adresu.

Změřeno na vygenerovaném obchodě s 396 produkty v každém jazyce (`tools/fixtures/wordpress-scale.php`): obsah projde ve
14 dávkách (asi 7 sekund), 396 obrázků v dalších 14, každý požadavek hluboko pod 128 MB.

**Přesun webu na Kaletě** k jinému hostingu nebo nový web ze startovací sady agentury: na starém webu vytvořte **Import
a export → Export celého webu**, na novém hostingu nainstalujte Kaletu a v instalátoru zvolte **Začít z exportu**.
Přihlaste se, otevřete **Import a export → Import z Kalety** a nahrajte `.zip` (větší přes FTP do `storage/import/`).
Náhled ukáže, co export obsahuje; po potvrzení se vytvoří záloha databáze a import proběhne sám po dávkách – stránky,
novinky, kolekce, komponenty, sdílené třídy, části webu, menu, pop-upy, přesměrování, knihovna médií i její soubory, název
webu, údaje o firmě, jazyky a vzhled. Každý záznam si nechá své číslo, takže odkazy mezi stránkami, menu a komponentami
platí dál, a každá stavba projde stejnou kontrolou jako uložení v builderu. Uživatelské účty, hesla, klíče, tokeny ani
nastavení pošty a záloh v exportu nikdy nejsou, nový web si nechá své; naimportované novinky budou vaše. Import jde jen do
prázdného webu – čerstvé instalace, případně se startovacím webem.

Soubor s obsahem `obsah.json` v archivu má verzi formátu a popisuje ho přiložený `README.txt`. Kaleta 3.14 zapisuje formát 2
(české názvy tabulek a sloupců), který naimportuje každá Kaleta od 1.8, a přečte i formát 3: stejný obsah pod anglickými
názvy z mapy přejmenování (`pages`, `news`, `tags`, `slug`, `language`…), který bude zapisovat Kaleta 4.0. Data a časy zůstávají
v obou v čase webu.

**Jedna stránka** se přenese také: **Stránky → Exportovat** stáhne JSON se stavbou stránky, sdílenými třídami, které používá,
a jejími komponentami (i komponentami v komponentách). **Stránky → Importovat** na jiném webu založí stránku skrytou,
doplní chybějící třídy (třída, kterou web už má, si nechá svůj vzhled) a komponenty a dřív naimportovanou komponentu použije znovu.

## 12. Audit webu a Stav systému

**Správa → Audit webu** projde celý web a vypíše, co opravit, u každé věci s odkazem, kde ji opravíte: odkazy na stránky,
které neexistují (ve stránkách, částech webu, šablonách, komponentách, pop-up oknech, menu, položkách a novinkách), a
nefunkční externí odkazy nalezené na pozadí, stránky a stránky položek bez popisu, stejné titulky, položky menu vedoucí
na skryté nebo smazané stránky, kontroly builderu (tlačítka bez odkazu, obrázky bez popisu, osnova nadpisů) a adresy,
které často končí „stránka nenalezena“ a nemají přesměrování, a **přístupnost** podle evropského aktu o přístupnosti (WCAG
2.2 AA): kontrast barev design systému, odkazy a tlačítka, jejichž text neříká, kam vedou („klikněte zde“), obrázky v textu
bez popisu, prázdné odkazy, tabulky bez záhlaví, rámce bez titulku a chybějící prohlášení o přístupnosti. Stejný audit
spustí Claude nástrojem `site_audit` a nalezené opraví.

**Adresy, které návštěvníci nenašli.** Když návštěvníci opakovaně přijdou na adresu, která neexistuje, řekne to úvodní
obrazovka a **Projít adresy** otevře seznam v Přesměrování. U každé zvolte **Přesměrovat** (formulář se vyplní, doplňte
cíl), nebo **Ignorovat** (nic ji nenahrazuje, nebo je to robot); **Ignorovat vše** upozornění schová, dokud se neobjeví
nová adresa. Pokusy robotů o adresy jiných systémů se nezapisují a adresa, která zase funguje, zmizí sama.

**Vzory, zrušené adresy a mnoho přesměrování najednou (3.6).** V **Přesměrování** hvězdička zastupuje zbytek adresy:
`/blog/*` → `/novinky/*` pošle `/blog/2019/clanek` na `/novinky/2019/clanek` a `/*/amp` → `/*` odebere příponu. Přesné
přesměrování má vždy přednost před vzorem, mezi vzory vyhraje delší pevný začátek a pravidlo, které by vedlo dokola, se
odmítne. Vzor nikdy nepošle návštěvníky na jiný web, pokud jeho cílem není celá adresa, kterou jste napsali (hvězdička je
tam povolená jen za doménou). Typ **zrušeno (410)** cíl nepotřebuje: adresa odpoví, že stránka zmizela natrvalo, takže ji
vyhledávače vyřadí – hodí se na spamové adresy po napadeném webu. **Import ze souboru CSV** vezme jedno přesměrování na
řádek (stará adresa, cíl, kód 301, 302 nebo 410) nebo export CSV z pluginu Redirection pro WordPress, ukáže, co se přidá,
změní nebo odmítne, a uloží až po potvrzení; až 5 000 řádků najednou. Claude dělá totéž nástrojem `save_redirects` (až 500
na volání, `dry_run` pro kontrolu předem).

**Stav systému.** Péče o web → Stav systému kontroluje, co web drží v chodu: PHP a jeho rozšíření, složky, do kterých web
zapisuje, šifrovací klíč a uložená hesla, soubory jádra, dvoufázové přihlášení správců, úlohy na pozadí (cron), zálohy
a aktualizace, záznamy DNS pro poštu (SPF, DMARC, DKIM), certifikát a registraci domény, jestli `config.php` může číst jen
web, a napojení na Clauda. Červený nebo oranžový řádek řekne, co udělat, a některé se opraví jedním kliknutím. Je tu adresa
úloh pro cron, protokol chyb (posledních 40 záznamů), e-mail, který web pošle, když selže záloha, aktualizace nebo úloha
(nejvýš jeden za hodinu), a stav ve formátu JSON pro monitorovací nástroje jako UptimeRobot.

**Firewall.** Nastavení → Firewall hlídá veřejný web a napojení na Clauda – nikdy administraci, takže se nezamknete,
a adresy vaší místní sítě se nikdy neblokují. Zablokujte jednotlivé adresy nebo sítě, celé země (jen za Cloudflare nebo když
hosting zemi posílá), návštěvníky, kteří posílají příliš mnoho požadavků, a roboty, kteří zkoušejí adresy jiných systémů;
takový návštěvník je na čas zablokovaný. Záložka řekne, za čím web běží, a vypíše posledních 50 odmítnutých požadavků;
protokol se drží 30 dní.

**Nefunkční odkazy.** Péče o web → Adresy → Nefunkční odkazy vypíše odkazy ve zveřejněných stránkách, novinkách
a položkách kolekcí, které nikam nevedou – na stránku, která tu už není, nebo na jiný web, který odpoví chybou. Kontrola
běží na pozadí, jedna novinka za pár minut, takže web nikdy nezpomalí; každý řádek řekne, kde odkaz je, a otevře místo
k opravě. Odkaz, který zase funguje, zmizí sám. Audit webu počítá tytéž odkazy a Claude je čte nástrojem `list_broken_links`.

**Kaleta 2.0** je jeden jasný systém: vrstvy kompatibility z verzí 1.x jsou pryč. Prvek Vyskakovací okno ve stránce se
při aktualizaci změnil na pop-up okno webu – stejný obsah, spouštěč i četnost, ukazuje se jen tam, kde byl, a jeho
tlačítko ho otevře (Vzhled → Pop-up okna). Staré adresy administrace z 1.3 vedou na úvodní obrazovku, staré názvy tříd
a pomocných funkcí z 1.3 už neexistují a nastavení odpovídá jen na současné názvy – napojení na Clauda staré názvy dál
přijímá, takže žádné napojení se nerozbije.

## 13. Zálohy, aktualizace, export

**Nastavení → Zálohy a aktualizace:** podepsané aktualizace a automatické zálohy databáze – každý den, kdy se na webu něco
změnilo, jinak jednou týdně; uchovává se posledních 10. **Kopie mimo server** nahrají každou zálohu na server FTPS nebo do
úložiště S3 (Amazon S3, Backblaze B2, Wasabi, Cloudflare R2) a na stejné místo kopírují i složku `media/` – jen nové a
změněné soubory, na pozadí, takže se tam postupně dostane i velká knihovna médií. Záložka ukazuje, jestli je kopie kompletní.
Od 3.19.1 kopie přes FTPS ověřuje certifikát FTP serveru stejně jako prohlížeč: server s certifikátem podepsaným sám sebou
(častý u domácího NASu) odmítne, dokud nemá certifikát od uznávané autority, třeba Let's Encrypt – nebo použijte S3. Na
vzácném serveru, jehož PHP nemá cURL s FTPS, je kopie dál šifrovaná, ale certifikát ověřit nejde, a záložka to řekne.

**Podpisový klíč (3.19.1):** web podepisuje odkazy, které vydává – sdílené náhledy konceptů, stažení chráněných dokumentů,
odemčené stránky chráněné heslem – vlastním klíčem. **Vytvořit nový podpisový klíč** v Nastavení → Zálohy a aktualizace je
všechny naráz zneplatní, třeba když odkaz na náhled dostal někdo, komu nepatřil. Potvrzení vyjmenuje, co přestane fungovat:
každý sdílený odkaz na náhled i náhled celého webu, odkazy na stažení chráněných dokumentů, které už odešly e-mailem,
odemčené chráněné stránky (návštěvníci zadají heslo znovu), formuláře otevřené v prohlížečích návštěvníků (jedno načtení
znovu), Vlastní HTML v prvcích zkopírovaných dříve do schránky builderu, obrázky pro sdílení dostanou nové adresy (`/og/…`,
sociální sítě si je stáhnou znovu) a dnešní limity odesílatelů whistleblowingu i dnešní počet návštěvníků ve statistice
začnou znovu. Napojení na Clauda se to nedotkne – jejich tokeny na tomto klíči nezávisí. Obnova zálohy z doby před novým
klíčem vrátí starý klíč. Zapíše se do protokolu změn. Dokud šifrovací klíč není přesunutý do config.php, uložená tajemství konektorů a oznámení
whistleblowingu se v tomtéž kroku zašifrují novým klíčem; během nedokončeného přesunu nový podpisový klíč počká.

**config.php (3.19.1):** obsahuje heslo k databázi a šifrovací klíč, proto ho instalátor nastaví tak, aby ho mohl číst jen
web – práva 0600 tam, kde PHP běží jako vlastník složky webu (většina sdíleného hostingu), jinak 0640, aby ho skupina
webového serveru mohla číst dál. Stav systému upozorní, když ho může číst kdokoli na serveru, a kde je PHP jeho vlastníkem,
opraví to jedním kliknutím (**Nastavit, aby config.php mohl číst jen web**); jinak nastavte práva v FTP klientovi nebo ve
správci souborů hostingu.

Chcete-li **obnovit web po ztrátě hostingu**, nainstalujte Kaletu na nový hosting se stejnou předponou tabulek, nahrajte poslední
zálohu databáze přes FTP do `storage/zalohy/`, zkopírujte zpět složku `media/` z kopie na FTPS nebo S3 a pak v Nastavení →
Zálohy a aktualizace u té zálohy klikněte na **Obnovit** a přihlaste se účty ze zálohy.

**Import a export → Export celého webu** vytvoří otevřený balík s obsahem a médii – pro přesun (viz výše) nebo abyste
měli obsah i mimo Kaletu. Poptávky, odběratelé a účty se neexportují.

Veřejné čtecí API z verzí 1.x (`/api/novinky`) ve 2.0 skončilo. Pro novinky použijte JSON Feed (`/feed.json`) nebo RSS,
pro práci s webem napojení na Clauda (MCP), pro události webhooky a pro odnesení obsahu export webu.

## 14. Obchod: pipeline a nabídky

Zákazníci a Obchody jsou funkce: zapněte je ve **Funkcích**, nebo při instalaci zvolte profil *Kancelář a prodej*.
Nabídky jsou samostatná funkce.

**Pipeline** (Pracoviště → Obchod) drží obchody od prvního kontaktu po vyhraný nebo prohraný na jedné nástěnce. Obchod
založíte tlačítkem **Nový obchod** nebo **Vytvořit obchod** u poptávky – zákazník, firma a název se vyplní z ní. Nástěnka
má sloupec pro každou fázi; kartu přesunete tažením, klávesnicí (mezerník, šipky, mezerník) nebo nabídkou fáze. **Vyhráno**
a **Prohráno** obchod uzavřou, Prohráno se zeptá na důvod. Stránka obchodu řekne fázi jednou, v **Přesunout**, a drží
historii fází, poptávku, ze které vznikl, jeho nabídky, odpovědnou osobu a komentáře a úkoly týmu. Další krok si naplánujte
jako úkol u obchodu – filtr „Bez dalšího kroku“ ukáže obchody, se kterými nikdo nehýbe. Správce fáze přejmenuje, přidá
a seřadí ve **Fázích**. Přehledy počítají obchody, nikdy jejich hodnoty.

**Nabídky** se zapínají ve Funkcích. **Interní ceny** vidí jen tým (řádek na produkt nebo variantu, nebo vlastní řádky;
jde nahrát CSV); měnit je smí jen kdo má právo *Nabídky: měnit interní ceny*. Nabídku vytvoříte tlačítkem **Nová
nabídka**, nebo z poptávky, obchodu či zákazníka – zákazník a řádky z košíku poptávky se převezmou. Nabídka odchází
e-mailem (v Nastavení → Pošta musí být SMTP server); zákazník ji otevře soukromým odkazem, stáhne PDF a – když to nastavení
dovolí – ji na té stránce přijme nebo odmítne. Číslo dostane při prvním odeslání. Claude nabídky připravuje jako koncepty
a odešle je, jen když o to požádáte.

## 15. Zákazníci

Zákazníci jsou funkce: zapněte ji ve **Funkcích** (nebo při instalaci zvolte profil *Kancelář a prodej*); kontakty
se u každé poptávky drží, i když je vypnutá. Záznam **zákazníka** vznikne sám, když někdo pošle poptávku, objedná si termín nebo se přihlásí k odběru novinek – jeden záznam na
e-mailovou adresu; **Nový zákazník** ho přidá ručně. Stránka zákazníka má jednu **časovou osu**: poptávky s prvním řádkem
zprávy, odpovědi, rezervace, obchody, nabídky a záznamy týmu o hovorech, schůzkách, e-mailech a poznámkách (**Zapsat
hovor / schůzku / e-mail / poznámku**). Panel **Úkoly** ukazuje jeho vlastní úkoly a pod *U jeho poptávek a obchodů* další
otevřený úkol každé jeho poptávky a obchodu s odkazem tam, kde se dělá (3.50; obchody jen pro toho, kdo má Pipeline).

Lidé jedné firmy patří k **firmě** (záložka Firmy), která má vlastní stránku se svými lidmi a obchody. Dva záznamy jednoho
člověka sloučí správce tlačítkem **Sloučit s…** – vždy rozhodnutím člověka. Ctrl K (⌘K na Macu) najde zákazníka podle
jména, e-mailu nebo firmy odkudkoli. Zákazníci jsou osobní údaje a na webu se nikdy neukazují: zákazník bez aktivity po
dobu nastavenou v **Nastavení → Soukromí a cookies** (výchozí 24 měsíců) se anonymizuje (viz kapitola 18).

## 16. Žádosti o práci

**Žádosti** přicházejí z formulářů na stránkách pracovních pozic a z formulářů s *Účelem* „Žádost o práci“; přiložené
životopisy se ukládají šifrovaně. Vidí je jen lidé se sekcí Žádosti – zaškrtněte ji v Uživatelích nebo dejte připravenou
roli **HR**. Seznam se otevře na žádostech, o kterých ještě není rozhodnuto; *Čekající* ukáže nové bez odpovědi déle než
týden. Žádost posouváte stavy Nová, Prohlédnutá, Pohovor, Nabídka a Přijat – nebo Zamítnuta s důvodem; **Nástěnka** ukáže
jednu pozici ve sloupcích. Odpověď ze šablony (přijato, pozvánka, zamítnutí) posune i stav; šablony pro každý jazyk jsou
v nastavení Žádostí.

V **databázi talentů** zůstávají lidé, kteří souhlasili, že se dozví o dalších pozicích, dokud jim souhlas neskončí. Žádosti se
mažou samy po době v Nastavení → Soukromí a cookies a každá ukazuje svůj den.

Jednou za hodinu Kaleta zkontroluje, že každý zveřejněný formulář žádosti o práci jde na webu otevřít – na stránce, v pop-up
okně, v části webu i v šabloně položky kolekce (v kolekci vytvořené z předvolby pracovních pozic se počítá každý formulář).
Když z webu zmizí – stránka skrytá, stránky položek vypnuté, formulář skrytý –, dostanou upozornění všichni se sekcí Žádosti
a zmizí, až je formulář zpět. Kolekce bez otevřené pozice je v pořádku; šablona jejích kategorií se nehlídá.

## 17. Interní soubory

**Interní soubory** jsou dokumenty týmu – ceníky, smlouvy, návody – které nikdy nedostanou veřejnou adresu. Správce
zakládá složky a rozhoduje, kdo kterou vidí: všichni se sekcí, vybrané role nebo oddělení. Soubor nahrajte do složky
s popisem a štítky, aby ho kolegové našli podle slova; **Nahradit** nechá starší verze, každou jde dál stáhnout, a stav
řekne, jestli dokument ještě platí. Soubory se stahují jen přes administraci. Soubor jde připojit k zákazníkovi, obchodu
nebo poptávce a pak se ukáže v jejich rámečku. Smazat jde jen prázdnou složku; soubor smaže správce nebo ten, kdo ho
nahrál. Claude soubory najde podle názvu a popisu, ale jejich obsah nedostane nikdy.

## 18. Soukromí a doba uchování

**Oznamovací kanál (whistleblowing):** oznámení přicházejí na jeho vlastní stránce (`/_report`), až s pěti přiloženými
soubory; ukládají se šifrovaně a případ otevřou jen čtenáři vybraní v jeho nastavení – ostatní se sekcí vidí čísla případů
a data. Každé oznámení včas potvrďte a dejte oznamovateli zpětnou vazbu – obrazovka ukazuje obě lhůty. Oznamovatel dostane
číslo případu a přístupový kód, se kterými případ sleduje, čte vaše zprávy a doplňuje informace, aniž by uvedl jméno.
Uzavřené případy se smažou po době z nastavení.

**Osobní údaje na žádost:** když se někdo zeptá, co o něm web drží, otevřete **Poptávky → Žádost o osobní údaje** a zadejte
jeho e-mailovou adresu – obrazovka vyjmenuje každé místo (poptávky s přílohami, odběr, e-maily čekající na odeslání, žádosti
o referenci, rezervace), stáhne vše jako soubor pro dotyčného a po zaškrtnutí, že to opravdu chcete, to natrvalo vymaže.
Totéž udělá Claude, když ho požádáte, ať *najde jeho osobní údaje*, a jeho odpověď pokryje i zákazníky, žádosti o místo
a komentáře. Vymazání je vždy vaše rozhodnutí a záloha obnovená později vymazání použije znovu.

**Doba uchování:** Nastavení → Soukromí a cookies drží všechny doby uchování na jednom místě – jak dlouho se drží
poptávky, žádosti o místo, zákazníci (výchozí 24 měsíců bez aktivity, pak se anonymizují), nabídky, rezervace, záznam
souhlasů s cookies a další záznamy. Starší web smaže sám; kratší dobu než dosud potvrdíte na samostatné obrazovce.
**Záznam o činnostech zpracování** pro vaše zásady ochrany osobních údajů připraví Claude podle toho, co web opravdu dělá.
Mezi záznamy s pevnou dobou jsou i příjemci newsletteru, smazaní den po jeho skončení, a pozastavený newsletter, zrušený
po 7 dnech.

**Cookie lišta.** Stejná záložka rozhoduje o cookie liště: vestavěná (její text a odkaz na zásady ochrany osobních údajů
v každé jazykové verzi), cizí podle svého kódu, nebo žádná pro web, který nenastavuje sledovací cookies. Vestavěná lišta
zapisuje udělené souhlasy jako doklad pro případnou kontrolu, ve výchozím stavu na 36 měsíců. Kódy měření a marketingu
z Nastavení → Měření se spustí, až je návštěvník povolí, a prohlížeč, který posílá signál Global Privacy Control, platí za
„jen nezbytné“. Na této záložce je i **Kontrola spamu ve formulářích**.

## 19. Moje práce a Můj účet

Dvě obrazovky patří každému přihlášenému, ať má jakoukoli roli: **Moje práce** v menu a **Můj účet** pod vaším jménem
nahoře. Ani jedna není sekce, kterou by správce dával nebo bral, a ani jedna neukáže nic, co byste nesměli otevřít jinde.
Zvonek vedle jména vede na vaše upozornění.

**Moje práce.** Vypíše, co na vás čeká: nejdřív vaše úkoly, pak poptávky, které vyřizujete, nabídky k dokončení nebo
k připomenutí, stránky a položky, za které odpovídáte, a odpovědi na vaše požadavky pro Clauda – každý řádek otevře místo,
kde se práce dělá. **Nový úkol** přidá úkol pro vás nebo kolegu s termínem a připomínkou; dokončíte nebo odložíte ho přímo
v seznamu. **Shrnutí** ukáže, kolik tým za období udělal, jen v počtech, a správci mají navíc záložku **Tým** s otevřenými
úkoly všech a s úkoly, které nemá nikdo. Kdo není správce, vidí součet týmu jen tehdy, když ho sdílí dost dalších lidí, a práce,
kterou sám přidělil kolegovi, se do toho nepočítá. **Odpovědná osoba** u záznamu ho nikdy nikomu jinému neskryje.

**Upozornění.** Zvonek sbírá zmínky, komentáře k tomu, za co odpovídáte, věci, které vám někdo přidělil, a termíny
kontroly; drží je 90 dní. **Označit vše jako přečtené** vynuluje počet. Jak se o nich dozvíte e-mailem – o každém hned,
jedním souhrnem každé ráno, nebo vůbec – si volíte sami v Mém účtu; upozornění na záznam otevře ten záznam a nic ze
seznamu nejde nikomu jinému.

**Můj účet.** Jeho záložky patří jen vám. **Moje údaje** – jméno, e-mail, jazyk administrace (i oslovení v němčině) a jak
k vám chodí upozornění. **Nepřítomnost** – zadejte dny, kdy jste pryč, se zástupcem: dokud jste pryč, chodí kopie vašich
upozornění zástupci (pravidla jsou v kapitole 7). **Zabezpečení** – heslo, dvoufázové přihlášení aplikací, klíče (passkeys)
a záložní kódy. **Claude** – aplikace a osobní tokeny připojené pod vaším účtem s jejich přístupem a sadou nástrojů,
připravené k odpojení (kapitola 10). **Podpis** – podpis pod vašimi odpověďmi e-mailem. **Kolegové** – pozice a pracovní
telefony kolegů, podle abecedy nebo oddělení, a organizační struktura.

## 20. Knihovna médií

**Obsah → Média** drží každý obrázek a soubor webu: fotky pro stránky a novinky, loga, PDF ke stažení, písma. Nahrajete je
výběrem souborů nebo přetažením na stránku, obrázek jde pustit i rovnou do textu v editoru. Soubory třiďte do **složek**,
hledejte podle názvu, popisu nebo jména souboru a několik souborů přesunete najednou zaškrtávátky v seznamu. Soubor, který
uložilo napojení Clauda omezené na koncepty, čeká v **Čeká na schválení**, dokud ho nezveřejníte.

**Velikosti a popisy obrázků.** Každý nahraný obrázek dostane sám menší varianty a kopii WebP a web každému návštěvníkovi
pošle velikost, kterou jeho obrazovka potřebuje. Každému obrázku dejte **popis pro nevidomé (alt)** – čtou ho nevidomí
návštěvníci i vyhledávače; **Nahradit** vymění soubor a nechá všechna místa, která ho používají. Média → **Nepoužité
soubory** vypíše, na co neukazuje žádná stránka, stavba, novinka, položka, komponenta, pop-up okno, newsletter ani
nastavení, stejný soubor nahraný dvakrát, příliš velké obrázky ke zmenšení a obrázky bez popisu k hromadnému doplnění.

**Kde se obrázek používá.** Detail souboru vypíše každé místo, které ho používá – novinky, stavby, položky kolekcí, logo –
takže víte, co se změní, než ho nahradíte nebo smažete. Média nemají koš: smazaný soubor je pryč a soubory, které web ještě
používá, se při mazání více souborů najednou přeskočí. Claude nahrává soubory nástrojem `upload_file`, obrázky bez popisu
najde nástrojem `list_media_without_alt` a popíše je nástrojem `update_media`.

## 21. Statistika a protokol změn

**Statistika.** Péče o web → Statistika počítá návštěvy bez cookies a bez ukládání čehokoli o člověku – jen součty po
dnech. Ukazuje návštěvy a zobrazení stránek, nejnavštěvovanější stránky, weby a kampaně, odkud návštěvníci přišli,
zařízení, konverze pop-up oken a – když se pamatuje původ poptávek – které stránky a kampaně přinášejí poptávky a odběry.
**Hledané výrazy** a **Hledání bez výsledku** ukážou, co návštěvníci na webu hledají – jen výrazy, drží se 400 dní.
Počítají se jen návštěvy potvrzené prohlížečem návštěvníka, takže roboti a vaše vlastní návštěvy vypadnou. **Rychlost
u návštěvníků** měří, jak rychle se stránky načítají ve skutečných prohlížečích (LCP, CLS, INP), a audit webu upozorní,
když se stránka zpomalí. Stejný přehled čte Claude nástrojem `get_stats`.

**Nastavení → Měření.** Tady se vestavěná statistika zapíná a vypíná – bez cookies a bez souhlasu. Vedle ní můžete přidat
Google Analytics, Google Tag Manager nebo jiný nástroj (Matomo, Plausible, vlastní kód): vložte ID nebo kód a s vestavěnou
cookie lištou se spustí, až návštěvník povolí analytické nebo marketingové cookies. Kaleta posílá do datové vrstvy své
konverzní události (odeslaný formulář, přihlášení k odběru, klik na telefon nebo e-mail, stažení souboru) a záložka nabízí
šablonu kontejneru pro Tag Manager (kapitola 7). Google Analytics mějte na jednom místě, jinak se návštěvy počítají dvakrát.

**Protokol změn.** Péče o web → Protokol změn (správci) vypíše každou změnu v administraci i přes napojení na Clauda – kdo,
kdy, co a v které sekci – od nejnovější. Filtrovat jde podle toho, kdo změnu udělal (lidé i Claude, lidé v administraci,
nebo Claude), podle sekce a podle slova v detailu; každá změna od Clauda nese název svého napojení. Protokol drží historii
šest měsíců, ne obsah: starší text vrátí verze stránky nebo historie položky. Claude protokol čte nástrojem `list_changes`.

**Vrácení relace Clauda.** Změny, které jedno napojení Clauda udělá za sebou, tvoří **relaci** (Protokol změn → Relace
Clauda). **Vrátit** vrátí všechno, co relace změnila – stránky, stavby a koncepty, novinky, položky kolekcí, menu,
nastavení, vzhled – do stavu před ní. Řádky, které potom změnil člověk nebo jiná relace, zůstanou, jak jsou, a vypíšou se;
vrácení s volbou *Přepsat i pozdější změny* vrátí i je. Relace se drží omezený počet dní, který obrazovka uvádí.

## 22. Zápisník Clauda, naplánované běhy, oborové plány a integrace

**Zápisník Clauda.** Claude → Zápisník drží poznámky pro toho, kdo na webu bude pracovat příště – pro Clauda v nové
konverzaci nebo pro kolegu: rozhodnutí („ceny vždy bez DPH“), co bylo klientovi slíbeno, kde co je. Poznámky mají téma
a jde je připnout, takže důležité jsou první; hledání najde poznámku podle slova. Claude zápisník čte na začátku práce
a zapisuje do něj nástrojem `write_notebook`, takže se na jednou přijaté rozhodnutí neptá znovu. Kontrolní seznam předání
počítá rozhodnutí v zápisníku.

**Naplánované běhy.** Claude → Naplánované běhy drží plány pro rutinu v Claudovi – co, jak často a kdy: každé ráno vyzvednout
požadavky, v pondělí týdenní péče, jednou měsíčně newsletter. Web Clauda sám nespouští: **Nastavení v Claudovi** dá zadání
rutiny pro vaši aplikaci Claude, která se připojí přes napojení jen na koncepty, udělá, co je na řadě, a ohlásí to zpět.
Každý plán ukazuje příští termín a poslední běh s historií a jde ho pozastavit; horní lišta řekne, když se běh, který
vyzvedává požadavky, zastavil.

**Oborové plány.** Údaje o firmě → Oborové plány použijí hotový plán pro druh podnikání – s Kaletou jich přichází dvacet,
od restaurace, ordinace nebo řemeslníka po obec nebo softwarovou firmu – nebo plán z jiného webu: jeho otázky se stanou
fakty a jeho kontroly řeknou, co webu ještě chybí (otevírací doba, ceník, stránka s rezervací). Sejmutí plánu odstraní jeho
kontroly a obsah nechá. **Stáhnout** uloží současný web jako plán pro další; Claude umí připravit plán pro druh podnikání,
na který se žádný dodaný nehodí (`draft_blueprint`).

**Integrace.** Správa → Integrace (správci) propojí web s Googlem (Search Console, Firemní profil, Tabulky), Bingem,
HubSpotem, Pipedrivem a Raynetem: vlastní aplikace OAuth webu nebo token API, uložený a nikdy znovu nezobrazený, nastavení
služby, připojení a odpojení a poslední volání. Pak třeba nové poptávky jdou do tabulky Google nebo do vašeho CRM,
otevírací doba se dostane do Firemního profilu a dotazy, které lidé psali v Googlu a Bingu, se ukážou ve Statistice. Žádá
se jen o oprávnění, která web potřebuje, a klíče přes napojení na Clauda nikdy dostupné nejsou.

## 23. Konzole webů

Konzole webů je pro agentury a IT týmy, které se starají o víc webů na Kaletě: jedna instalace Kalety s funkcí **Konzole
webů** je ukáže všechny na jedné obrazovce. **Dnes** ukáže nejdřív, co potřebuje pozornost – nepovedenou zálohu,
čekající aktualizaci, certifikát před vypršením; schránka sbírá, co čeká na každém webu, a každý web má svou historii
a úkoly. Weby patří zákazníkům: konzole pro každého zákazníka udělá měsíční zprávu a posílá upozornění podle vašich
pravidel. Nové verze se k webům dostávají po vlnách, takže je vaše testovací weby dostanou první.

**Hlášení do konzole.** Na každém webu ho Nastavení → Hlášení do konzole spáruje s konzolí: v Konzoli webů → Přidat web
vytvořte párovací klíč a vložte ho sem (platí 24 hodin a jen jednou). Web pak každou hodinu pošle podepsané hlášení – verzi,
zdraví, úlohy na pozadí, zálohy, počty toho, co čeká – nikdy osobní údaje, a konzole do webu nikdy nesahá. Tady také
dovolíte konzoli rozhodovat, kdy se nainstalují nové verze, uvidíte otisky klíčů, pošlete hlášení hned nebo se odpojíte.
Záložka se objeví, jakmile je v Nastavení → Agentura uvedená agentura nebo je web spárovaný.

## 24. Doplňky a další nastavení

**Doplňky.** Správa → Doplňky vypíše kód jiných vývojářů zkopírovaný do složky `extensions/` webu: každý zapnete nebo
vypnete, uvidíte, co potřebuje, i chybu, která ho vypnula, a otevřete stránky administrace, které přidává. Z administrace
se nic nenahrává ani nestahuje – složku zkopíruje vývojář nebo hosting a správce doplněk zapne. Doplňky s Kaletou mluví jen
přes její popsané rozhraní (Extension API) a doplněk, jehož chyba zastaví stránku, se sám vypne; jak ho napsat, popisuje
`docs/EXTENSIONS.md`.

**Nastavení → Základní.** Název a popis webu, adresa webu (z ní vznikají všechny absolutní odkazy – změňte ji, když se web
stěhuje na jinou doménu), e-mail webu, odkazy na sociální sítě, časové pásmo, měna, kterou začínají obchody, úvodní stránka
a adresa i titulek novinek, cache stránek a **režim údržby**, který návštěvníkům ukáže oznámení, zatímco pracujete. Změna
časového pásma se zeptá, jestli převést uložená data; změna názvu hostitele upozorní, že klíče staré adresy přestanou
fungovat.

**Nastavení sekcí.** Některá nastavení žijí u své sekce – nabídky a ceník, žádosti o místo, fáze obchodů, rezervace, uložené
odpovědi poptávek, oznamovací kanál, pokyny pro Clauda. Tato záložka je vypíše všechna na jednom místě, jen u sekcí, které
smíte otevřít, s odkazem na každé. Vlastní pole nemá; doby uchování všech sekcí jsou v Nastavení → Soukromí a cookies
a samotné funkce ve Funkcích.

**Nastavení → Zabezpečení.** Pravidla přihlášení pro všechny: kdo musí mít dvoufázové přihlášení (aplikací nebo klíčem) –
takový člověk se po přihlášení dostane jen do Mého účtu, dokud si ho nenastaví – a jestli se účty s klíčem přihlašují jen
klíčem. **Pozastavovat automaticky** zablokuje účty a zruší napojení, které se dlouho nepoužívaly, kontrola běží jednou
denně; bez toho je Stav systému a audit webu jen vypíšou. Blokování adres a zemí je v Nastavení → Firewall.

**Nastavení → Pošta.** Jak web posílá své e-maily – odkazy na heslo, upozornění, poptávky, odpovědi, nabídky a newslettery:
přes funkci `mail()` hostingu (nic se nenastavuje, ale část zpráv skončí ve spamu), nebo přes vlastní **SMTP server** –
schránku u hostingu, Google Workspace, Seznam nebo službu jako Brevo, Mailgun či Amazon SES, která doplní server, port
a šifrování. **Poslat zkušební e-mail** ho ověří; zpráva, kterou server nepřevezme, zůstane ve frontě a úlohy na pozadí ji
zkusí znovu.

**Nastavení → Zprávy.** Po skončení měsíce web udělá zprávu o tom, co se s ním dělo: vyřízené požadavky, změny lidí
a Clauda, aktualizace, zálohy, zabezpečení, audit webu, návštěvnost, poptávky, rezervace a newsletter – počty, nikdy lidi.
Archivované zprávy si prohlédnete a vytisknete, nebo si necháte zkrácenou verzi poslat e-mailem v prvních dnech každého
měsíce sobě nebo klientovi, s logem agentury z Nastavení → Agentura. Dostupnost se ve zprávě objeví jen s konzolí webů.
