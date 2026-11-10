<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The words of the Workspace (3.32, U38/U39): one term per idea on every admin screen, in every language. tools/unit-tests.php
 * checks the dictionaries against the words it pins for TERMS and that no screen uses a RETIRED key any more. Only the visible texts – MCP tool
 * names, parameters, Czech aliases and the data model never change with it.
 *
 *  - The person who takes care of a record is the "responsible person" everywhere: a page, an item, a customer, a deal, an
 *    enquiry (it was "Handled by") and a task (it was "For"); a notification says "… made you responsible".
 *  - The people of the CRM are "Customers" (the menu, the list, "New customer"); the list's tabs are "People" and "Companies".
 *  - A deal is "Obchod" in Czech, so the enquiry kind Sales is "Prodej"; a lost stage is "ztracená" in every Czech sentence.
 */
final class Glossary
{
    /** The English keys of the terms; tools/unit-tests.php pins what cs, de and es call each (the expected words live there –
     * no Czech literal outside the dictionaries). */
    public const array TERMS = ['Responsible person', 'Customers', 'Customer', 'New customer', 'People', 'Companies', 'Deal', 'Deals', 'Sales', 'Won', 'Lost',
        '%s made you responsible'];

    /** English keys no screen uses any more (the term that replaced each). */
    public const array RETIRED = [
        'Handled by' => 'Responsible person',
        'For' => 'Responsible person',
        'Enquiries you handle' => 'Enquiries you are responsible for',
        '%s assigned it to you' => '%s made you responsible',
        '%s assigned you %s' => '%s made you responsible for %s',
        'Contacts' => 'People',
        'New contact' => 'New customer',
        'New contact here' => 'New customer here',
    ];

    /** The languages the glossary is pinned in. */
    public const array LANGUAGES = ['cs', 'de', 'es'];
}
