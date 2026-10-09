<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;
use Kaleta\Core\Antispam;
use Kaleta\Core\Booking as Bookings;

/**
 * Online booking of an appointment (3.0, Core\Booking): the visitor picks a service, a person (or anyone), a day and a
 * free time, leaves a name, e-mail and phone and agrees to the processing. Sent to /_booking (Front\Booking).
 *
 * The server renders a plain form that works without image/web.js: the services and people as radio buttons and a
 * select of the next free times of the chosen service (?booking=<id>&service=<service> reloads it for another service).
 * With the script the select becomes a small month calendar of days with free times (/_booking/days) and the times of
 * the chosen day (/_booking/slots).
 */
final class Booking extends Element
{
    public const string TYPE = 'rezervace';
    public const string NAME = 'Booking';
    public const string DESCRIPTION = 'Online booking of an appointment: a service, a person, a day and a free time – the bookings are in Bookings and arrive by e-mail.';
    public const string ICON = 'formular';
    public const string GROUP = 'Dynamic';
    public const string EXTENSION = 'bookings';
    public const array HTML_TAGS = ['form'];

    /** How many of the next free times the plain form offers. */
    private const int FALLBACK_SLOTS = 40;

    public static function properties(): array
    {
        return [
            'sluzba' => ['typ' => 'cislo', 'popisek' => 'Service (id from Bookings → Services; 0 = the visitor chooses)', 'vychozi' => 0, 'min' => 0, 'max' => 1000000],
            'osoba' => ['typ' => 'cislo', 'popisek' => 'Person (id from Bookings → People; 0 = the visitor chooses, or anyone)', 'vychozi' => 0, 'min' => 0, 'max' => 1000000],
            'tlacitko' => ['typ' => 'text', 'popisek' => 'Button text', 'vychozi' => t('Book the appointment'), 'max' => 80],
            'dekujeme' => ['typ' => 'text', 'popisek' => 'Thank-you message', 'vychozi' => t('Thank you, your appointment is booked. A confirmation with the details and a cancel link is on its way to your e-mail.'), 'max' => 400],
            'souhlas' => ['typ' => 'text', 'popisek' => 'Consent text (a required checkbox)', 'vychozi' => t('I agree to the processing of my personal data for the purpose of this appointment.'), 'max' => 300],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-rezervace { display: grid; gap: var(--ka-mezera-m); }
.ka-rezervace, .ka-rezervace-hotovo { scroll-margin-top: 6rem; }
.ka-rezervace fieldset { display: grid; gap: var(--ka-mezera-2xs); margin: 0; padding: 0; border: 0; }
.ka-rezervace legend { margin-block-end: var(--ka-mezera-2xs); padding: 0; font-weight: 600; }
.ka-rezervace-volby { display: grid; gap: var(--ka-mezera-2xs); }
.ka-rezervace-volby label { display: flex; gap: var(--ka-mezera-xs); align-items: flex-start; padding: 0.6em 0.9em; border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); cursor: pointer; }
.ka-rezervace-volby label:has(:checked) { border-color: var(--ka-barva-primarni); background: var(--ka-barva-primarni-jemna); }
.ka-rezervace-volby input { margin-block-start: 0.3em; accent-color: var(--ka-barva-primarni); }
.ka-rezervace-volby small { display: block; color: var(--ka-barva-tlumeny); }
.ka-rezervace-kalendar { display: grid; gap: var(--ka-mezera-xs); }
.ka-rezervace-mesic { display: flex; align-items: center; justify-content: space-between; gap: var(--ka-mezera-xs); font-weight: 600; }
.ka-rezervace-mesic button { padding: 0.3em 0.7em; border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); color: inherit; font: inherit; cursor: pointer; }
.ka-rezervace-dny { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
.ka-rezervace-dny span, .ka-rezervace-dny button { display: grid; place-items: center; min-height: 2.4em; border: 0; border-radius: var(--ka-zaobleni); background: none; color: inherit; font: inherit; }
.ka-rezervace-dny span { color: var(--ka-barva-tlumeny); font-size: var(--ka-krok--1); }
.ka-rezervace-dny button { background: var(--ka-barva-plocha); cursor: pointer; }
.ka-rezervace-dny button:disabled { background: none; color: var(--ka-barva-tlumeny); cursor: default; text-decoration: line-through; }
.ka-rezervace-dny button[aria-pressed="true"], .ka-rezervace-casy button[aria-pressed="true"] { background: var(--ka-barva-primarni); color: var(--ka-barva-na-primarni); }
.ka-rezervace-casy { display: flex; flex-wrap: wrap; gap: var(--ka-mezera-2xs); }
.ka-rezervace-casy button { padding: 0.5em 0.9em; border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); color: inherit; font: inherit; cursor: pointer; }
.ka-rezervace-vybrano { margin: 0; font-weight: 600; }
.ka-rezervace .ka-pole { display: grid; gap: var(--ka-mezera-2xs); margin: 0; }
.ka-rezervace .ka-pole > label { font-weight: 600; }
.ka-rezervace .ka-pole input:not([type="checkbox"]):not([type="radio"]), .ka-rezervace .ka-pole select, .ka-rezervace .ka-pole textarea { box-sizing: border-box; width: 100%; padding: 0.7em 0.9em; border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); color: var(--ka-barva-text); font: inherit; }
.ka-rezervace .ka-pole-souhlas label { display: flex; gap: var(--ka-mezera-xs); align-items: flex-start; font-weight: 400; }
.ka-rezervace .ka-pole-souhlas input { margin-block-start: 0.3em; accent-color: var(--ka-barva-primarni); }
.ka-rezervace .ka-povinne { color: var(--ka-barva-primarni); }
.ka-rezervace-chyba, .ka-rezervace-hotovo { margin: 0; padding: var(--ka-mezera-m); border-radius: var(--ka-zaobleni); }
.ka-rezervace-hotovo { background: var(--ka-barva-primarni-jemna); color: var(--ka-barva-text); }
.ka-rezervace-chyba { background: color-mix(in oklch, #c4281c 12%, var(--ka-barva-pozadi)); color: color-mix(in oklch, #c4281c 80%, var(--ka-barva-text)); }
.ka-rezervace-prazdne { margin: 0; color: var(--ka-barva-tlumeny); }
.ka-rezervace [hidden] { display: none !important; }'; // the display of the calendar, the times and the fallback field would otherwise beat the hidden attribute
    }

    /** The anchor the page returns to after sending: the same as the id the form gets when rendered. */
    public static function anchor(array $p): string
    {
        return $p['kotva'] ?? (!empty($p['styl']) ? 's-' . $p['id'] : 'rezervace-' . $p['id']);
    }

    /** The message after sending by the code in the url (?booking=<id>&result=<code>) – the text never comes from the url. */
    public static function messages(string $code): string
    {
        return match ($code) {
            'obsazeno' => t('Sorry, this time has just been taken. Please choose another one.'),
            'slot' => t('Please choose a day and a time.'),
            'service' => t('Please choose a service.'),
            'name' => t('Please fill in your name.'),
            'email' => t('Enter a valid e-mail address.'),
            'phone' => t('Phone number, for example +44 20 7946 0958.'),
            'souhlas' => t('Please tick the consent.'),
            'limit' => t('Too many bookings have come from your address in a short time. Please try again later.'),
            'rychle' => t('The form was sent before we could check that a person is sending it. Please wait a moment and send it again.'),
            'captcha' => t('Please confirm that you are not a robot and send the form again.'),
            default => t('The form could not be verified. Reload the page and try again.'),
        };
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $r = $k->app->request;
        $db = $k->app->db();
        $result = $r->get('booking') === $p['id'] ? $r->get('result') : '';
        $id = str_contains($a, ' id="') ? '' : ' id="' . e(self::anchor($p)) . '"';
        if ($result === 'pending') { // a service that needs the provider's confirmation: a request, not a booking (3.3)
            return '<div' . Text::withClass($a, 'ka-rezervace-hotovo') . $id . ' role="status" data-odeslano="' . e(t('Booking')) . '"><p>' . e(Bookings::pendingThanks($k->app->settings())) . '</p></div>';
        }
        if ($result === 'ok') {
            return '<div' . Text::withClass($a, 'ka-rezervace-hotovo') . $id . ' role="status" data-odeslano="' . e(t('Booking')) . '"><p>' . e($o['dekujeme']) . '</p></div>';
        }
        $services = Bookings::services($db);
        $staff = Bookings::staff($db);
        $fixedService = (int) $o['sluzba'] > 0 ? Bookings::service($db, (int) $o['sluzba'], true) : null;
        if ($fixedService !== null) {
            $services = [$fixedService];
        }
        $services = array_values(array_filter($services, fn (array $s): bool => Bookings::staffFor($db, $s['id']) !== []));
        if ($services === []) {
            return $k->editor ? '<div' . Text::withClass($a, 'ka-rezervace-prazdne') . '><p>' . e(t('Add a service and a person who offers it in Administration → Bookings; the form appears here.')) . '</p></div>' : '';
        }
        // the plain form (no script) shows the next free times of one service: the only one, the fixed one, or the one asked for
        $chosen = $r->get('booking') === $p['id'] && $r->getInt('service') > 0 ? $r->getInt('service') : (count($services) === 1 ? $services[0]['id'] : 0);
        $chosenService = null;
        foreach ($services as $s) {
            if ($s['id'] === $chosen) {
                $chosenService = $s;
            }
        }
        $fixedStaff = (int) $o['osoba'] > 0 ? Bookings::member($db, (int) $o['osoba'], true) : null;
        if (!$k->editor) {
            $k->withoutCache = true; // the free times change with every booking
        }
        $html = $result !== '' ? '<p class="ka-rezervace-chyba" role="alert">' . e(self::messages($result)) . '</p>' : '';
        $name = 'r-' . $p['id'];

        // 1. the service
        $html .= '<fieldset class="ka-rezervace-krok" data-krok="sluzba"><legend>' . e(t('Service')) . '</legend><div class="ka-rezervace-volby">';
        foreach ($services as $i => $s) {
            $meta = implode(' · ', array_filter([t('%d min', $s['duration_min']), $s['price_text']]));
            $html .= '<label><input type="radio" name="service" value="' . $s['id'] . '" required data-trvani="' . $s['duration_min'] . '"' . (!empty($s['requires_confirmation']) ? ' data-potvrzeni="1"' : '') . ($s['id'] === ($chosenService['id'] ?? ($fixedService !== null || count($services) === 1 ? $s['id'] : 0)) ? ' checked' : '') . '>'
                . '<span>' . e($s['name']) . '<small>' . e($meta) . ($s['description'] !== '' ? ' – ' . e($s['description']) : '') . '</small></span></label>';
        }
        $html .= '</div></fieldset>';

        // 2. the person – only when there is a choice
        $offering = array_values(array_filter($staff, fn (array $m): bool => array_intersect($m['services'], array_column($services, 'id')) !== []));
        if ($fixedStaff !== null) {
            $html .= '<input type="hidden" name="staff" value="' . $fixedStaff['id'] . '">';
        } elseif (count($offering) > 1) {
            $html .= '<fieldset class="ka-rezervace-krok" data-krok="osoba"><legend>' . e(t('Who')) . '</legend><div class="ka-rezervace-volby">'
                . '<label><input type="radio" name="staff" value="0" checked><span>' . e(t('Anyone available')) . '</span></label>';
            foreach ($offering as $m) {
                $html .= '<label data-sluzby="' . e(implode(',', $m['services'])) . '"><input type="radio" name="staff" value="' . $m['id'] . '"><span>' . e($m['name']) . '</span></label>';
            }
            $html .= '</div></fieldset>';
        } else {
            $html .= '<input type="hidden" name="staff" value="0">';
        }

        // 3. the day and the time: the calendar (script) and the plain select
        $html .= '<fieldset class="ka-rezervace-krok" data-krok="cas"><legend>' . e(t('Day and time')) . '</legend>'
            . '<div class="ka-rezervace-kalendar" data-kalendar hidden></div>'
            . '<div class="ka-rezervace-casy" data-casy hidden></div>'
            . '<p class="ka-rezervace-vybrano" data-vybrano hidden></p>'
            . '<div class="ka-pole" data-bez-skriptu>';
        if ($chosenService !== null) {
            $options = '';
            $staffId = $fixedStaff !== null ? $fixedStaff['id'] : 0;
            foreach (self::nextSlots($k->app, $chosenService, $staffId) as $slot) {
                $options .= '<option value="' . e($slot) . '">' . e(format_date($slot, true)) . '</option>';
            }
            $html .= '<label for="' . $name . '-slot">' . e(t('Free times')) . ' <span class="ka-povinne" aria-hidden="true">*</span></label>'
                . ($options === '' ? '<p class="ka-rezervace-prazdne">' . e(t('There are no free times at the moment. Please contact us.')) . '</p><select id="' . $name . '-slot" name="slot" hidden></select>'
                    : '<select id="' . $name . '-slot" name="slot" required><option value="">' . e(t('— choose —')) . '</option>' . $options . '</select>');
        } else {
            $html .= '<select name="slot" hidden></select><button class="ka-tlacitko" type="submit" formmethod="get" formaction="' . e($k->app->url($r->path())) . '" name="booking" value="' . e($p['id']) . '">' . e(t('Show free times')) . '</button>';
        }
        $html .= '</div></fieldset>';

        // 4. the contact
        $html .= '<fieldset class="ka-rezervace-krok" data-krok="kontakt"><legend>' . e(t('Your details')) . '</legend>'
            . '<p class="ka-pole"><label for="' . $name . '-jmeno">' . e(t('Your name')) . ' <span class="ka-povinne" aria-hidden="true">*</span></label><input id="' . $name . '-jmeno" name="jmeno" type="text" autocomplete="name" maxlength="150" required></p>'
            . '<p class="ka-pole"><label for="' . $name . '-email">' . e(t('Your e-mail')) . ' <span class="ka-povinne" aria-hidden="true">*</span></label><input id="' . $name . '-email" name="email" type="email" autocomplete="email" maxlength="190" required></p>'
            . '<p class="ka-pole"><label for="' . $name . '-telefon">' . e(t('Phone')) . '</label><input id="' . $name . '-telefon" name="telefon" type="tel" autocomplete="tel" maxlength="30" pattern="' . Form::PHONE_PATTERN . '" title="' . e(t('Phone number, for example +44 20 7946 0958.')) . '"></p>'
            . '<p class="ka-pole"><label for="' . $name . '-poznamka">' . e(t('Note')) . '</label><textarea id="' . $name . '-poznamka" name="poznamka" rows="3" maxlength="1000"></textarea></p>'
            . '<p class="ka-pole ka-pole-souhlas"><label><input type="checkbox" name="souhlas" value="1" required> <span>' . e($o['souhlas']) . ' <span class="ka-povinne" aria-hidden="true">*</span></span></label>'
            . (($policy = \Kaleta\Core\Privacy::policyUrl($k->app->settings())) !== '' ? ' <a class="ka-pole-zasady" href="' . e($policy) . '" target="_blank">' . e(t('Privacy policy')) . '</a>' : '') . '</p>'
            . '</fieldset>';

        // a service that needs confirmation is requested, not booked: the default button says so (the script follows the chosen service)
        $buttonText = $o['tlacitko'];
        $buttonData = '';
        if ($o['tlacitko'] === t('Book the appointment') && array_filter($services, fn (array $s): bool => !empty($s['requires_confirmation'])) !== []) {
            $buttonData = ' data-zadost="' . e(t('Request this time')) . '" data-rezervovat="' . e($o['tlacitko']) . '"';
            $selected = $chosenService ?? ($fixedService !== null || count($services) === 1 ? $services[0] : null);
            $buttonText = $selected !== null && !empty($selected['requires_confirmation']) ? t('Request this time') : $buttonText;
        }
        $antispam = new Antispam($db, $k->app->settings());
        $k->types['tlacitko'] = true; // the button looks like the Button element

        return '<form' . Text::withClass($a, 'ka-rezervace') . $id . ' method="post" action="' . e($k->url('_booking')) . '" data-rezervace="' . e($p['id']) . '" data-dny="' . e($k->url('_booking/days')) . '" data-sloty="' . e($k->url('_booking/slots')) . '">'
            . '<input type="hidden" name="zdroj" value="' . e($k->source) . '"><input type="hidden" name="prvek" value="' . e($p['id']) . '">'
            . '<input type="hidden" name="zpet" value="' . e($k->app->url($r->path())) . '">'
            . $antispam->fields('rezervace|' . $k->source . '|' . $p['id'])
            . $html
            . Form::captcha($k)
            . '<p class="ka-pole"><button class="ka-tlacitko ka-tlacitko--primarni" type="submit"' . $buttonData . '>' . e($buttonText) . '</button></p></form>';
    }

    /**
     * The next free times of a service for the plain form: day by day from today until enough are found or the horizon ends.
     *
     * @param array<string, mixed> $service
     * @return list<string> "YYYY-MM-DD HH:MM"
     */
    private static function nextSlots(\Kaleta\Core\App $app, array $service, int $staffId): array
    {
        $out = [];
        $now = new \DateTimeImmutable();
        $horizon = $app->settings()->int('booking_horizon_days');
        for ($i = 0; $i <= $horizon && count($out) < self::FALLBACK_SLOTS; $i++) {
            $day = $now->modify('+' . $i . ' days')->format('Y-m-d');
            foreach (array_keys(Bookings::availability($app, $service, $staffId, $day, $now)) as $time) {
                $out[] = $day . ' ' . $time;
                if (count($out) >= self::FALLBACK_SLOTS) {
                    break;
                }
            }
        }

        return $out;
    }
}
