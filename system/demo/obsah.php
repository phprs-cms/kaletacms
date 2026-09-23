<?php
/**
 * Ukázkový obsah: smyšlený deník Pražský kurýr (cs), The Prague Courier (en), Prager Kurier (de).
 * Nahrává ho Core\Demo – z instalátoru, nebo z Nastavení → Základní → Ukázkový obsah. Slovenské weby dostanou češtinu.
 *
 * Vše je vymyšlené: žádné skutečné osoby, firmy ani citáty. Ilustrace v img/ vznikly pro tento projekt.
 *
 * 'clanky'  = údaje společné všem jazykům: rubrika (klíč z 'rubriky'), obrazek (soubor z img/, '' = bez obrázku),
 *             autor ('' = ten, kdo obsah nahrál; jinak jméno hosta), pred_hodinami (stáří článku ve chvíli nahrání),
 *             pripnout (otvírák hlavní stránky – právě jeden)
 * 'cs' ...  = texty: rubriky [klíč => název] a clanky [klíč => titulek, uvod, text, případně shrnuti (bod na řádek)]
 *
 * Úplnost a shodu klíčů mezi jazyky hlídá tools/testy.php.
 */

return [
    'clanky' => [
        'nabrezi'  => ['rubrika' => 'domov',   'obrazek' => 'foto2.jpg', 'autor' => '',              'pred_hodinami' => 3,   'pripnout' => true],
        'divadlo'  => ['rubrika' => 'kultura', 'obrazek' => 'foto6.jpg', 'autor' => '',              'pred_hodinami' => 8,   'pripnout' => false],
        'ledovce'  => ['rubrika' => 'veda',    'obrazek' => 'foto4.jpg', 'autor' => '',              'pred_hodinami' => 27,  'pripnout' => false],
        'obce'     => ['rubrika' => 'svet',    'obrazek' => '',          'autor' => '',              'pred_hodinami' => 49,  'pripnout' => false],
        'summit'   => ['rubrika' => 'svet',    'obrazek' => 'foto7.jpg', 'autor' => 'Petr Novotný',  'pred_hodinami' => 56,  'pripnout' => false],
        'hdp'      => ['rubrika' => 'nazory',  'obrazek' => 'foto9.jpg', 'autor' => 'Lucie Marková', 'pred_hodinami' => 80,  'pripnout' => false],
        'pobrezi'  => ['rubrika' => 'svet',    'obrazek' => 'foto5.jpg', 'autor' => '',              'pred_hodinami' => 104, 'pripnout' => false],
        'baterie'  => ['rubrika' => 'veda',    'obrazek' => 'foto1.jpg', 'autor' => 'Petr Novotný',  'pred_hodinami' => 130, 'pripnout' => false],
        'najmy'    => ['rubrika' => 'domov',   'obrazek' => 'foto8.jpg', 'autor' => '',              'pred_hodinami' => 170, 'pripnout' => false],
        'festival' => ['rubrika' => 'kultura', 'obrazek' => 'foto3.jpg', 'autor' => '',              'pred_hodinami' => 220, 'pripnout' => false],
    ],

    /* ------------------------------------------------------------------ čeština ------------------------------------------------------------------ */
    'cs' => [
        'rubriky' => ['domov' => 'Domov', 'svet' => 'Svět', 'kultura' => 'Kultura', 'veda' => 'Věda a technika', 'nazory' => 'Názory'],
        'clanky' => [
            'nabrezi' => [
                'titulek' => 'Město chystá největší proměnu nábřeží za sto let',
                'uvod' => '<p>Radnice představila plán, který má z rušné čtyřproudé silnice udělat promenádu se stromořadím.</p>',
                'text' => '<p>Tam, kde dnes projede čtyřicet tisíc aut denně, by za pět let měly stát lavičky, stromy a kavárny s výhledem na řeku. Návrh počítá se zúžením silnice na dva pruhy, novou tramvajovou zastávkou a třemi sty vysazenými stromy. Auta, která nábřežím jen projíždějí, má převzít vnější okruh.</p>'
                    . '<p>Podle urbanistů je klíčové, aby se plánování neodehrávalo za zavřenými dveřmi. „Lidé musí vidět, že jejich hlas má váhu,“ řekla na veřejném představení jedna z autorek studie. Radnice slibuje, že všechny připomínky vypořádá do konce roku a výsledky zveřejní.</p>'
                    . '<h2>Co bude dál</h2>'
                    . '<p>Zastupitelé budou o návrhu hlasovat v listopadu. Pokud projde, první stavební práce začnou na jaře a potrvají zhruba tři roky. Opozice už avizovala, že bude požadovat nezávislý posudek dopadů na dopravu v okolních čtvrtích.</p>'
                    . '<p>Obchodníci z nábřeží se zatím dělí na dva tábory: jedni se bojí úbytku zákazníků přijíždějících autem, druzí počítají s tím, že pěší promenáda přivede lidi, kteří se zastaví. Ať už hlasování dopadne jakkoli, o podobě veřejného prostoru se ve městě bude mluvit ještě dlouho.</p>',
            ],
            'divadlo' => [
                'titulek' => 'Divadlo roku: malá scéna z Ostravy porazila kamenné domy',
                'uvod' => '<p>Porota ocenila odvahu dramaturgie i práci s neherci. Vítězná inscenace se bude hrát i v Praze.</p>',
                'text' => '<p>Sál pro osmdesát diváků, rozpočet menší než výprava jedné operní premiéry – a přesto hlavní cena. Ostravské Divadlo Pod Haldou zvítězilo v anketě kritiků s inscenací Směna, ve které vedle profesionálních herců vystupují bývalí horníci.</p>'
                    . '<p>„Nechtěli jsme hrát o lidech z regionu, chtěli jsme hrát s nimi,“ popsala režisérka při přebírání ceny. Porota v odůvodnění vyzdvihla, že soubor dokázal z místního příběhu udělat téma, kterému rozumí diváci kdekoli.</p>'
                    . '<h2>Kde inscenaci uvidíte</h2>'
                    . '<p>Směna se v Ostravě hraje do konce sezony a je vyprodaná na dva měsíce dopředu. Na jaře se soubor vydá na turné: čekají ho tři večery v Praze, po jednom v Brně a v Plzni. Vstupenky půjdou do prodeje začátkem příštího měsíce.</p>'
                    . '<p>Druhé místo získala velká činoherní scéna z Prahy, třetí loutkové divadlo z Liberce. Cenu za celoživotní přínos letos porota neudělila.</p>',
            ],
            'ledovce' => [
                'titulek' => 'Ledovce tají rychleji, než předpovídaly modely',
                'uvod' => '<p>Nová měření ze satelitů ukazují, že úbytek ledu v Grónsku se za poslední dekádu zdvojnásobil.</p>',
                'shrnuti' => "Úbytek grónského ledu se za deset let zdvojnásobil.\nModely tempo tání podcenily zhruba o třetinu.\nNejrychleji mizí led na západním pobřeží.",
                'text' => '<p>Mezinárodní tým glaciologů porovnal dvacet let satelitních měření s tím, co pro stejné období předpovídaly klimatické modely. Výsledek je nepříjemný: skutečný úbytek ledu je zhruba o třetinu větší, než se čekalo, a v posledních letech se dál zrychluje.</p>'
                    . '<p>Hlavní příčinou není jen teplejší vzduch. Do fjordů proudí teplejší mořská voda, která ledovce podemílá zespodu – a právě tento jev modely dosud zachycovaly jen zjednodušeně.</p>'
                    . '<h2>Co to znamená pro pobřeží</h2>'
                    . '<p>Rychlejší tání posouvá odhady růstu hladiny oceánů. Autoři studie upozorňují, že pobřežní města by měla při plánování ochrany počítat spíše s horní hranicí dosavadních scénářů než s jejich středem.</p>'
                    . '<p>Vědci zároveň zdůrazňují, že vývoj není předem daný. Tempo tání ve druhé polovině století bude záviset hlavně na tom, kolik skleníkových plynů lidstvo vypustí v příštích dvaceti letech.</p>',
            ],
            'obce' => [
                'titulek' => 'Pobřeží po bouři: obce sčítají škody a chystají se na zimu',
                'uvod' => '<p>Provizorní opravy běží, obce ale varují: další sezona bouří začne za pár týdnů.</p>',
                'text' => '<p>Dva dny po bouři, která odnesla část pobřežní silnice, mají obce první odhady škod. Jen v nejhůře zasaženém okrese jdou do desítek milionů: poškozené jsou hráze, přístavní mola i kanalizace.</p>'
                    . '<p>Silničáři zatím navezli kamenný zához a otevřeli provizorní objížďku vnitrozemím. Autobusy po ní jezdí o čtyřicet minut déle a děti ze dvou vesnic se do školy dostanou jen s přestupem.</p>'
                    . '<h2>Závod s časem</h2>'
                    . '<p>Starostové žádají vládu o mimořádnou pomoc a hlavně o rychlost. Hlavní sezona podzimních bouří teprve přijde a narušené hráze by další podobný nápor nemusely vydržet.</p>'
                    . '<p>Krajský úřad slíbil, že o penězích na nejnutnější opravy rozhodne do konce týdne. Trvalé řešení – posunutí silnice dál od moře – je ale otázkou let, ne měsíců.</p>',
            ],
            'summit' => [
                'titulek' => 'Summit skončil bez dohody, jednání se přesouvají na jaro',
                'uvod' => '<p>Delegace se po třech dnech rozešly s prázdnýma rukama. Spor se vede hlavně o peníze.</p>',
                'text' => '<p>Ani noční prodloužení nepomohlo. Klimatický summit, od kterého si pořadatelé slibovali závazný plán snižování emisí, skončil jen krátkým společným prohlášením o tom, že rozhovory budou pokračovat.</p>'
                    . '<p>Jednání uvázla na otázce, kdo zaplatí přechod chudších zemí na čistou energii. Bohatší státy nabízely půjčky, rozvojové země trvaly na přímé podpoře. Kompromisní návrh předsednictví neprošel o několik hlasů.</p>'
                    . '<h2>Další pokus na jaře</h2>'
                    . '<p>Delegace se znovu sejdou v dubnu. Do té doby mají pracovní skupiny připravit nový návrh financování, který by byl přijatelný pro obě strany.</p>'
                    . '<p>Pozorovatelé jsou opatrní. „Odklad je lepší než špatná dohoda, ale času ubývá,“ shrnul náladu v kuloárech jeden z vyjednavačů, který si nepřál být jmenován.</p>',
            ],
            'hdp' => [
                'titulek' => 'Proč bychom měli přestat měřit úspěch růstem HDP',
                'uvod' => '<p>Ekonomika není závod. Komentář o tom, co čísla neukazují a proč na tom záleží.</p>',
                'text' => '<p>Každé čtvrtletí čekáme na jedno číslo, jako by šlo o výsledek zápasu. Vzrostl hrubý domácí produkt o procento? Výborně. Klesl? Průšvih. Jenže HDP měří, kolik peněz ekonomikou proteklo – ne to, jak se v ní lidem žije.</p>'
                    . '<p>Dopravní zácpa HDP zvyšuje, protože se při ní pálí benzin. Babička, která hlídá vnoučata, ho nezvyšuje vůbec. Vykácený les se v účtech objeví jako zisk, a teprve povodeň o pár let později jako náklad.</p>'
                    . '<h2>Co měřit místo toho</h2>'
                    . '<p>Nikdo rozumný nenavrhuje HDP zahodit. Stačilo by přestat ho brát jako jediné vysvědčení. Vedle něj potřebujeme stejně viditelné údaje o zdraví, dostupnosti bydlení, kvalitě vzduchu nebo o tom, kolik volného času lidem zbývá.</p>'
                    . '<p>Dokud budou politici skládat účty z jediného čísla, budou se honit právě za ním. Změňme vysvědčení, a změní se i to, o co se hraje.</p>',
            ],
            'pobrezi' => [
                'titulek' => 'Pobřeží po bouři: fotoreportáž z míst, kde moře vzalo silnici',
                'uvod' => '<p>Vlny až osm metrů vysoké odnesly část pobřežní komunikace. Obyvatelé počítají škody.</p>',
                'text' => '<p>Ráno po bouři je na pobřeží ticho, jen moře je pořád hnědé od písku. Silnice, po které ještě předevčírem jezdil školní autobus, končí po sto metrech ulomeným okrajem asfaltu. Pod ním je pět metrů prázdna.</p>'
                    . '<p>Meteorologové naměřili vlny vysoké až osm metrů a nárazy větru přes sto třicet kilometrů v hodině. Evakuace proběhla včas, nikdo nebyl zraněn. Bez proudu zůstalo na půl dne zhruba dva tisíce domácností.</p>'
                    . '<h2>Lidé, kteří zůstali</h2>'
                    . '<p>Majitelka penzionu u přístavu vynáší s rodinou ze sklepa nasáklé matrace. „Moře tu bylo vždycky. Jen dřív zůstávalo tam, kde má být,“ říká a ukazuje na čáru, kterou voda nechala na zdi v úrovni pasu.</p>'
                    . '<p>Rybáři přišli o dva čluny a část mola. Přesto chtějí do týdne znovu vyplout – sezona je krátká a jiná práce tu není.</p>',
            ],
            'baterie' => [
                'titulek' => 'Čeští vědci vyvinuli baterii, která vydrží tisíce cyklů',
                'uvod' => '<p>Prototyp z brněnské laboratoře slibuje levnější ukládání energie ze solárních elektráren.</p>',
                'text' => '<p>Baterie velká jako lednice, která po osmi tisících nabitích ztratí jen desetinu kapacity. Brněnský výzkumný tým představil prototyp úložiště, které místo lithia používá sodík – prvek, kterého je všude dost a je levný.</p>'
                    . '<p>Sodíkové baterie nejsou novinka, dosud je ale brzdila krátká životnost. Výzkumníci ji prodloužili novým složením elektrolytu, které omezuje rozpad elektrod. Na mobil nebo elektromobil je článek příliš těžký, pro domácí a obecní úložiště to ale nevadí.</p>'
                    . '<h2>Kdy se dostane na trh</h2>'
                    . '<p>Od prototypu k výrobku vede dlouhá cesta. Tým teď hledá průmyslového partnera pro zkušební provoz: první větší úložiště by mělo příští rok stát u solární elektrárny na jižní Moravě.</p>'
                    . '<p>Pokud se výsledky z laboratoře potvrdí i venku, mohla by cena uložené kilowatthodiny klesnout zhruba na polovinu dnešní úrovně.</p>',
            ],
            'najmy' => [
                'titulek' => 'Nájmy ve velkých městech rostou nejrychleji od roku 2008',
                'uvod' => '<p>Průměrné nájemné meziročně stouplo o jedenáct procent. Nejhůře jsou na tom mladé rodiny.</p>',
                'text' => '<p>Byt dva plus jedna v krajském městě stojí nájemníka v průměru o jedenáct procent víc než před rokem. Je to nejrychlejší růst za posledních sedmnáct let a mzdy mu nestačí ani zdaleka.</p>'
                    . '<p>Důvodů je víc: drahé hypotéky drží v nájmech lidi, kteří by si jinak koupili vlastní byt, nových bytů se staví málo a část jich končí jako krátkodobé ubytování pro turisty.</p>'
                    . '<h2>Koho to zasáhne nejvíc</h2>'
                    . '<p>Nejhůře dopadají mladé rodiny a lidé, kteří se stěhují za prací. Podle průzkumu dává třetina nájemníků ve velkých městech za bydlení víc než čtyřicet procent příjmu – hranici, od které odborníci mluví o nadměrné zátěži.</p>'
                    . '<p>Města slibují rychlejší povolování staveb a vlastní nájemní bydlení. I kdyby se sliby naplnily, první byty z těchto programů budou k nastěhování nejdřív za tři až čtyři roky.</p>',
            ],
            'festival' => [
                'titulek' => 'Festival ohlásil program: přijedou hvězdy i objevy z Islandu',
                'uvod' => '<p>Dramaturgové letos vsadili na severskou scénu. Předprodej začíná v pondělí.</p>',
                'text' => '<p>Čtyři dny, šest scén a přes osmdesát vystoupení. Festival Letní scéna zveřejnil kompletní program a je z něj zřejmé, kam se dramaturgové letos dívali: na sever.</p>'
                    . '<p>Hlavní večery budou patřit zavedeným jménům evropské klubové scény, odpoledne ale dostanou prostor kapely, které u nás ještě nehrály. Hned pět jich přijede z Islandu – od komorního folku po elektroniku.</p>'
                    . '<h2>Vstupenky a doprava</h2>'
                    . '<p>Předprodej začíná v pondělí v deset hodin. První vlna čtyřdenních vstupenek je cenově zvýhodněná a loni zmizela za jediné odpoledne. Děti do dvanácti let mají vstup zdarma.</p>'
                    . '<p>Pořadatelé znovu vypraví zvláštní vlaky z Prahy a z Brna a rozšíří stanové městečko. Novinkou je tichá zóna pro rodiny s dětmi.</p>',
            ],
        ],
    ],

    /* ------------------------------------------------------------------ English ------------------------------------------------------------------ */
    'en' => [
        'rubriky' => ['domov' => 'Home news', 'svet' => 'World', 'kultura' => 'Culture', 'veda' => 'Science & Tech', 'nazory' => 'Opinion'],
        'clanky' => [
            'nabrezi' => [
                'titulek' => 'City plans the biggest riverfront makeover in a century',
                'uvod' => '<p>The council has unveiled a plan to turn a busy four-lane road into a tree-lined promenade.</p>',
                'text' => '<p>Where forty thousand cars pass every day, there should be benches, trees and cafés with a river view within five years. The proposal narrows the road to two lanes, adds a new tram stop and plants three hundred trees. Through traffic is to be diverted to the outer ring road.</p>'
                    . '<p>According to urban planners, the key is that planning does not happen behind closed doors. “People need to see that their voice carries weight,” one of the study’s authors said at the public presentation. The council promises to settle all comments by the end of the year and publish the results.</p>'
                    . '<h2>What happens next</h2>'
                    . '<p>Councillors will vote on the proposal in November. If it passes, construction will begin in spring and take about three years. The opposition has already said it will demand an independent assessment of the impact on traffic in neighbouring districts.</p>'
                    . '<p>Riverfront shopkeepers are split: some fear losing customers who arrive by car, others expect a promenade to bring people who actually stop. Whatever the outcome of the vote, the city will be talking about its public space for a long time.</p>',
            ],
            'divadlo' => [
                'titulek' => 'Theatre of the year: a small Ostrava stage beats the big houses',
                'uvod' => '<p>The jury praised bold programming and work with non-actors. The winning production will also run in Prague.</p>',
                'text' => '<p>A hall for eighty people, a budget smaller than the set of a single opera premiere – and still the top prize. Ostrava’s Pod Haldou Theatre won the critics’ poll with The Shift, a production in which former miners perform alongside professional actors.</p>'
                    . '<p>“We did not want to make a play about people from the region, we wanted to make it with them,” the director said as she accepted the award. The jury noted that the company turned a local story into something audiences anywhere can relate to.</p>'
                    . '<h2>Where to see it</h2>'
                    . '<p>The Shift runs in Ostrava until the end of the season and is sold out two months ahead. In spring the company goes on tour: three nights in Prague, one each in Brno and Plzeň. Tickets go on sale early next month.</p>'
                    . '<p>Second place went to a large Prague playhouse, third to a puppet theatre from Liberec. No lifetime achievement award was given this year.</p>',
            ],
            'ledovce' => [
                'titulek' => 'Glaciers are melting faster than models predicted',
                'uvod' => '<p>New satellite measurements show that ice loss in Greenland has doubled over the past decade.</p>',
                'shrnuti' => "Greenland’s ice loss has doubled in ten years.\nModels underestimated the pace of melting by about a third.\nIce is disappearing fastest on the west coast.",
                'text' => '<p>An international team of glaciologists compared twenty years of satellite data with what climate models had predicted for the same period. The result is uncomfortable: actual ice loss is about a third greater than expected, and it has kept accelerating in recent years.</p>'
                    . '<p>Warmer air is only part of the story. Warmer sea water is flowing into the fjords and undercutting the glaciers from below – a process that models have so far captured only in simplified form.</p>'
                    . '<h2>What it means for the coast</h2>'
                    . '<p>Faster melting shifts the estimates of sea level rise. The authors say coastal cities planning their defences should work with the upper end of current scenarios rather than the middle.</p>'
                    . '<p>The scientists also stress that nothing is settled in advance. The pace of melting in the second half of the century will depend mainly on how much greenhouse gas humanity emits over the next twenty years.</p>',
            ],
            'obce' => [
                'titulek' => 'After the storm: villages count the cost and prepare for winter',
                'uvod' => '<p>Temporary repairs are under way, but councils warn the next storm season is only weeks away.</p>',
                'text' => '<p>Two days after the storm that washed away part of the coastal road, local councils have their first damage estimates. In the worst-hit district alone they run into tens of millions: sea walls, harbour piers and sewers are all damaged.</p>'
                    . '<p>Road crews have brought in rock fill and opened a temporary inland diversion. Buses now take forty minutes longer, and children from two villages can only reach school by changing on the way.</p>'
                    . '<h2>A race against time</h2>'
                    . '<p>Mayors are asking the government for emergency aid – and above all for speed. The main season of autumn storms is still to come, and the weakened sea walls might not withstand another onslaught.</p>'
                    . '<p>The regional authority has promised to decide on money for the most urgent repairs by the end of the week. A permanent fix – moving the road further from the sea – is a matter of years, not months.</p>',
            ],
            'summit' => [
                'titulek' => 'Summit ends without a deal, talks move to spring',
                'uvod' => '<p>After three days the delegations went home empty-handed. The dispute is mostly about money.</p>',
                'text' => '<p>Not even an overnight extension helped. The climate summit, which organisers hoped would deliver a binding plan to cut emissions, ended with nothing more than a short joint statement that talks will continue.</p>'
                    . '<p>Negotiations stalled over who should pay for poorer countries’ switch to clean energy. Richer states offered loans, developing countries insisted on direct support. The chair’s compromise proposal fell a few votes short.</p>'
                    . '<h2>Another attempt in spring</h2>'
                    . '<p>The delegations will meet again in April. Until then, working groups are to draft a new funding proposal acceptable to both sides.</p>'
                    . '<p>Observers are cautious. “A delay is better than a bad deal, but time is running out,” said one negotiator, who asked not to be named, summing up the mood in the corridors.</p>',
            ],
            'hdp' => [
                'titulek' => 'Why we should stop measuring success by GDP growth',
                'uvod' => '<p>The economy is not a race. A comment on what the numbers hide and why it matters.</p>',
                'text' => '<p>Every quarter we wait for one number as if it were a match result. GDP up by one per cent? Excellent. Down? Disaster. But GDP measures how much money flowed through the economy – not how people actually live in it.</p>'
                    . '<p>A traffic jam raises GDP because it burns petrol. A grandmother looking after her grandchildren does not raise it at all. A forest that has been cut down shows up as profit, and only the flood a few years later shows up as a cost.</p>'
                    . '<h2>What to measure instead</h2>'
                    . '<p>Nobody sensible suggests throwing GDP away. It would be enough to stop treating it as the only school report. Next to it we need equally visible figures on health, housing affordability, air quality or how much free time people have left.</p>'
                    . '<p>As long as politicians are judged by a single number, that is the number they will chase. Change the report card, and the game changes with it.</p>',
            ],
            'pobrezi' => [
                'titulek' => 'After the storm: a photo report from where the sea took the road',
                'uvod' => '<p>Waves up to eight metres high washed away part of the coastal road. Residents are counting the damage.</p>',
                'text' => '<p>The morning after the storm the coast is quiet, only the sea is still brown with sand. The road the school bus used the day before yesterday ends after a hundred metres in a broken edge of asphalt. Below it, five metres of nothing.</p>'
                    . '<p>Meteorologists recorded waves up to eight metres high and gusts of more than a hundred and thirty kilometres an hour. The evacuation came in time and nobody was hurt. About two thousand households were without power for half a day.</p>'
                    . '<h2>The people who stayed</h2>'
                    . '<p>The owner of a guest house by the harbour is carrying soaked mattresses out of the cellar with her family. “The sea has always been here. It just used to stay where it belongs,” she says, pointing at the waist-high line the water left on the wall.</p>'
                    . '<p>The fishermen lost two boats and part of the pier. Even so, they want to be back at sea within a week – the season is short and there is no other work here.</p>',
            ],
            'baterie' => [
                'titulek' => 'Czech scientists build a battery that lasts thousands of cycles',
                'uvod' => '<p>A prototype from a Brno lab promises cheaper storage for solar power.</p>',
                'text' => '<p>A battery the size of a fridge that loses only a tenth of its capacity after eight thousand charges. A research team in Brno has presented a storage prototype that uses sodium instead of lithium – an element that is cheap and available everywhere.</p>'
                    . '<p>Sodium batteries are not new, but a short lifespan has held them back. The researchers extended it with a new electrolyte that slows the decay of the electrodes. The cell is too heavy for a phone or a car, but that does not matter for home and municipal storage.</p>'
                    . '<h2>When it will reach the market</h2>'
                    . '<p>It is a long way from prototype to product. The team is now looking for an industrial partner for a trial run: the first larger unit should be installed next year at a solar plant in South Moravia.</p>'
                    . '<p>If the lab results hold up outdoors, the price of a stored kilowatt-hour could fall to roughly half of today’s level.</p>',
            ],
            'najmy' => [
                'titulek' => 'Rents in big cities are rising at the fastest pace since 2008',
                'uvod' => '<p>Average rent is up eleven per cent year on year. Young families are hit hardest.</p>',
                'text' => '<p>A two-bedroom flat in a regional capital costs tenants on average eleven per cent more than a year ago. It is the fastest increase in seventeen years, and wages are nowhere near keeping up.</p>'
                    . '<p>There are several reasons: expensive mortgages keep people renting who would otherwise buy, too few new flats are being built, and some of those end up as short-term tourist lets.</p>'
                    . '<h2>Who is hit hardest</h2>'
                    . '<p>Young families and people moving for work fare worst. According to a survey, a third of tenants in big cities spend more than forty per cent of their income on housing – the threshold at which experts speak of an excessive burden.</p>'
                    . '<p>Cities promise faster building permits and municipal rental housing of their own. Even if the promises are kept, the first flats from these schemes will be ready in three to four years at the earliest.</p>',
            ],
            'festival' => [
                'titulek' => 'Festival announces line-up: big names and discoveries from Iceland',
                'uvod' => '<p>This year the programmers bet on the Nordic scene. Advance sales start on Monday.</p>',
                'text' => '<p>Four days, six stages and more than eighty performances. The Summer Stage festival has published its full programme, and it is clear where the programmers were looking this year: north.</p>'
                    . '<p>The main evenings belong to established names of the European club scene, but the afternoons make room for bands that have never played here before. Five of them are coming from Iceland – from intimate folk to electronica.</p>'
                    . '<h2>Tickets and travel</h2>'
                    . '<p>Advance sales start on Monday at ten. The first wave of four-day passes is discounted and last year it was gone in a single afternoon. Children under twelve get in free.</p>'
                    . '<p>The organisers will again run special trains from Prague and Brno and enlarge the campsite. New this year is a quiet zone for families with children.</p>',
            ],
        ],
    ],

    /* ------------------------------------------------------------------ Deutsch ------------------------------------------------------------------ */
    'de' => [
        'rubriky' => ['domov' => 'Inland', 'svet' => 'Welt', 'kultura' => 'Kultur', 'veda' => 'Wissen & Technik', 'nazory' => 'Meinung'],
        'clanky' => [
            'nabrezi' => [
                'titulek' => 'Stadt plant den größten Umbau des Flussufers seit hundert Jahren',
                'uvod' => '<p>Das Rathaus hat einen Plan vorgestellt, der aus der vierspurigen Straße eine Promenade mit Baumallee machen soll.</p>',
                'text' => '<p>Wo heute täglich vierzigtausend Autos fahren, sollen in fünf Jahren Bänke, Bäume und Cafés mit Blick auf den Fluss stehen. Der Entwurf sieht vor, die Straße auf zwei Spuren zu verengen, eine neue Straßenbahnhaltestelle zu bauen und dreihundert Bäume zu pflanzen. Den Durchgangsverkehr soll der äußere Ring übernehmen.</p>'
                    . '<p>Stadtplanern zufolge ist entscheidend, dass nicht hinter verschlossenen Türen geplant wird. „Die Menschen müssen sehen, dass ihre Stimme Gewicht hat“, sagte eine Autorin der Studie bei der öffentlichen Vorstellung. Das Rathaus verspricht, alle Einwände bis Jahresende zu klären und die Ergebnisse zu veröffentlichen.</p>'
                    . '<h2>Wie es weitergeht</h2>'
                    . '<p>Der Stadtrat stimmt im November über den Entwurf ab. Bei einer Zustimmung beginnen die Bauarbeiten im Frühjahr und dauern rund drei Jahre. Die Opposition hat bereits angekündigt, ein unabhängiges Gutachten zu den Folgen für den Verkehr in den Nachbarvierteln zu verlangen.</p>'
                    . '<p>Die Händler am Ufer sind gespalten: Die einen fürchten um Kunden, die mit dem Auto kommen, die anderen rechnen damit, dass eine Promenade Menschen bringt, die auch stehen bleiben. Wie die Abstimmung auch ausgeht – über den öffentlichen Raum wird die Stadt noch lange reden.</p>',
            ],
            'divadlo' => [
                'titulek' => 'Theater des Jahres: Kleine Bühne aus Ostrava schlägt die großen Häuser',
                'uvod' => '<p>Die Jury lobte den Mut der Dramaturgie und die Arbeit mit Laien. Die Siegerinszenierung ist bald auch in Prag zu sehen.</p>',
                'text' => '<p>Ein Saal für achtzig Zuschauer, ein Budget kleiner als die Ausstattung einer einzigen Opernpremiere – und trotzdem der Hauptpreis. Das Theater Pod Haldou aus Ostrava gewann die Kritikerumfrage mit der Inszenierung „Die Schicht“, in der ehemalige Bergleute neben Berufsschauspielern auf der Bühne stehen.</p>'
                    . '<p>„Wir wollten nicht über die Menschen aus der Region spielen, sondern mit ihnen“, sagte die Regisseurin bei der Preisverleihung. Die Jury hob hervor, dass das Ensemble aus einer lokalen Geschichte ein Thema gemacht hat, das Zuschauer überall verstehen.</p>'
                    . '<h2>Wo die Inszenierung zu sehen ist</h2>'
                    . '<p>„Die Schicht“ läuft in Ostrava bis zum Ende der Spielzeit und ist zwei Monate im Voraus ausverkauft. Im Frühjahr geht das Ensemble auf Tournee: drei Abende in Prag, je einer in Brünn und Pilsen. Der Kartenverkauf beginnt Anfang nächsten Monats.</p>'
                    . '<p>Den zweiten Platz belegte ein großes Prager Schauspielhaus, den dritten ein Puppentheater aus Liberec. Ein Preis für das Lebenswerk wurde in diesem Jahr nicht vergeben.</p>',
            ],
            'ledovce' => [
                'titulek' => 'Gletscher schmelzen schneller, als die Modelle vorhergesagt haben',
                'uvod' => '<p>Neue Satellitenmessungen zeigen: Der Eisverlust in Grönland hat sich im letzten Jahrzehnt verdoppelt.</p>',
                'shrnuti' => "Grönlands Eisverlust hat sich in zehn Jahren verdoppelt.\nDie Modelle haben das Tempo um etwa ein Drittel unterschätzt.\nAm schnellsten schwindet das Eis an der Westküste.",
                'text' => '<p>Ein internationales Team von Glaziologen hat zwanzig Jahre Satellitendaten mit dem verglichen, was Klimamodelle für denselben Zeitraum vorhergesagt hatten. Das Ergebnis ist unbequem: Der tatsächliche Eisverlust ist etwa ein Drittel größer als erwartet und hat sich in den letzten Jahren weiter beschleunigt.</p>'
                    . '<p>Wärmere Luft ist nur ein Teil der Erklärung. In die Fjorde strömt wärmeres Meerwasser, das die Gletscher von unten aushöhlt – ein Vorgang, den die Modelle bisher nur vereinfacht abbilden.</p>'
                    . '<h2>Was das für die Küsten bedeutet</h2>'
                    . '<p>Das schnellere Schmelzen verschiebt die Schätzungen zum Anstieg des Meeresspiegels. Die Autoren raten Küstenstädten, beim Hochwasserschutz eher mit dem oberen Rand der bisherigen Szenarien zu planen als mit deren Mitte.</p>'
                    . '<p>Zugleich betonen die Forscher, dass nichts vorherbestimmt ist. Wie schnell das Eis in der zweiten Jahrhunderthälfte schmilzt, hängt vor allem davon ab, wie viele Treibhausgase die Menschheit in den nächsten zwanzig Jahren ausstößt.</p>',
            ],
            'obce' => [
                'titulek' => 'Nach dem Sturm: Gemeinden ziehen Bilanz und rüsten sich für den Winter',
                'uvod' => '<p>Die Notreparaturen laufen, doch die Gemeinden warnen: Die nächste Sturmsaison beginnt in wenigen Wochen.</p>',
                'text' => '<p>Zwei Tage nach dem Sturm, der einen Teil der Küstenstraße fortgerissen hat, liegen den Gemeinden erste Schadensschätzungen vor. Allein im am stärksten betroffenen Landkreis gehen sie in die zweistelligen Millionen: Beschädigt sind Deiche, Hafenmolen und die Kanalisation.</p>'
                    . '<p>Die Straßenmeisterei hat Steinschüttungen aufgebracht und eine provisorische Umleitung durch das Hinterland geöffnet. Busse brauchen darauf vierzig Minuten länger, und Kinder aus zwei Dörfern kommen nur noch mit Umsteigen zur Schule.</p>'
                    . '<h2>Wettlauf gegen die Zeit</h2>'
                    . '<p>Die Bürgermeister bitten die Regierung um Soforthilfe – und vor allem um Tempo. Die eigentliche Saison der Herbststürme steht noch bevor, und die angegriffenen Deiche würden einem weiteren Ansturm womöglich nicht standhalten.</p>'
                    . '<p>Die Regionalverwaltung hat zugesagt, bis Ende der Woche über Geld für die dringendsten Reparaturen zu entscheiden. Eine dauerhafte Lösung – die Verlegung der Straße weiter ins Land – ist allerdings eine Frage von Jahren, nicht von Monaten.</p>',
            ],
            'summit' => [
                'titulek' => 'Gipfel endet ohne Einigung, Gespräche auf das Frühjahr vertagt',
                'uvod' => '<p>Nach drei Tagen gingen die Delegationen mit leeren Händen auseinander. Gestritten wird vor allem ums Geld.</p>',
                'text' => '<p>Auch die nächtliche Verlängerung half nicht. Der Klimagipfel, von dem sich die Veranstalter einen verbindlichen Plan zur Senkung der Emissionen erhofft hatten, endete lediglich mit einer kurzen gemeinsamen Erklärung, dass die Gespräche fortgesetzt werden.</p>'
                    . '<p>Die Verhandlungen scheiterten an der Frage, wer den Umstieg ärmerer Länder auf saubere Energie bezahlt. Reichere Staaten boten Kredite an, Entwicklungsländer bestanden auf direkter Unterstützung. Der Kompromissvorschlag des Vorsitzes verfehlte die Mehrheit um wenige Stimmen.</p>'
                    . '<h2>Neuer Anlauf im Frühjahr</h2>'
                    . '<p>Im April kommen die Delegationen erneut zusammen. Bis dahin sollen Arbeitsgruppen einen neuen Finanzierungsvorschlag erarbeiten, der für beide Seiten annehmbar ist.</p>'
                    . '<p>Beobachter bleiben vorsichtig. „Ein Aufschub ist besser als ein schlechtes Abkommen, aber die Zeit läuft uns davon“, fasste ein Unterhändler, der nicht genannt werden wollte, die Stimmung auf den Fluren zusammen.</p>',
            ],
            'hdp' => [
                'titulek' => 'Warum wir Erfolg nicht länger am BIP-Wachstum messen sollten',
                'uvod' => '<p>Wirtschaft ist kein Wettrennen. Ein Kommentar darüber, was Zahlen nicht zeigen – und warum das zählt.</p>',
                'text' => '<p>Jedes Quartal warten wir auf eine Zahl, als ginge es um ein Spielergebnis. Das Bruttoinlandsprodukt ist um ein Prozent gewachsen? Hervorragend. Gesunken? Katastrophe. Doch das BIP misst, wie viel Geld durch die Wirtschaft geflossen ist – nicht, wie es sich in ihr lebt.</p>'
                    . '<p>Ein Stau erhöht das BIP, weil dabei Benzin verbrannt wird. Eine Großmutter, die ihre Enkel hütet, erhöht es gar nicht. Ein abgeholzter Wald taucht in der Bilanz als Gewinn auf, erst das Hochwasser ein paar Jahre später als Kosten.</p>'
                    . '<h2>Was wir stattdessen messen sollten</h2>'
                    . '<p>Kein vernünftiger Mensch will das BIP abschaffen. Es würde reichen, es nicht länger als einziges Zeugnis zu behandeln. Daneben brauchen wir ebenso sichtbare Angaben zu Gesundheit, bezahlbarem Wohnen, Luftqualität oder dazu, wie viel freie Zeit den Menschen bleibt.</p>'
                    . '<p>Solange Politiker an einer einzigen Zahl gemessen werden, jagen sie genau dieser Zahl hinterher. Ändern wir das Zeugnis, ändert sich auch das Spiel.</p>',
            ],
            'pobrezi' => [
                'titulek' => 'Nach dem Sturm: Fotoreportage von dort, wo das Meer die Straße nahm',
                'uvod' => '<p>Bis zu acht Meter hohe Wellen rissen einen Teil der Küstenstraße fort. Die Anwohner ziehen Bilanz.</p>',
                'text' => '<p>Am Morgen nach dem Sturm ist es still an der Küste, nur das Meer ist noch braun vom Sand. Die Straße, auf der vorgestern noch der Schulbus fuhr, endet nach hundert Metern an einer abgebrochenen Asphaltkante. Darunter: fünf Meter Leere.</p>'
                    . '<p>Meteorologen registrierten bis zu acht Meter hohe Wellen und Böen von mehr als hundertdreißig Kilometern pro Stunde. Die Evakuierung kam rechtzeitig, verletzt wurde niemand. Rund zweitausend Haushalte waren einen halben Tag lang ohne Strom.</p>'
                    . '<h2>Die Menschen, die geblieben sind</h2>'
                    . '<p>Die Besitzerin einer Pension am Hafen trägt mit ihrer Familie durchnässte Matratzen aus dem Keller. „Das Meer war immer da. Früher blieb es nur dort, wo es hingehört“, sagt sie und zeigt auf die hüfthohe Linie, die das Wasser an der Wand hinterlassen hat.</p>'
                    . '<p>Die Fischer haben zwei Boote und einen Teil der Mole verloren. Trotzdem wollen sie binnen einer Woche wieder hinausfahren – die Saison ist kurz, und andere Arbeit gibt es hier nicht.</p>',
            ],
            'baterie' => [
                'titulek' => 'Tschechische Forscher entwickeln Batterie für Tausende Ladezyklen',
                'uvod' => '<p>Ein Prototyp aus einem Brünner Labor verspricht günstigere Speicher für Solarstrom.</p>',
                'text' => '<p>Eine Batterie von der Größe eines Kühlschranks, die nach achttausend Ladungen nur ein Zehntel ihrer Kapazität verliert. Ein Brünner Forschungsteam hat den Prototyp eines Speichers vorgestellt, der statt Lithium Natrium verwendet – ein Element, das billig und überall verfügbar ist.</p>'
                    . '<p>Natriumbatterien sind nicht neu, bisher bremste sie aber ihre kurze Lebensdauer. Die Forscher haben sie mit einem neuen Elektrolyten verlängert, der den Zerfall der Elektroden verlangsamt. Für Handy oder Elektroauto ist die Zelle zu schwer, bei Speichern für Häuser und Gemeinden spielt das keine Rolle.</p>'
                    . '<h2>Wann sie auf den Markt kommt</h2>'
                    . '<p>Vom Prototyp zum Produkt ist es ein weiter Weg. Das Team sucht nun einen Industriepartner für den Testbetrieb: Der erste größere Speicher soll nächstes Jahr an einem Solarpark in Südmähren stehen.</p>'
                    . '<p>Bestätigen sich die Laborergebnisse auch im Freien, könnte der Preis einer gespeicherten Kilowattstunde auf etwa die Hälfte des heutigen Niveaus sinken.</p>',
            ],
            'najmy' => [
                'titulek' => 'Mieten in Großstädten steigen so schnell wie seit 2008 nicht mehr',
                'uvod' => '<p>Die Durchschnittsmiete liegt elf Prozent über dem Vorjahr. Am härtesten trifft es junge Familien.</p>',
                'text' => '<p>Eine Dreizimmerwohnung in einer Regionalhauptstadt kostet Mieter im Schnitt elf Prozent mehr als vor einem Jahr. Das ist der schnellste Anstieg seit siebzehn Jahren, und die Löhne halten bei Weitem nicht mit.</p>'
                    . '<p>Die Gründe sind vielfältig: Teure Hypotheken halten Menschen in Mietwohnungen, die sonst kaufen würden, es wird zu wenig neu gebaut, und ein Teil der neuen Wohnungen endet als Ferienunterkunft für Touristen.</p>'
                    . '<h2>Wen es am stärksten trifft</h2>'
                    . '<p>Am schlechtesten ergeht es jungen Familien und Menschen, die der Arbeit wegen umziehen. Laut einer Umfrage gibt ein Drittel der Mieter in Großstädten mehr als vierzig Prozent des Einkommens fürs Wohnen aus – die Schwelle, ab der Fachleute von einer übermäßigen Belastung sprechen.</p>'
                    . '<p>Die Städte versprechen schnellere Baugenehmigungen und eigene Mietwohnungen. Selbst wenn sie Wort halten, sind die ersten Wohnungen aus diesen Programmen frühestens in drei bis vier Jahren bezugsfertig.</p>',
            ],
            'festival' => [
                'titulek' => 'Festival stellt Programm vor: Stars und Entdeckungen aus Island',
                'uvod' => '<p>Die Programmmacher setzen dieses Jahr auf die nordische Szene. Der Vorverkauf beginnt am Montag.</p>',
                'text' => '<p>Vier Tage, sechs Bühnen und mehr als achtzig Auftritte. Das Festival Sommerbühne hat sein vollständiges Programm veröffentlicht, und es ist klar, wohin die Programmmacher dieses Jahr geschaut haben: nach Norden.</p>'
                    . '<p>Die Hauptabende gehören etablierten Namen der europäischen Clubszene, die Nachmittage aber Bands, die hierzulande noch nie gespielt haben. Gleich fünf kommen aus Island – von leisem Folk bis Elektronik.</p>'
                    . '<h2>Karten und Anreise</h2>'
                    . '<p>Der Vorverkauf beginnt am Montag um zehn Uhr. Die erste Welle der Viertagespässe ist vergünstigt und war im vergangenen Jahr an einem einzigen Nachmittag vergriffen. Kinder bis zwölf Jahre haben freien Eintritt.</p>'
                    . '<p>Die Veranstalter setzen erneut Sonderzüge aus Prag und Brünn ein und vergrößern den Zeltplatz. Neu ist eine Ruhezone für Familien mit Kindern.</p>',
            ],
        ],
    ],
];
