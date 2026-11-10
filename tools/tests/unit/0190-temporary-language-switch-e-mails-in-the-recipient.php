<?php

declare(strict_types=1);

/* ---------- temporary language switch (e-mails in the recipient's language) ---------- */
Kaleta\Core\Language::set('cs');
check('Jazyk::docasne: uvnitř platí cizí jazyk', Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Core\Language::code() . '|' . t('Číst článek →')), 'en|Read article →');
check('Jazyk::docasne: potom se jazyk vrátí', Kaleta\Core\Language::code() . '|' . t('Číst článek →'), 'cs|Číst článek →');
try {
    Kaleta\Core\Language::runWith('en', function (): never { throw new RuntimeException('x'); });
} catch (RuntimeException) {
}
check('Jazyk::docasne: jazyk se vrátí i po výjimce', Kaleta\Core\Language::code(), 'cs');

