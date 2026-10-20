-- 3.17: customers on the fleet console (Fleet\Customers). A site belongs to at most one customer – a name the owner
-- renames in one place; the list, the inbox and the staged updates filter by it, and a kit version can be meant for one
-- customer's sites only (ka_fleet_kits.customer_id, NULL = every site). The note is the owner's own text about a site;
-- it stays on the console and never travels to the site.
CREATE TABLE IF NOT EXISTS ka_fleet_customers (
    id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fleet_customers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
ALTER TABLE ka_fleet_sites ADD COLUMN customer_id INT UNSIGNED NULL AFTER silent_reported, ADD COLUMN note VARCHAR(1000) NOT NULL DEFAULT '' AFTER customer_id, ADD KEY ix_fleet_sites_customer (customer_id);
ALTER TABLE ka_fleet_kits ADD COLUMN customer_id INT UNSIGNED NULL AFTER summary;
