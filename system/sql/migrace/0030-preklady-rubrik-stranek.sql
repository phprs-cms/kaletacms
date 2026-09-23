-- Propojení překladů i u rubrik a stránek (u článků už je): přepínač jazyků vede na protějšek a vyhledávače dostanou hreflang.
ALTER TABLE rs_topic   ADD COLUMN preklad_z INT UNSIGNED NULL;   -- idt rubriky ve výchozím jazyce
ALTER TABLE rs_stranky ADD COLUMN preklad_z INT UNSIGNED NULL;   -- ids stránky ve výchozím jazyce
