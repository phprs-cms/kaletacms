<?php

declare(strict_types=1);

/* ---------- 3.50 A1 (N153, N154): a second extension only for Kaleta's own image siblings; imported files can be deleted, only inside media/ ---------- */
use Kaleta\Core\Files as A350Files;
use Kaleta\Core\Images as A350Images;
use Kaleta\Core\SiteImport as A350Site;

// N153: the archive of a Kaleta export may carry photo.jpg.webp and photo.jpg.avif (Images::save writes them), nothing else
check('3.50 N153: one allowed extension, or an image with its WebP or AVIF sibling – any other second extension is refused, SVG in any other part too', array_map(A350Site::mediaTarget(...), [
    'media/2026/05/photo-1a2b3c.jpg', 'media/2026/05/photo-1a2b3c.jpg.webp', 'media/2026/05/photo-1a2b3c-1200.png.avif', 'media/2026/05/photo.webp.avif', 'media/old/Logo.SVG',
    'media/2026/05/price.pdf.jpg', 'media/2026/05/logo.svg.webp', 'media/2026/05/photo.jpg.svg', 'media/2026/05/photo.webp.webp', 'media/2026/05/a.jpg.webp.avif', 'media/2026/05/photo.php.jpg']),
    ['media/2026/05/photo-1a2b3c.jpg', 'media/2026/05/photo-1a2b3c.jpg.webp', 'media/2026/05/photo-1a2b3c-1200.png.avif', 'media/2026/05/photo.webp.avif', 'media/old/Logo.svg',
        null, null, null, null, null, null]);

// N154: a file with an imported name is deleted like Media's own; never a folder, never a file a link outside points at
$a350Dir = KALETA_ROOT . '/media/a350-unit-' . getmypid();
$a350Outside = sys_get_temp_dir() . '/a350-outside-' . getmypid() . '.pdf';
@mkdir($a350Dir . '/folder.pdf', 0777, true);
file_put_contents($a350Dir . '/Price_list.pdf', '%PDF-1.4 x');
file_put_contents($a350Dir . '/Photo.jpg', 'x');
file_put_contents($a350Dir . '/Photo.jpg.webp', 'x');
file_put_contents($a350Dir . '/Photo-1200.jpg', 'x');
file_put_contents($a350Outside, '%PDF-1.4 outside');
@symlink($a350Outside, $a350Dir . '/link.pdf');
$a350Rel = 'media/' . basename($a350Dir);
A350Files::delete($a350Rel . '/Price_list.pdf');
A350Images::delete($a350Rel . '/Photo.jpg', '');
A350Files::delete($a350Rel . '/link.pdf');
A350Files::delete($a350Rel . '/folder.pdf');
A350Files::delete($a350Rel . '/../' . basename($a350Dir) . '/Price_list.pdf');
check('3.50 N154: an imported name is deleted with its image siblings; a link out and a folder stay – and stillOnDisk says which rows must stay', [
    is_file($a350Dir . '/Price_list.pdf'), is_file($a350Dir . '/Photo.jpg'), is_file($a350Dir . '/Photo.jpg.webp'), is_file($a350Dir . '/Photo-1200.jpg'),
    is_link($a350Dir . '/link.pdf'), is_file($a350Outside), is_dir($a350Dir . '/folder.pdf'),
    A350Files::stillOnDisk($a350Rel . '/Price_list.pdf'), A350Files::stillOnDisk($a350Rel . '/link.pdf'), A350Files::stillOnDisk($a350Rel . '/folder.pdf'), A350Files::stillOnDisk('media/../config.php')],
    [false, false, false, false, true, true, true, false, true, false, false]);
check('3.50 N154: only a path mediaTarget keeps as it is counts as an imported name (no "..", lower-case extensions, an allowed type)', [
    A350Files::importedName('media/x/Price_list.pdf'), A350Files::importedName('media/x/Price_list.PDF'), A350Files::importedName('media/../x.pdf'), A350Files::importedName('media/x/run.php')],
    [true, false, false, false]);
@unlink($a350Dir . '/link.pdf');
@unlink($a350Outside);
@rmdir($a350Dir . '/folder.pdf');
@rmdir($a350Dir);
