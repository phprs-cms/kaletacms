-- Bloky: zobrazení jen v určité rubrice a jen na určitém zařízení.
ALTER TABLE rs_bloky
    ADD COLUMN jen_rubrika INT UNSIGNED NULL AFTER zona,          -- NULL = všude; jinak jen v rubrice a u jejích článků
    ADD COLUMN zarizeni VARCHAR(10) NOT NULL DEFAULT 'vse' AFTER jen_rubrika,  -- vse | mobil | pocitac
    ADD CONSTRAINT fk_bloky_rubrika FOREIGN KEY (jen_rubrika) REFERENCES rs_topic (idt) ON DELETE SET NULL;
