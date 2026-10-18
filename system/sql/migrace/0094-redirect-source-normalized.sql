-- 3.14 (docs/design/4.0-data-model.md §1.3 – expand): the old address of a redirect in its normal form, compared as exact
-- bytes (utf8mb4_bin). NULL = not normalised yet: the background job "redirect_sources" (Core\RedirectSources) fills it in
-- batches, for the rows of today and for every later one. Lookups keep using z_adresy in 3.14, so nothing changes for
-- visitors and the code of 3.13 ignores the column. The key is not unique yet: System status lists the sources that would
-- collide (Core\Readiness), and only 4.0 makes it unique and looks redirects up by it.
ALTER TABLE ka_presmerovani ADD COLUMN source_norm VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL AFTER z_adresy, ADD KEY ix_presmerovani_norm (source_norm);
