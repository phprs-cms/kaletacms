<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * An optional CAPTCHA for visitors' forms (2.6): hCaptcha, Google reCAPTCHA v3 or Cloudflare Turnstile, on top of the
 * built-in protection (Antispam). Off by default. The provider's script loads only on pages with a form; the answer is
 * checked on the server. The secret key stays in the settings (type tajne) and is never shown or sent to Claude.
 */
final class Captcha
{
    /** provider => [name, script, the field the widget posts, verification address, the widget's class (null = invisible, v3)] */
    public const array PROVIDERS = [
        'hcaptcha' => ['hCaptcha', 'https://js.hcaptcha.com/1/api.js', 'h-captcha-response', 'https://api.hcaptcha.com/siteverify', 'h-captcha'],
        'recaptcha' => ['Google reCAPTCHA v3', 'https://www.google.com/recaptcha/api.js?render=%s', 'g-recaptcha-response', 'https://www.google.com/recaptcha/api/siteverify', null],
        'turnstile' => ['Cloudflare Turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', 'cf-turnstile-response', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', 'cf-turnstile'],
    ];

    /** reCAPTCHA v3 gives a score from 0 (bot) to 1 (person); below this the form is refused. */
    public const float RECAPTCHA_MIN_SCORE = 0.5;

    private static bool $scriptPrinted = false;

    /** No provider's widget or script on this page (offOnThisPage). */
    private static bool $off = false;

    /**
     * The page being rendered must not load the provider's script at all – the whistleblowing channel (3.3.3, N53): the
     * provider would learn the reporter's address, cookies and browser. A form elsewhere on such a page (a site part)
     * then shows no widget either.
     */
    public static function offOnThisPage(): void
    {
        self::$off = true;
    }

    /** The configured provider, or null when the CAPTCHA is off or not fully set up. */
    public static function provider(Settings $s): ?string
    {
        $provider = $s->get('captcha_provider');

        return isset(self::PROVIDERS[$provider]) && $s->get('captcha_site_key') !== '' && $s->get('captcha_secret') !== '' ? $provider : null;
    }

    /** The widget for inside a form (with the provider's script the first time on the page); '' when the CAPTCHA is off. */
    public static function widget(Settings $s): string
    {
        $provider = self::provider($s);
        if ($provider === null || self::$off) {
            return '';
        }
        [, $script, $field, , $class] = self::PROVIDERS[$provider];
        $key = $s->get('captcha_site_key');
        $html = $class !== null
            ? '<div class="ka-captcha ' . $class . '" data-sitekey="' . e($key) . '"></div>'
            : '<input type="hidden" name="' . $field . '" value="" data-recaptcha="' . e($key) . '">'; // reCAPTCHA v3: image/web.js fills it in on submit
        if (!self::$scriptPrinted) {
            self::$scriptPrinted = true;
            $html .= '<script src="' . e(sprintf($script, rawurlencode($key))) . '" async defer></script>';
        }

        return $html;
    }

    /**
     * Checks the visitor's answer with the provider: true passed, false failed, null the provider could not be reached
     * (the site decides with captcha_fail_open whether such a form is accepted on the built-in protection alone).
     */
    public static function verify(Settings $s, Request $r): ?bool
    {
        $provider = self::provider($s);
        if ($provider === null) {
            return true;
        }
        [, , $field, $url] = self::PROVIDERS[$provider];
        $answer = $r->post($field);
        if ($answer === '' || strlen($answer) > 4096) {
            return false;
        }
        // KALETA_CAPTCHA_VERIFY replaces the provider's address in the tests (tools/test.sh), never needed on a real site
        $url = TestSeams::value('KALETA_CAPTCHA_VERIFY') ?: $url;
        $result = self::post($url, ['secret' => $s->get('captcha_secret'), 'response' => $answer, 'remoteip' => $r->ip()]);
        if ($result === null) {
            return null;
        }
        if (($result['success'] ?? false) !== true) {
            return false;
        }

        return $provider !== 'recaptcha' || (float) ($result['score'] ?? 0) >= self::RECAPTCHA_MIN_SCORE;
    }

    /** Whether a form with this result of verify() goes through. */
    public static function accepted(Settings $s, ?bool $verified): bool
    {
        return $verified ?? $s->bool('captcha_fail_open');
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>|null the provider's JSON answer, null when it did not answer in time
     */
    private static function post(string $url, array $fields): ?array
    {
        $body = http_build_query($fields);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);
            $response = curl_exec($ch);
        } else {
            $response = @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'timeout' => 5,
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $body]]));
        }
        $data = is_string($response) ? json_decode($response, true) : null;

        return is_array($data) ? $data : null;
    }
}
