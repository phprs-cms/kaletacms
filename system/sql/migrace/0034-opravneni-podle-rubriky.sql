-- Oprávnění podle rubriky: uživatel bez záznamu smí do všech rubrik, se záznamy jen do vyjmenovaných.
CREATE TABLE rs_user_rubriky (
    idu INT UNSIGNED NOT NULL,
    idt INT UNSIGNED NOT NULL,
    PRIMARY KEY (idu, idt),
    CONSTRAINT fk_user_rubriky_user FOREIGN KEY (idu) REFERENCES rs_user (idu) ON DELETE CASCADE,
    CONSTRAINT fk_user_rubriky_rubrika FOREIGN KEY (idt) REFERENCES rs_topic (idt) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
