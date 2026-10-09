<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Settings keys of Kaleta 1.4.0 and older => current keys. Since 2.0 the settings answer only to the current keys; the old
 * ones are still needed in three places:
 *
 *  - updates: a site updated from 1.4.0 or older finishes the update request with its own code, which may write its old
 *    keys (verze_db…) once more after migration 0026 renamed them – adopt() moves them, Migration reads verze_db;
 *  - the Claude connection: update_settings keeps accepting old keys, so no connection breaks;
 *  - an export of 1.4.0 or older carries old keys (Core\SiteImport).
 */
final class OldSettingsKeys
{
    /** @var array<string, string> */
    public const array KEYS = [
        'nazev_webu' => 'site_name',
        'adresa_webu' => 'site_url',
        'popis_webu' => 'site_description',
        'klicova_slova' => 'keywords',
        'email_webu' => 'site_email',
        'logo_webu' => 'logo',
        'firma_nazev' => 'company_name',
        'firma_typ' => 'company_type',
        'firma_ico' => 'company_id',
        'firma_dic' => 'company_vat_id',
        'firma_rejstrik' => 'company_register',
        'firma_zastupce' => 'company_representative',
        'firma_ulice' => 'company_street',
        'firma_mesto' => 'company_city',
        'firma_psc' => 'company_postcode',
        'firma_zeme' => 'company_country',
        'firma_telefon' => 'company_phone',
        'firma_email' => 'company_email',
        'firma_hodiny' => 'company_hours',
        'firma_mapa' => 'company_map',
        'firma_gps' => 'company_gps',
        'poptavky_mesice' => 'enquiries_months',
        'brand_akcent' => 'brand_accent',
        'tmavy_rezim' => 'dark_mode',
        'tmavy_prepinac' => 'theme_switcher',
        'brand_pismo_titulky' => 'brand_heading_font',
        'brand_pismo_text' => 'brand_text_font',
        'text_paticky' => 'footer_text',
        'soc_facebook' => 'social_facebook',
        'soc_instagram' => 'social_instagram',
        'soc_x' => 'social_x',
        'soc_youtube' => 'social_youtube',
        'soc_linkedin' => 'social_linkedin',
        'casove_pasmo' => 'time_zone',
        'jazyk_webu' => 'site_language',
        'jazyky_dalsi' => 'additional_languages',
        'titulni_stranka' => 'home_page',
        'pocet_clanku' => 'news_per_page',
        'udrzba' => 'maintenance',
        'udrzba_text' => 'maintenance_text',
        'webhook_poptavky' => 'webhook_enquiries',
        'vynutit_2fa' => 'require_2fa',
        'cache_stranek' => 'page_cache',
        'kontrola_odkazu' => 'link_check',
        'kontrola_odkazu_cas' => 'link_check_time',
        'sdileni' => 'share_buttons',
        'osnova_clanku' => 'article_outline',
        'souvisejici_auto' => 'related_news_auto',
        'ulohy_token' => 'tasks_token',
        'statistika' => 'stats',
        'tajny_klic' => 'secret_key',
        'indexovani' => 'indexing',
        'og_obrazek' => 'share_image',
        'overeni_google' => 'verification_google',
        'overeni_bing' => 'verification_bing',
        'ai_crawlery' => 'ai_crawlers',
        'markdown_clanky' => 'markdown_news',
        'indexnow_klic' => 'indexnow_key',
        'plausible_domena' => 'plausible_domain',
        'kod_hlava' => 'head_code',
        'cookies_rezim' => 'cookies_mode',
        'cookies_externi_kod' => 'cookies_external_code',
        'cookies_zasady_url' => 'cookies_policy_url',
        'kod_marketing' => 'marketing_code',
        'cookies_evidence' => 'cookies_log',
        'cookies_evidence_mesice' => 'cookies_log_months',
        'stav_token' => 'health_token',
        'zaloha_vzdalena' => 'remote_backup',
        'zaloha_host' => 'backup_host',
        'zaloha_uzivatel' => 'backup_user',
        'zaloha_heslo' => 'backup_password',
        'zaloha_slozka' => 'backup_folder',
        'zaloha_region' => 'backup_region',
        'zaloha_vzdalena_stav' => 'remote_backup_status',
        'zalohy_auto' => 'auto_backups',
        'aktualizace_url' => 'update_url',
        'aktualizace_cache' => 'update_cache',
        'aktualizace_auto' => 'auto_updates',
        'aktualizace_pokus' => 'update_attempt',
        'rozsireni' => 'extensions',
        'posta_rezim' => 'mail_mode',
        'posta_od' => 'mail_from',
        'posta_odpoved' => 'mail_reply_to',
        'smtp_sifrovani' => 'smtp_encryption',
        'smtp_uzivatel' => 'smtp_user',
        'smtp_heslo' => 'smtp_password',
        'oznameni_kontrola' => 'notification_check',
        'uklid_udaju' => 'data_cleanup',
        'ai_poskytovatel' => 'ai_provider',
        'ai_klic' => 'ai_key',
        'pruvodce_skryt' => 'first_steps_hidden',
        'vzhled_ulozen' => 'appearance_saved',
        'uklizeno_verze' => 'cleaned_version',
        'verze_db' => 'db_version',
        'newsletter_klic' => 'newsletter_key',
        'newsletter_seznam' => 'newsletter_list',
        'newsletter_sluzba' => 'newsletter_service',
    ];

    /** The current key of an old one (also with a language suffix: nazev_webu_en → site_name_en); the key itself when it is current. */
    public static function current(string $key): string
    {
        if (isset(self::KEYS[$key])) {
            return self::KEYS[$key];
        }

        return ($m = Language::settingKey($key, ['nazev_webu', 'popis_webu'])) !== null ? self::KEYS[$m[0]] . '_' . $m[1] : $key;
    }

    /**
     * Moves rows still stored under an old key to the current key and deletes them. Such a row is newer than the current
     * one: the release that ran an update writes its own keys again after migration 0026 renamed them (the migration
     * number above all).
     */
    public static function adopt(Db $db): void
    {
        $keys = array_keys(self::KEYS);
        $rows = $db->pairs('SELECT promenna, hodnota FROM {nastaveni} WHERE promenna IN (' . implode(',', array_fill(0, count($keys), '?')) . ") OR promenna LIKE 'nazev\\_webu\\_%' OR promenna LIKE 'popis\\_webu\\_%'", $keys);
        foreach ($rows as $old => $value) {
            $current = self::current((string) $old);
            if ($current === $old) {
                continue;
            }
            if ($current === 'db_version') {
                $value = (string) max((int) $value, (int) $db->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'db_version'"));
            }
            $db->run('INSERT INTO {nastaveni} (promenna, hodnota) VALUES (?, ?) ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)', [$current, $value]);
            $db->run('DELETE FROM {nastaveni} WHERE promenna = ?', [$old]);
        }
    }
}
