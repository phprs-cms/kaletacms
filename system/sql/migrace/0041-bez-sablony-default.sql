-- Vestavěná šablona „default“ (třísloupcová) se ruší; web, který ji používal, přechází na Classic Newspaper.
-- Rozvržení (rs_config.rozvrzeni) zůstává - Classic Newspaper skládá oba postranní sloupce bloků vpravo.
-- Vlastní šablony (kopie pod jiným názvem) se migrace netýká.
UPDATE rs_config SET hodnota = 'classic-newspaper' WHERE promenna = 'layout' AND hodnota = 'default';
