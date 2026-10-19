### 3.15 – stream C: files from drafts-only connections wait for approval (audit N10, closed)

Owner decision 6 Oct, design approved 19 Oct 2026. `upload_file` stays a `draft` tool. A drafts-only Claude connection's
files no longer go straight to the public `media/` folder:

- **What is staged:** files stored through MCP by a connection limited to drafts. That is `upload_file` (images, SVG,
  attachments, fonts) and the media downloads of `save_collection_items`. No other drafts-only tool writes into `media/`:
  `import_website` and `import_wordpress` are `write` tools. Full-access connections, the admin's own uploads and imports
  work as before (`Core\MediaStaging::appliesTo`).
- **Where:** `storage/staging/YYYY/MM/<name>`, outside the web root. The files get the same checks as before: `Images`
  re-encodes, `Files` checks finfo against the allowlist, and SVG is cleaned (a `$staged` flag on
  `Images::saveFile`, `Files::saveFile` and `Media::saveSvgContent`). Migration 0099 adds `ka_media.staged_at`. The path
  the tool returns is still the final `media/…` one, so drafts use it unchanged.
- **Preview:** a request for a missing `media/…` file reaches `index.php`. Apache does this through the root
  `.htaccess`, the PHP dev server through `dev-router.php`, and nginx through the new `try_files … /index.php` lines in
  `system/nginx.example.conf`. `Front\Kernel` serves the staged file to signed-in users and to draft previews. A
  verified preview key sets the `ka_nahled_media` cookie on `/media/`, and the whole-site preview cookie works too. The
  response has nosniff, `attachment` for documents, the SVG sandbox CSP and `private, no-store`. Everyone else gets the
  usual 404.
- **Going public:** a staged file goes public once published content refers to it. This is checked after every admin
  POST, after every write call of a connection that may publish, on scheduled publishing, when a newsletter is sent,
  and when the look is published. A class background counts once a published build uses the class. As a safety net, the
  first anonymous request for a file that published content refers to also publishes it. Media → *Waiting for approval*
  has **Publish file** and **Delete**, and the dashboard's *Waiting for you* lists the files. `list_media` has
  `staged: true`, and `upload_file` returns `staged: true` and a note.
- **Cleanup:** the hourly background job `media_staging` publishes the files that are in use and deletes those nobody
  approved within 30 days, including orphaned staging files.
- Staged rows are left out of the site export.

**For operators:** sites on nginx need the updated `/media/` block, or staged files stay 404 even in previews.
Publishing still works there.
