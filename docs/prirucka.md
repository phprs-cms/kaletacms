# Příručka Kalety

Návod pro toho, kdo web spravuje: od instalace přes builder stránek až po napojení na AI.
[English version](guide.md)

## 1. Instalace a první kroky

1. Nahrajte soubory na hosting (PHP 8.3+, MySQL 8 / MariaDB 10.6+), založte prázdnou databázi a otevřete `/install.php`.
2. Ve formuláři vyberte **startovací web**: *Firemní web*, *Řemeslo a služby*, nebo *Poradenství a agentura*. Každý
   přinese vlastní styl a stránky Úvod, O nás, Služby a Kontakt z hotových sekcí s ukázkovými texty.
3. Vyberte, **co chcete mít zapnuté**: Novinky, Formuláře a poptávky, Newsletter, Statistiku, Přesměrování, jazykové
   verze, AI asistenta… Rozšíření jde kdykoli zapnout a vypnout v administraci (**Rozšíření**); vypnutím se nic nesmaže.
4. Instalace založí i skrytou kostru **Zásad ochrany osobních údajů** v jazyce webu s odkazem v patičce, v cookie liště a u souhlasu ve formuláři. Doplňte údaje v hranatých závorkách a stránku zveřejněte. Nová stránka ze šablony **Zásady ochrany osobních údajů** (Stránky → Nová stránka) se řídí tím, co má web zapnuté – poptávky, newsletter, statistika, měření a marketingové kódy, mapy – a doplní údaje o firmě. Vždy je to šablona ke kontrole, ne právní rada.
5. Instalátor je česky, anglicky, německy a španělsky (řídí se jazykem prohlížeče). Na konci ukáže adresu pro napojení Clauda a první zadání na vyzkoušení.
6. Po přihlášení vás na **Přehledu** provedou **První kroky**: vzhled webu, údaje o firmě, stránky, zásady a pošta.

Administrace je na adrese `/admin.php`. Role: **správce** smí všechno, **editor** spravuje obsah, **autor novinek** píše
jen své novinky a vydat je smí jen s právem vydávat. V **Uživatelé → Role** si založíte **vlastní role** – pojmenovanou
sadu sekcí (třeba „Obchodník“ jen s Poptávkami); změna role se přenese na všechny její členy.
Začít můžete hotovou rolí: **Klient** (stránky, novinky, kolekce, poptávky a statistika – vzhled, nastavení a uživatelé
zůstávají vám), **Autor textů** (novinky, které vydá někdo jiný) a **Jen poptávky**.

**Nápověda na každé obrazovce.** Vedle nadpisu každé části administrace otevře **Jak s tím pracovat** její článek
v návodu (v jazyce administrace, pokud ho návod má); v builderu je článek v Nápovědě (**?**). Administrace je česky,
anglicky, německy a španělsky – každý uživatel si vybere v **Můj účet**. Nástroje Clauda (MCP) mluví vždy anglicky.

**Stavíte web pro klienta?** V **Nastavení → Obecné → Web postavil a stará se o něj** vyplňte své jméno, web, e-mail,
telefon a logo: přihlašovací obrazovka a patička administrace klientovi ukážou, koho se zeptat. Před předáním vypíše
**Audit webu → Před předáním**, co ještě chybí – e-mail webu, SMTP server, kopie záloh mimo server, údaje o firmě,
indexování, ikona webu, měření bez cookie lišty, bezpečnostní kontakt, správci bez dvoufázového přihlášení nebo passkey,
vlastní účet klienta, váš kontakt a úlohy na pozadí.

## 2. Builder stránek

Builder otevřete v **Stránky → Builder** (nebo odkazem „Upravit zde“ na webu). Uprostřed je **plátno – skutečná stránka
webu**, vlevo panel **Přidat / Struktura**, vpravo vlastnosti vybraného prvku.

- **Vkládání:** klepněte na prvek nebo hotovou sekci v panelu Přidat, nebo je **přetáhněte na plátno** – modrá čára
  ukáže, kam dopadnou (rámeček = dovnitř kontejneru). Po najetí na hotovou sekci uvidíte její náhled.
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
  **vyhledávání**, **tlačítko nahoru** a **odběr novinek**. Sekce umí **video na pozadí**, video **plakát**, navigace
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

- **Začít ze šablony:** čtyři záhlaví (logo vlevo a menu vpravo, logo uprostřed, s kontaktní lištou, minimální), čtyři
  patičky (sloupce, kompaktní, s tiráží, s výzvou k akci) a jednoduché nebo rozšířené obálky. Šablona je čistá kostra –
  barvy, písma a mezery si bere z design systému. Uloží se do konceptu části, takže web se změní až po publikování;
  zkontrolujte ji na celém webu přes **Náhled celého webu**.

- **Záhlaví a patička** jsou na každé stránce. Prvky **Logo**, **Navigace** (na telefonu se schová za tlačítko) a
  **Údaje firmy** se plní samy.
- **Obálky** (detail novinky, výpis novinek, stránka 404) přidají sekce kolem obsahu, který skládá systém. Prvek
  **Obsah stránky** označuje místo, kam systém obsah vloží.
- **Varianty:** u záhlaví a patičky zvolte **Přidat variantu**, pojmenujte ji a zaškrtněte stránky. Varianta platí jen
  tam. Prázdná varianta část skryje – hodí se pro landing page.

**Vzhled → Menu** sestaví hlavní menu a menu v patičce: stránky, vlastní odkazy, novinky a skupiny, pod každou položkou
jedno podmenu. Pořadí měníte přetažením nebo šipkami. Dokud menu neuložíte, skládá se samo ze stránek „v navigaci“.

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

Odeslané zprávy jsou v **Obsah → Poptávky**: stav (nová, přečtená, vyřízená), odpověď e-mailem, export do CSV. Je-li formulář na
stránce, na kterou vede reklama nebo newsletter, poptávka ukáže i **kampaň** z adresy (`utm_source`, `utm_medium`, `utm_campaign`…) –
v administraci, v e-mailu s upozorněním, v CSV i ve webhooku, bez cookies.
Poptávky obsahují osobní údaje, proto se po nastaveném počtu měsíců (výchozí 24) samy mažou.

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

Rozšíření **Newsletter** přidá prvek **Odběr novinek**: návštěvník zadá e-mail a potvrdí ho odkazem (double opt-in).
Potvrzené adresy najdete v **Obsah → Odběratelé** a vyexportujete je do CSV i s odkazem na odhlášení pro váš
rozesílací nástroj.

Nebo v **Rozšíření → Newsletter** napojte mailingovou službu, kterou už používáte: Brevo, MailerLite, Mailchimp, Ecomail,
SmartEmailing nebo jakoukoli jinou přes webhook (Make, Zapier, n8n). Zadejte klíč API a seznam; každý potvrzený odběratel
se pak přidá do seznamu a odhlášený se z něj odebere. Přenos běží na pozadí a nepovedené pokusy se opakují; v
**Odběratelích** vidíte stav každé adresy a stávající odběratele pošlete do služby jedním tlačítkem.

**Obsah → Newslettery** pošlou potvrzeným odběratelům novinky přímo z webu. E-mailový builder tu není: jedna šablona
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

## 8. Údaje o firmě

**Nastavení → Firma:** obchodní firma, druh podniku, IČO, DIČ, adresa, telefon, **otevírací doba** (po řádcích, např.
„Po–Pá 8:00–17:00“, „So 9–12“, „Ne zavřeno“), odkaz na mapu a souřadnice. Prvek **Údaje firmy** je vypíše kdekoli
na webu a vyhledávače je dostanou jako strukturovaná data – Google tak ukáže otevírací dobu i adresu.
Pro **tiráž** (právní informace o provozovateli, v Německu Impressum) vyplňte zápis v rejstříku a kdo firmu zastupuje
a založte stránku podle šablony **Tiráž** (Stránky → Nová stránka) – prvek Údaje firmy v režimu *Tiráž* vypíše všechny
vyplněné údaje o provozovateli.

## 9. Novinky, SEO a jazyky

**Novinky** jsou blog firmy: kategorie, štítky, plánované vydání, koš, historie verzí. SEO se stará samo (mapa webu,
kanonické adresy, strukturovaná data, `llms.txt` pro AI vyhledávače). Další jazykové verze (`/de/…`) zapnete v
**Rozšíření → Jazykové verze webu** a jazyky vyberete v Nastavení – na výběr je kolem čtyřiceti jazyků včetně všech
jazyků EU. Texty pro návštěvníky (Hledat, Číst dál, formuláře, cookie lišta) jsou přeložené do češtiny, angličtiny,
němčiny, francouzštiny, španělštiny, italštiny, polštiny a slovenštiny; ostatní jazyky je mají anglicky, datum ve svém
tvaru. Jazyk webu zvolíte už při instalaci, nezávisle na jazyku administrace.

Němčina má dvě oslovení, formální (Sie) a neformální (du), a volí se zvlášť pro administraci a pro web. Administrace se
řídí volbou každého uživatele (**Můj účet → Oslovení v němčině**; ptá se i instalátor). Texty pro návštěvníky se řídí
**Nastavení → Obecné → Oslovení v němčině**, takže v administraci můžete tykat a zákazníky na webu přesto vykat. Claude
píše německé texty pro návštěvníky v oslovení webu, ať vám administrace říká cokoli.

Systémové adresy se řídí jazykem: česká verze má `/novinky` a `/hledani`, všechny ostatní `/news`, `/news/category/…`,
`/news/tag/…` a `/search`. Druhá podoba natrvalo přesměruje, takže staré odkazy i pozice ve vyhledávačích zůstanou.

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

**Asistent** zapnete v **Rozšíření**. Vyberte poskytovatele (Anthropic Claude, OpenAI, Google Gemini, Mistral), vložte
klíč API a model. Asistent jen navrhuje. Text odchází k poskytovateli, jen když klepnete na jeho tlačítko.

- V builderu: **✨ Vytvořit sekci s AI** (popište, co má sekce obsahovat) a **Přepsat s AI** u textu prvku (kratší,
  delší, formálněji, přátelštěji, opravit chyby). Výsledek zkontrolujte, fakta doplňte sami. Ctrl+Z změnu vrátí.
- V novinkách: titulky, perex, SEO popis, štítky, korektura, popisy obrázků a překlad do jiného jazyka webu.

**Napojení na Claude (MCP)** je v nových instalacích zapnuté (na starších webech ho zapnete v Rozšířeních). Pak máte dvě
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
všechno najednou (výchozí pro osobní token – Claude Code a skripty). Napojení z doby před 3.10 mají všechny nástroje dál;
kterékoli napojení přepnete v Můj účet.

Můj účet ukáže přístup a sadu nástrojů každé připojené aplikace a tokenu. **Protokol změn** u každé změny od Clauda uvede název napojení
a umí ukázat jen změny Clauda nebo jen lidí; Claude ho čte také (`list_changes`).

**Pokyny pro Clauda.** V **Rozšíření → Napojení na Claude** napište, čeho se má Claude držet – jak oslovujete zákazníky,
která slova používat a kterým se vyhnout, pravidla domu. Každé napojení je dostane při připojení. Claude navíc nabízí
hotové úkoly (níže).

**Nastavení přes napojení.** Kromě názvu webu a údajů o firmě umí Claude zapnout rozšíření a jazykové verze, cookie lištu,
přepínače SEO, kódy analytiky a kód v `<head>` – když o to požádáte.

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
adresy se přesměrují na nové. Hlavička, patička, menu, cookie lišty a formuláře starého webu se vynechají a vzhled převezme
design systém. Totéž umí Claude nástrojem `import_website` a pak sladí vzhled a upraví texty.

**Správa → Import a export → WordPress:** nahrajte export z WordPressu (Nástroje → Export, soubor XML). Import převede
příspěvky na novinky, stránky volitelně **rovnou do builderu**, stáhne obrázky do Médií a založí přesměrování ze starých
adres. Import jde spustit znovu – co už převedl, přeskočí.

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

Přenést jde i **jedna stránka**: **Stránky → Exportovat** stáhne JSON se stavbou stránky, sdílenými třídami, které používá,
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

**Obnova webu po ztrátě hostingu:** nainstalujte Kaletu na nový hosting se stejnou předponou tabulek, nahrajte poslední
zálohu databáze přes FTP do `storage/zalohy/`, zkopírujte zpět složku `media/` z kopie na FTPS nebo S3 a pak v Nastavení →
Zálohy a aktualizace u té zálohy klikněte na **Obnovit** a přihlaste se účty ze zálohy.

**Import a export → Export celého webu** vytvoří otevřený balík s obsahem a médii – pro přesun (viz výše) nebo abyste
měli obsah i mimo Kaletu. Poptávky, odběratelé a účty se neexportují.

Veřejné čtecí API z verzí 1.x (`/api/novinky`) ve 2.0 skončilo. Pro novinky použijte JSON Feed (`/feed.json`) nebo RSS,
pro práci s webem napojení na Clauda (MCP), pro události webhooky a pro odnesení obsahu export webu.
