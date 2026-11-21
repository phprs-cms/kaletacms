<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Antispam;
use Kaleta\Builder\Elements\Form;
use Kaleta\Core\App;
use Kaleta\Core\Files;
use Kaleta\Core\Whistleblowing as Channel;

/**
 * The public side of the whistleblowing channel (2.14, Core\Whistleblowing): /_report with the form and the one-time
 * case number and access code, /_report/follow where the reporter opens the case with both, reads the status and the
 * handler's messages and adds information. Plain HTML forms, no script needed; the Kernel shows the page without
 * statistics, cache, tracking codes or a cookie bar.
 */
final class Whistleblowing
{
    public function __construct(private readonly App $app)
    {
    }

    /** @return array{0: string, 1: string, 2: int} title, the HTML of the page, the HTTP status */
    public function render(bool $follow): array
    {
        // 3.3.3 (N53): no third-party CAPTCHA anywhere on the channel's pages – its script would hand the reporter's address,
        // cookies and browser to Google, hCaptcha or Cloudflare. The built-in protection (Antispam) and the caps remain.
        \Kaleta\Core\Captcha::offOnThisPage();

        return $follow ? $this->followUp() : $this->report();
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function report(): array
    {
        $r = $this->app->request;
        $title = t('Report a concern');
        $error = '';
        $busy = false;
        $tooLarge = false;
        if ($r->bodyTooLarge()) {
            // 3.50.1 (UX-c1): a body over post_max_size reaches PHP with $_POST and $_FILES emptied – instead of "could not be
            // verified" over an empty form the reporter learns the real limits; no antispam attempt is counted
            $error = Form::messages('size', Form::MAX_ATTACHMENT, Files::postLimit());
            $tooLarge = true;
        } elseif ($r->isPost()) {
            $reason = (new Antispam($this->app->db(), $this->app->settings()))->verify($r, 'oznameni');
            if ($reason !== null) {
                $error = $reason === 'robot' ? t('The form could not be verified. Reload the page and try again.') : $reason;
            } else {
                $result = Channel::receive($this->app, $r->post('text'), $r->post('name'), $r->post('contact'), is_array($_FILES['files'] ?? null) ? $_FILES['files'] : null);
                if ($result === null) {
                    // the daily limit of the address bucket (3.3.2): a kind answer that keeps the text
                    $error = t('We cannot accept another report right now. Please try again later – your text is still in the form below.');
                    $busy = true;
                } elseif (is_array($result)) {
                    return [$title, $this->wrap($title, '<p class="ka-formular-odeslano">' . e(t('Thank you. Your report has been received.')) . '</p>'
                        . ($result['without_attachments'] ? '<p class="ka-formular-chyba" role="status">' . e(t('Your attachments could not be stored right now, so the report was received without them. You can describe what they show in a message when you follow your report.')) . '</p>' : '')
                        . '<dl class="ka-oznameni-pristup"><dt>' . e(t('Case number')) . '</dt><dd><code class="ka-oznameni-cislo">' . e($result['number']) . '</code></dd>'
                        . '<dt>' . e(t('Access code')) . '</dt><dd><code class="ka-oznameni-kod">' . e($result['code']) . '</code></dd></dl>'
                        . '<p><strong>' . e(t('Write both down now – the code is shown only once and cannot be recovered. With them you can follow the case and add information.')) . '</strong></p>'
                        . '<p>' . e(t('We will confirm receipt within %d days and give you feedback within %d months.', Channel::ACKNOWLEDGE_DAYS, Channel::FEEDBACK_MONTHS)) . '</p>'
                        . '<p><a class="ka-tlacitko" href="' . e($this->app->url('_report/follow')) . '">' . e(t('Follow your report')) . '</a></p>'), 200];
                } else {
                    $error = $result; // the reason the report was refused (empty text, too long…)
                }
            }
        }
        $antispam = new Antispam($this->app->db(), $this->app->settings());
        $intro = trim($this->app->settings()->get('whistleblowing_intro'));
        $html = ($intro !== '' ? '<p>' . nl2br(e($intro)) . '</p>' : '')
            . '<p>' . e(t('This channel is for reporting breaches of law or internal rules in our organisation. You may stay anonymous. Your report is stored encrypted and only the persons appointed to handle reports can read it.')) . '</p>'
            . ($error !== '' ? '<p class="ka-formular-chyba" role="alert">' . e($error) . '</p>' : '')
            // the limits for the browser's size check (image/web.js, form[data-file-too-large]); never data-formular – the
            // text of a report is not kept in the browser's storage (3.50.1 D4)
            . '<form class="ka-formular" method="post" enctype="multipart/form-data" autocomplete="off"' . self::limitAttributes(Files::postLimit()) . '>' . $antispam->fields('oznameni')
            . '<p class="ka-pole"><label for="o-text">' . e(t('What happened')) . ' <span class="ka-povinne" aria-hidden="true">*</span></label><textarea id="o-text" name="text" rows="10" maxlength="' . Channel::MAX_TEXT . '" required>' . $this->field('text') . '</textarea></p>'
            . '<p class="ka-pole"><label for="o-name">' . e(t('Your name (optional)')) . '</label><input id="o-name" name="name" maxlength="200" value="' . $this->field('name') . '"></p>'
            . '<p class="ka-pole"><label for="o-contact">' . e(t('How can we reach you (optional)')) . '</label><input id="o-contact" name="contact" maxlength="500" value="' . $this->field('contact') . '"></p>'
            . '<p class="ka-pole"><label for="o-files">' . e(t('Attachments (optional)')) . '</label><input id="o-files" name="files[]" type="file" multiple accept=".' . implode(',.', Form::ATTACHMENT_EXTENSIONS) . '" data-max-bytes="' . Form::MAX_ATTACHMENT . '">'
            . '<small class="ka-pole-napoveda">' . e(self::attachmentHint(Files::postLimit())) . '</small></p>'
            . '<p class="ka-pole"><button class="ka-tlacitko ka-tlacitko--primarni" type="submit">' . e(t('Send the report')) . '</button></p></form>'
            . '<p><a href="' . e($this->app->url('_report/follow')) . '">' . e(t('Follow your report')) . '</a></p>';

        return [$title, $this->wrap($title, $html), $tooLarge ? 413 : ($busy ? 429 : ($error !== '' ? 422 : 200))];
    }

    /** The attributes of the report form for the browser's size check: one file and the server's limit of the body. Pure. */
    public static function limitAttributes(int $postLimit): string
    {
        return Form::limitAttributes(Form::MAX_ATTACHMENT, $postLimit);
    }

    /**
     * The hint under the file field (3.50.1): 5 files of 10 MB, and the real total when the server accepts less than that in
     * one body (post_max_size; 0 = no limit). Pure.
     */
    public static function attachmentHint(int $postLimit): string
    {
        $perFile = (int) (Form::MAX_ATTACHMENT / 1048576);
        if ($postLimit > 0 && $postLimit < Channel::MAX_ATTACHMENTS * Form::MAX_ATTACHMENT) {
            return t('Up to %d files, each up to %d MB and together up to %d MB: PDF, image, document or ZIP.', Channel::MAX_ATTACHMENTS, $perFile, max(1, intdiv($postLimit, 1048576)));
        }

        return t('Up to %d files, each up to %d MB: PDF, image, document or ZIP.', Channel::MAX_ATTACHMENTS, $perFile);
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function followUp(): array
    {
        $r = $this->app->request;
        $title = t('Follow your report');
        $error = '';
        $notice = '';
        $status = 200;
        if ($r->isPost()) {
            if (Channel::tooManyAttempts($this->app)) {
                $error = t('Too many attempts. Try again in an hour.');
                $status = 429;
            } else {
                $case = Channel::open($this->app, trim($r->post('number')), $r->post('code'));
                if ($case === null) {
                    $error = t('The case number or the access code is wrong.');
                    $status = 403;
                } else {
                    if ($r->post('reply') !== '' && Channel::addMessage($this->app, (int) $case['id'], 'reporter', $r->post('reply'))) {
                        $notice = t('Your message was added to the case.');
                    }

                    return [$title, $this->wrap($title, $this->caseHtml($case, trim($r->post('number')), $r->post('code'), $notice)), 200];
                }
            }
        }
        $html = '<p>' . e(t('Enter the case number and the access code you received when you sent the report.')) . '</p>'
            . ($error !== '' ? '<p class="ka-formular-chyba" role="alert">' . e($error) . '</p>' : '')
            . '<form class="ka-formular" method="post" autocomplete="off">'
            . '<p class="ka-pole"><label for="o-number">' . e(t('Case number')) . '</label><input id="o-number" name="number" maxlength="12" placeholder="' . e(date('Y')) . '-0001" required value="' . $this->field('number') . '"></p>'
            . '<p class="ka-pole"><label for="o-code">' . e(t('Access code')) . '</label><input id="o-code" name="code" maxlength="40" required></p>'
            . '<p class="ka-pole"><button class="ka-tlacitko ka-tlacitko--primarni" type="submit">' . e(t('Show the case')) . '</button></p></form>';

        return [$title, $this->wrap($title, $html), $status];
    }

    /** The reporter's view of a case: the status, the deadlines, the messages and a box for more information. */
    private function caseHtml(array $case, string $number, string $code, string $notice): string
    {
        $deadlines = Channel::deadlines((string) $case['created_at']);
        $html = ($notice !== '' ? '<p class="ka-formular-odeslano">' . e($notice) . '</p>' : '')
            . '<dl class="ka-oznameni-pristup"><dt>' . e(t('Case number')) . '</dt><dd><code class="ka-oznameni-cislo">' . e((string) $case['number']) . '</code></dd>'
            . '<dt>' . e(t('Submitted on')) . '</dt><dd>' . e(format_date((string) $case['created_at'], true)) . '</dd>'
            . '<dt>' . e(t('Status')) . '</dt><dd class="ka-oznameni-stav" data-stav="' . e((string) $case['status']) . '">' . e(t(Channel::STATUSES[$case['status']] ?? (string) $case['status'])) . '</dd>';
        if ($case['status'] !== 'closed') {
            $html .= '<dt>' . e(t('Feedback due by')) . '</dt><dd>' . e(format_date($deadlines['feedback_due'])) . '</dd>';
        }
        $html .= '</dl>';
        $messages = Channel::messages($this->app, (int) $case['id']);
        if ($messages !== []) {
            $html .= '<h2>' . e(t('Messages')) . '</h2><ol class="ka-oznameni-zpravy">';
            foreach ($messages as $m) {
                $html .= '<li class="ka-oznameni-zprava ka-oznameni-zprava--' . e($m['from']) . '"><p class="ka-oznameni-od"><strong>' . e(t($m['from'] === 'handler' ? 'From the handler' : 'From you')) . '</strong> · ' . e(format_date($m['created_at'], true)) . '</p><p>' . nl2br(e($m['text'])) . '</p></li>';
            }
            $html .= '</ol>';
        }
        if ($case['status'] !== 'closed') {
            $html .= '<h2>' . e(t('Add information')) . '</h2><form class="ka-formular" method="post" autocomplete="off"><input type="hidden" name="number" value="' . e($number) . '"><input type="hidden" name="code" value="' . e($code) . '">'
                . '<p class="ka-pole"><label for="o-reply">' . e(t('Your message')) . '</label><textarea id="o-reply" name="reply" rows="6" maxlength="' . Channel::MAX_MESSAGE . '" required></textarea></p>'
                . '<p class="ka-pole"><button class="ka-tlacitko ka-tlacitko--primarni" type="submit">' . e(t('Send')) . '</button></p></form>';
        }

        return $html;
    }

    private function field(string $name): string
    {
        return e(is_scalar($_POST[$name] ?? null) ? (string) $_POST[$name] : '');
    }

    private function wrap(string $title, string $html): string
    {
        return '<div class="ka-porovnani-stranka ka-oznameni"><h1>' . e($title) . '</h1>' . $html . '</div>';
    }
}
