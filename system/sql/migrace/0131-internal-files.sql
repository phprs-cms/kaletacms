-- 3.42: internal files (Core\InternalFiles, admin Workspace → Internal files) – a document library for the team that is
-- never public: no page, search, sitemap, llms.txt, export, blueprint, page package, heartbeat or media picker reads these
-- tables (a unit test keeps them inside the allowed classes). The files live in storage/internal-files/ under random names,
-- outside media/, and are served only through the admin action with the right to the folder.
-- A folder is seen by everyone with the section (audience all) or only by the custom roles in roles (audience roles);
-- administrators see every folder.
-- No foreign keys (links follow in code); no section is given by this migration – administrators have it, others get it
-- in Users or Roles. A table that already exists (an earlier attempt) is skipped by the migration runner (1050).
CREATE TABLE ka_internal_folders (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    audience    VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT 'all',  -- all | roles
    roles       VARCHAR(500) CHARACTER SET ascii NOT NULL DEFAULT '',    -- with audience roles: comma-separated ka_role ids
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_internal_folders_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- One document of the library: its name, description and tags, and the number of its current version.
CREATE TABLE ka_internal_files (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    folder_id   INT UNSIGNED NOT NULL,
    name        VARCHAR(200) NOT NULL,                                   -- the shown name with its extension
    description TEXT NULL,
    tags        VARCHAR(500) NOT NULL DEFAULT '',                        -- comma-separated
    status      VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT 'ready', -- ready | draft (a drafts-only Claude connection writes drafts)
    extension   VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT '',
    size        BIGINT UNSIGNED NOT NULL DEFAULT 0,                      -- bytes of the current version
    version     INT UNSIGNED NOT NULL DEFAULT 1,                         -- the current version
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL,
    updated_by  INT UNSIGNED NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_internal_files_folder (folder_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Every version of a file: replacing a file adds a version and keeps the older ones.
CREATE TABLE ka_internal_file_versions (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    file_id       INT UNSIGNED NOT NULL,
    version       INT UNSIGNED NOT NULL,
    path          VARCHAR(80) CHARACTER SET ascii NOT NULL,              -- YYYY/MM/<32 hex>.bin under storage/internal-files
    original_name VARCHAR(200) NOT NULL DEFAULT '',
    extension     VARCHAR(10) CHARACTER SET ascii NOT NULL DEFAULT '',
    size          BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sha256        CHAR(64) CHARACTER SET ascii NOT NULL DEFAULT '',
    uploaded_by   INT UNSIGNED NULL,
    uploaded_at   DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_internal_file_versions (file_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
