<?php
/**
 * Accessibility toolbar for visitors (2.14): larger text (root font-size steps), higher contrast (a class on <html> that
 * maps the design system colours to black on white), underlined links and reduced motion. The choice stays in this browser
 * (localStorage "ka-pristupnost", no cookies) and is applied before the first paint. A popover like the appearance switcher:
 * keyboard and screen-reader accessible (a button with aria-pressed per on/off option, Esc closes it). "Larger text" cycles
 * three sizes, so instead of aria-pressed its label shows the current size. The panel opens above the button; while the
 * cookie bar shows, both sit above it (--ka-cookies-vyska, views/front/cookies.php).
 */
$options = ['text' => t('Larger text'), 'kontrast' => t('High contrast'), 'podtrzeni' => t('Underline links'), 'klid' => t('Reduce motion')];
?>
<div class="ka-pristupnost" data-pristupnost>
<style>
:root.ka-text-1 { font-size: 112.5%; }
:root.ka-text-2 { font-size: 125%; }
:root.ka-kontrast { --ka-barva-text: #000000; --ka-barva-pozadi: #ffffff; --ka-barva-plocha: #ffffff; --ka-barva-primarni: #0000c8; --ka-barva-sekundarni: #005a00; --ka-barva-na-primarni: #ffffff; --ka-barva-tlumeny: #000000; --ka-barva-linka: #000000; --text: #000000; --podklad: #ffffff; --text-tlumeny: #000000; --linka: #000000; color-scheme: light; }
:root.ka-kontrast body { background: #ffffff; color: #000000; }
:root.ka-kontrast a { color: #0000c8; }
:root.ka-kontrast img, :root.ka-kontrast video { filter: contrast(1.15); }
:root.ka-podtrzeni a { text-decoration: underline; text-decoration-thickness: 0.09em; text-underline-offset: 0.12em; }
:root.ka-klid *, :root.ka-klid *::before, :root.ka-klid *::after { animation-duration: 0.001ms !important; animation-iteration-count: 1 !important; transition-duration: 0.001ms !important; scroll-behavior: auto !important; }
.ka-pristupnost { --ka-pristupnost-dole: max(56px, var(--ka-cookies-vyska, 0px)); position: fixed; inset-inline-start: 12px; bottom: var(--ka-pristupnost-dole); z-index: 998; font: 14px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
.ka-pristupnost-tl { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border: 1px solid #475467; border-radius: 999px; background: #ffffff; color: #14171f; cursor: pointer; font: inherit; }
.ka-pristupnost-tl:focus-visible, .ka-pristupnost-panel button:focus-visible { outline: 3px solid #0000c8; outline-offset: 2px; }
/* the popover UA style is inset: 0 – without resetting top the panel would open at the top of the window */
.ka-pristupnost-panel { position: fixed; inset-block: auto calc(var(--ka-pristupnost-dole) + 44px); inset-inline: 12px auto; max-height: calc(100vh - var(--ka-pristupnost-dole) - 56px); overflow-y: auto; margin: 0; padding: 8px; border: 1px solid #475467; border-radius: 10px; background: #ffffff; color: #14171f; box-shadow: 0 12px 40px rgb(0 0 0 / 0.18); min-width: 220px; }
.ka-pristupnost-panel button { display: flex; width: 100%; align-items: center; justify-content: space-between; gap: 12px; padding: 8px 10px; border: 0; border-radius: 6px; background: none; color: inherit; font: inherit; text-align: start; cursor: pointer; }
.ka-pristupnost-panel button:hover { background: #f2f4f7; }
.ka-pristupnost-panel button[aria-pressed="true"]::after { content: "✓"; font-weight: 700; }
.ka-pristupnost-panel button[aria-pressed="false"]::after { content: ""; width: 1em; }
.ka-pristupnost-uroven { font-variant-numeric: tabular-nums; color: #475467; }
.ka-pristupnost-panel .ka-pristupnost-reset { margin-top: 4px; border-top: 1px solid #d0d5dd; border-radius: 0; font-size: 0.9em; }
@media print { .ka-pristupnost { display: none; } }
</style>
<button type="button" class="ka-pristupnost-tl" popovertarget="ka-pristupnost-panel" aria-label="<?= e(t('Accessibility options')) ?>"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="4.5" r="1.8"/><path d="M5 9.5c4.7 1 9.3 1 14 0M12 10.5v4.5M12 15l-3 6M12 15l3 6"/></svg><span><?= e(t('Accessibility')) ?></span></button>
<div id="ka-pristupnost-panel" class="ka-pristupnost-panel" popover role="group" aria-label="<?= e(t('Accessibility options')) ?>">
<?php foreach ($options as $key => $labelText): ?>
<?php if ($key === 'text'): ?>
	<button type="button" data-pristupnost-volba="text"><span><?= e($labelText) ?></span> <span class="ka-pristupnost-uroven">100%</span></button>
<?php else: ?>
	<button type="button" data-pristupnost-volba="<?= $key ?>" aria-pressed="false"><?= e($labelText) ?></button>
<?php endif ?>
<?php endforeach ?>
	<button type="button" class="ka-pristupnost-reset" data-pristupnost-volba=""><?= e(t('Default settings')) ?></button>
</div>
<script>
(function () {
	var KEY = 'ka-pristupnost', root = document.documentElement, box = document.querySelector('[data-pristupnost]');
	var SIZES = [1, 1.125, 1.25], level = box.querySelector('.ka-pristupnost-uroven');
	var percent = function (n) { try { return new Intl.NumberFormat(root.lang || undefined, { style: 'percent', maximumFractionDigits: 1 }).format(n); } catch (e) { return Math.round(n * 1000) / 10 + '%'; } };
	var read = function () { try { var v = JSON.parse(localStorage.getItem(KEY) || '{}'); return v && typeof v === 'object' ? v : {}; } catch (e) { return {}; } };
	var write = function (v) { try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) { /* storage unavailable – the choice lasts for this page */ } };
	var apply = function (v) {
		root.classList.remove('ka-text-1', 'ka-text-2', 'ka-kontrast', 'ka-podtrzeni', 'ka-klid');
		if (v.text) { root.classList.add('ka-text-' + v.text); }
		['kontrast', 'podtrzeni', 'klid'].forEach(function (k) { if (v[k]) { root.classList.add('ka-' + k); } });
		box.querySelectorAll('[data-pristupnost-volba]').forEach(function (b) {
			var k = b.getAttribute('data-pristupnost-volba');
			if (k && k !== 'text') { b.setAttribute('aria-pressed', v[k] ? 'true' : 'false'); }
		});
		level.textContent = percent(SIZES[v.text] || 1);
	};
	var state = read();
	apply(state);
	box.addEventListener('click', function (e) {
		var b = e.target.closest('[data-pristupnost-volba]');
		if (!b) { return; }
		var k = b.getAttribute('data-pristupnost-volba');
		if (!k) { state = {}; } else if (k === 'text') { state.text = ((state.text || 0) + 1) % 3; } else { state[k] = !state[k]; }
		write(state);
		apply(state);
	});
})();
</script>
</div>
