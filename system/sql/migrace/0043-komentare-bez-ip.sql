-- Komentáře dřív ukládaly celou IP adresu pisatele. Nově se ukládá jen otisk (jako u hlasování a antispamu);
-- dosud uložené adresy se mažou - otisk z nich zpětně spočítat nejde a adresy samy v databázi nemají co dělat.
UPDATE rs_komentare SET od_ip = '' WHERE od_ip LIKE '%.%' OR od_ip LIKE '%:%';
