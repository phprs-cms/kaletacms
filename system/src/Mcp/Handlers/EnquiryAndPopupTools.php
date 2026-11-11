<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Admin\Modules\Media;
use Kaleta\Admin\Modules\Categories;
use Kaleta\Admin\Modules\Pages;
use Kaleta\Core\App;
use Kaleta\Core\Language;
use Kaleta\Front\SiteIdentity;
use Kaleta\Builder\SiteParts;
use Kaleta\Builder\DesignSystem;
use Kaleta\Builder\Library;
use Kaleta\Builder\Collections;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;
use Kaleta\Builder\HtmlConverter;

/**
 * MCP tools: enquiries and pop-ups (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait EnquiryAndPopupTools
{
    /** list_enquiries (seznam_poptavek) */
    private function toolListEnquiries(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();

        if (!\Kaleta\Core\Extensions::isEnabled($siteSettings, 'poptavky') || !$auth->hasModule('enquiries')) {
            throw new \DomainException('Poptávky smí číst jen uživatel s právem k Poptávkám (rozšíření Formuláře a poptávky musí být zapnuté).');
        }
        $whereParts = [];
        $params = [];
        $statuses = ['nove' => 0, 'prectene' => 1, 'vyrizene' => 2];
        if (isset($statuses[$a['stav'] ?? ''])) {
            $whereParts[] = 'stav = ?';
            $params[] = $statuses[$a['stav']];
        }
        if (($a['hledat'] ?? '') !== '') {
            $whereParts[] = '(email LIKE ? OR data LIKE ?)';
            $search = '%' . addcslashes((string) $a['hledat'], '%_\\') . '%';
            array_push($params, $search, $search);
        }
        // the kind from triage (2.12): unsorted = not sorted yet; without a kind spam is left out
        $kind = (string) ($a['kategorie'] ?? '');
        if ($kind === 'unsorted') {
            $whereParts[] = "kategorie = ''";
        } elseif (isset(\Kaleta\Core\Triage::CATEGORIES[$kind])) {
            $whereParts[] = 'kategorie = ?';
            $params[] = $kind;
        } else {
            $whereParts[] = "kategorie <> 'spam'";
        }
        // 3.34 review M3, second review N6: the job enquiries migration 0120 has still to move stay out for a person without
        // Applications, a moved twin for everyone
        $whereParts[] = \Kaleta\Admin\Modules\Enquiries::visible($db, $siteSettings, $auth->hasModule(\Kaleta\Core\Applications::SECTION));
        $limit = max(1, min(50, (int) ($a['limit'] ?? 20)));
        $statusNames = array_flip($statuses);

        $rows = $db->all('SELECT idp, datum, formular, stranka, tema, vstup, odkud, kampan, email, stav, kategorie, priorita, navrh_odpovedi, triaged_by, data, prirazeno FROM {poptavky} WHERE ' . implode(' AND ', $whereParts) . ' ORDER BY idp DESC LIMIT ' . $limit, $params);
        $notes = \Kaleta\Core\Comments::countEnquiries($db, array_map('intval', array_column($rows, 'idp'))); // 3.25
        // 3.34: with Applications on, an enquiry sorted as a job application points there (additive key, only on those rows)
        $applicationsHint = \Kaleta\Core\Applications::isOn($siteSettings)
            ? 'Job applications live in Applications on this site (list_applications). A person moves this one there with "Move to Applications" on its detail; set the form\'s Purpose to "Job application" so the next ones arrive there.'
            : null;

        // about (2.12): what the form was about – the collection item, page or pop-up it was on (Front\EnquiryTopic)
        return array_map(fn (array $p): array => ['id' => (int) $p['idp'], 'datum' => substr((string) $p['datum'], 0, 16), 'formular' => $p['formular'], 'stranka' => $p['stranka'], 'about' => $p['tema'] !== '' ? $p['tema'] : null,
            'kampan' => \Kaleta\Front\Forms::campaignText((string) $p['kampan']), 'first_page' => $p['vstup'] !== '' ? $p['vstup'] : null, 'came_from' => $p['odkud'] !== '' ? $p['odkud'] : null, 'email' => $p['email'], 'stav' => $statusNames[(int) $p['stav']] ?? '',
            'pole' => array_map(fn (array $d): array => ['popisek' => $d[0], 'hodnota' => $d[1]], json_decode((string) $p['data'], true) ?: [])]
            + ($p['kategorie'] !== '' ? ['category' => $p['kategorie'], 'priority' => \Kaleta\Core\Triage::PRIORITIES[(int) $p['priorita']] ?? null,
                'draft_reply' => $p['navrh_odpovedi'] ?: null, 'triaged_by' => in_array($p['triaged_by'], ['claude', 'assistant', 'rule'], true) ? $p['triaged_by'] : 'person'] : [])
            // 3.24: who handles it ({id, name}; a removed account is "(former user)"), only when someone does
            + ($p['prirazeno'] !== null ? ['assignee' => \Kaleta\Core\Handover::person($db, $p['prirazeno'])] : [])
            // 3.25: how many internal notes the team wrote on it (Core\Comments), only when there are some
            + (($notes[(int) $p['idp']] ?? 0) > 0 ? ['notes_count' => $notes[(int) $p['idp']]] : [])
            + ($applicationsHint !== null && $p['kategorie'] === 'job' ? ['applications_hint' => $applicationsHint] : []),
            $rows);
    }

    /** update_enquiry and delete_enquiry */
    private function toolUpdateEnquiry(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->hasModule('enquiries'), 'Enquiries can be changed only by users with the Enquiries section.');
        // 3.34 second review N6: nothing still moving to Applications for a person without them, never a moved twin
        $enquiry = $db->one('SELECT idp, data FROM {poptavky} WHERE idp = ? AND ' . \Kaleta\Admin\Modules\Enquiries::visible($db, $this->app->settings(), $auth->hasModule(\Kaleta\Core\Applications::SECTION)), [$id])
            ?? throw new \InvalidArgumentException('The enquiry does not exist. Use list_enquiries.');
        if ($name === 'delete_enquiry') {
            \Kaleta\Admin\Modules\Enquiries::deleteAttachments([$enquiry], $db);
            \Kaleta\Core\Deals::forgetEnquiries($db, [$id]); // N331-2: a deal from it stays, without the link and (3.32) the person's words – before the row goes
            $db->delete('poptavky', ['idp' => $id]);
            \Kaleta\Core\Comments::forgetEnquiries($db, [$id]); // 3.25: its internal notes too

            return ['deleted' => $id];
        }
        // a drafts-only connection (3.2) only suggests the triage – the status and the internal note are a person's
        if ($auth->draftsOnly() && (isset($a['status']) || isset($a['note']) || isset($a['add_note']) || array_key_exists('assignee', $a))) {
            throw new \DomainException('This connection can only save drafts: for an enquiry that is its triage – category, priority and draft_reply – as a suggestion a person checks. '
                . 'The status, the internal note and who handles it are set by a person (or a connection with full access); put what you would change into the note of the request or the summary of the run.');
        }
        $changes = [];
        if (isset($a['status'])) {
            $status = ['new' => 0, 'read' => 1, 'resolved' => 2][(string) $a['status']] ?? throw new \InvalidArgumentException('status must be new, read or resolved.');
            $changes['stav'] = $status;
        }
        if (isset($a['note'])) {
            $changes['poznamka'] = mb_substr(trim((string) $a['note']), 0, 5000);
        }
        // 3.24: who handles it (Core\Handover – a user with the Enquiries section; the assignment e-mail goes from there)
        $assignee = array_key_exists('assignee', $a) ? \Kaleta\Core\Handover::resolve($db, $a['assignee'], $auth->id()) : false;
        if (is_int($assignee) && !\Kaleta\Core\Handover::mayOpen($db, $this->app->settings(), $assignee, 'enquiries')) {
            throw new \DomainException('This user cannot open Enquiries (or the account is blocked) – nothing was changed. Give the enquiry to someone with the Enquiries section.');
        }
        // 3.25: add_note appends a note as the connected user (Core\Comments) – the earlier ones stay as they were
        if (isset($a['add_note'])) {
            if (!is_string($a['add_note']) || \Kaleta\Core\Comments::clean($a['add_note']) === '') {
                throw new \InvalidArgumentException('add_note must be the text of the note.');
            }
        }
        if ($changes !== []) {
            $db->update('poptavky', $changes, ['idp' => $id]);
        }
        if (isset($a['add_note'])) {
            \Kaleta\Core\Comments::add($db, \Kaleta\Core\Comments::enquiry($id), (string) $a['add_note'], $auth->user() ?? [], 'mcp');
            $changes['note_added'] = true;
        }
        if ($assignee !== false && \Kaleta\Core\Handover::assign($this->app, 'enquiry', $id, $assignee)['changed']) {
            $changes['assignee'] = true;
        }
        if (isset($a['category']) || isset($a['priority']) || isset($a['draft_reply'])) {
            $triage = \Kaleta\Core\Triage::clean($a['category'] ?? null, isset($a['priority']) ? ['high' => 3, 'normal' => 2, 'low' => 1][(string) $a['priority']] ?? 0 : null, $a['draft_reply'] ?? null);
            if (isset($a['category']) && $triage['kategorie'] === null) {
                throw new \InvalidArgumentException('category must be one of: ' . implode(', ', array_keys(\Kaleta\Core\Triage::CATEGORIES)) . '.');
            }
            if (\Kaleta\Core\Triage::save($db, $id, $triage, 'claude')) {
                $changes['triage'] = true;
            } else {
                return ['id' => $id, 'changed' => array_keys($changes), 'note' => 'A person sorted this enquiry already – their triage stays.'];
            }
        }

        return ['id' => $id, 'changed' => array_keys($changes)];
    }

    /** request_testimonial (2.12) */
    private function toolRequestTestimonial(string $name, array $a): mixed
    {
        if (!$this->app->auth()->hasModule('enquiries')) {
            throw new \DomainException('Enquiries can be changed only by users with the Enquiries section.');
        }
        $result = \Kaleta\Core\Testimonials::request($this->app, (int) ($a['id'] ?? 0), !empty($a['send']));

        return $result + ['next' => $result['sent'] ? 'The customer got the link by e-mail. Their answer will be a hidden draft in References – publish it when the user approves.'
            : 'Pass the link to the customer (it works once, for 30 days). Their answer will be a hidden draft in References.'];
    }

    /** reply_to_enquiry (3.22, C10): a real e-mail to the enquiry's own address, only with confirm=true */
    private function toolReplyToEnquiry(string $name, array $a): mixed
    {
        if (!$this->app->auth()->hasModule('enquiries')) {
            throw new \DomainException('Enquiries can be changed only by users with the Enquiries section.');
        }
        if (($a['confirm'] ?? false) !== true) {
            throw new \InvalidArgumentException('Sending a reply needs confirm=true – it is a real e-mail to the customer: only when the user read the text and asked to send it now.');
        }
        $result = \Kaleta\Core\EnquiryReply::send($this->app, (int) ($a['id'] ?? 0), is_string($a['subject'] ?? null) ? $a['subject'] : '', is_string($a['text'] ?? null) ? $a['text'] : '',
            ($a['keep_open'] ?? false) !== true, 'mcp');

        return ['id' => (int) ($a['id'] ?? 0)] + $result + ['next' => $result['status'] === 'sent'
            ? 'Tell the user the reply went out. It is in the enquiry\'s history in the administration.'
            : 'Tell the user the mail server refused it for now (' . $result['error'] . '); the site retries from its mail queue – the enquiry\'s history shows when it goes out.'];
    }

    /** find_personal_data (2.14): counts and enquiry IDs for one address, never the content */
    private function toolFindPersonalData(string $name, array $a): mixed
    {
        $email = $this->personalDataEmail($a);
        $found = \Kaleta\Core\PersonalData::find($this->app->db(), $email, $this->app);

        return ['email' => $email, 'found' => \Kaleta\Core\PersonalData::counts($found), 'enquiry_ids' => array_map('intval', array_column($found['enquiries'], 'idp')),
            'next' => 'Tell the user what was found. The file for the person: Enquiries → Personal data request in the administration. Erase only when the user asks: erase_personal_data.'];
    }

    /** erase_personal_data (2.14) */
    private function toolErasePersonalData(string $name, array $a): mixed
    {
        $email = $this->personalDataEmail($a);
        if (($a['confirm'] ?? false) !== true) {
            throw new \InvalidArgumentException('Erasing needs confirm=true – only when the user asked for it.');
        }
        $result = \Kaleta\Core\PersonalData::erase($this->app, $email);

        return $result + ['note' => trim(($result['kept_testimonials'] !== [] ? 'Published testimonials of the person stay (collection items ' . implode(', ', $result['kept_testimonials']) . ') – ask the user whether to remove them too.' : '')
            . (($result['erased']['applications_held'] ?? 0) > 0 ? ' ' . $result['erased']['applications_held'] . ' job application(s) were not erased: their CV list cannot be opened (encryption key) and their files cannot be located – tell the user to put the vault_key line back into config.php, then erase again.' : ''))];
    }

    private function personalDataEmail(array $a): string
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('Personal data requests are handled only by administrators.');
        }
        $email = \Kaleta\Core\PersonalData::normalise(is_string($a['email'] ?? null) ? $a['email'] : '');
        if ($email === null) {
            throw new \InvalidArgumentException('email must be a valid e-mail address.');
        }

        return $email;
    }

    /** triage_enquiries (2.12): the unsorted enquiries as text to sort */
    private function toolTriageEnquiries(string $name, array $a): mixed
    {
        if (!\Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'poptavky') || !$this->app->auth()->hasModule('enquiries')) {
            throw new \DomainException('Enquiries can be read only by users with the Enquiries section.');
        }
        $limit = max(1, min(20, (int) ($a['limit'] ?? 10)));
        $rows = $this->app->db()->all("SELECT * FROM {poptavky} WHERE kategorie = '' AND " . \Kaleta\Admin\Modules\Enquiries::visible($this->app->db(), $this->app->settings(), $this->app->auth()->hasModule(\Kaleta\Core\Applications::SECTION))
            . ' ORDER BY idp DESC LIMIT ' . $limit);

        return ['enquiries' => array_map(fn (array $p): array => ['id' => (int) $p['idp'], 'date' => substr((string) $p['datum'], 0, 16), 'text' => \Kaleta\Core\Triage::text($p)], $rows),
            'categories' => array_keys(\Kaleta\Core\Triage::CATEGORIES), 'priorities' => ['high', 'normal', 'low'],
            'next' => $rows === [] ? 'Every enquiry is sorted.' : 'For each: update_enquiry {id, category, priority, draft_reply}. Spam gets no reply. Never promise prices, dates or facts the site does not state; the user checks and sends every reply.'];
    }

    /** delete_enquiry: the same as update_enquiry */
    private function toolDeleteEnquiry(string $name, array $a): mixed
    {
        return $this->toolUpdateEnquiry($name, $a);
    }

    /** list_popups (seznam_popupu) */
    private function toolListPopups(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        if (!$auth->hasAppearanceArea()) { // 3.22 (C9): administrators and the Appearance area
            throw new \DomainException('Pop-ups are for administrators and users with the Appearance area.');
        }

        return array_map($this->popup(...), \Kaleta\Builder\Popups::all($db));
    }

    /** save_popup (uloz_popup) */
    private function toolSavePopup(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        if (!$auth->hasAppearanceArea() || !$auth->canPublish()) { // 3.22 (C9): the settings of a pop-up apply at once
            throw new \DomainException('Pop-ups can be changed only by an administrator, or an editor with the Appearance area.');
        }

        return $this->popup($this->savePopup($a), true);
    }

    /** delete_popup */
    private function toolDeletePopup(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->hasAppearanceArea() && $auth->canPublish(), 'Pop-ups can be deleted only by an administrator, or an editor with the Appearance area.'); // 3.22 (C9)
        $need($db->delete('popupy', ['idpp' => $id]) > 0, 'The pop-up does not exist. Use list_popups.');
        \Kaleta\Front\Cache::clear();

        return ['deleted' => $id];
    }
}
