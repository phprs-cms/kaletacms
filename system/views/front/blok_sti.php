<?php
/**
 * Blok: nejpoužívanější štítky.
 *
 * @var list<array<string, mixed>> $stitky
 * @var callable(string): string $url
 */
?>
<p class="blok-stitky"><?php foreach ($stitky as $s): ?><a href="<?= e($url('stitek/' . $s['seo_link'])) ?>" rel="tag">#<?= e($s['nazev']) ?></a> <?php endforeach ?></p>
