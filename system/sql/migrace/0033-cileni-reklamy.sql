-- Cílení reklamy: jen ve vybrané rubrice, jen na telefonu / jen na počítači.
ALTER TABLE rs_reklama
    ADD COLUMN jen_rubrika INT UNSIGNED NULL,
    ADD COLUMN zarizeni VARCHAR(10) NOT NULL DEFAULT 'vse';   -- vse | mobil | pocitac
