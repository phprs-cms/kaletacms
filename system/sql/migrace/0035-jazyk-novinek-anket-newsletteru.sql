-- Jazykové verze: novinky, ankety, odběratelé a vydání newsletteru ('' = výchozí jazyk webu).
ALTER TABLE rs_news ADD COLUMN jazyk CHAR(2) NOT NULL DEFAULT '' AFTER datum, ADD KEY ix_news_jazyk (jazyk, datum);
ALTER TABLE rs_ankety ADD COLUMN jazyk CHAR(2) NOT NULL DEFAULT '' AFTER uzavrena;
ALTER TABLE rs_odberatele ADD COLUMN jazyk CHAR(2) NOT NULL DEFAULT '' AFTER prihlasen;
ALTER TABLE rs_newsletter ADD COLUMN jazyk CHAR(2) NOT NULL DEFAULT '' AFTER prokliku;
