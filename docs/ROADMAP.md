# Kaleta roadmap

What is planned next. Dates are not promised; releases ship when they are tested.

## 1.2 – languages for the EU and beyond (ready, not yet released)

- Around forty site languages; visitor texts translated into Czech, English, German, French, Spanish, Italian, Polish and
  Slovak, other languages fall back to English with dates in their own format.
- System addresses in the site language (`/news`, `/search`), old addresses redirect permanently.
- Site language chosen in the installer; imprint (Impressum) template and company fields.
- Language switcher and light / dark / device-based look with a switcher for visitors.
- Admin: distinct menu icons, chart tooltips, GitHub Sponsors link in the footer.

## 1.3 – pop-ups and newsletters

### Pop-up builder

Today a pop-up is an element inside one page. 1.3 turns pop-ups into site-wide pieces built in the builder, like the
header and footer:

- **Types:** centred modal, slide-in from a corner, top or bottom bar, full screen.
- **Triggers:** after N seconds, after scrolling N %, exit intent, click on a link or button (`#popup-name`), inactivity,
  after N page views in a visit.
- **Where it shows:** all pages, selected pages, collections or news categories, a language version, device
  (desktop / phone), date range, visitors coming from a campaign (`utm_*`) or a referring site.
- **How often:** once per visit, once per N days, until closed, never again after a form in it was sent – remembered
  in the visitor's browser, no cookies.
- **Library:** ready-made pop-ups – newsletter sign-up, lead magnet with a form, announcement bar, discount, event.
- **Accessibility:** focus kept inside, Esc closes, reduced motion respected, no pop-up covers the cookie bar.
- **Results:** views, closes and conversions (form sent) per pop-up, counted cookie-free like the site statistics.
- Claude can create and change pop-ups over MCP like other site parts.

### Newsletter

Two steps, in this order:

1. **Subscribers sent to the mailing service the site already uses.** After the double opt-in the address goes to
   Brevo, MailerLite, Mailchimp, Ecomail or SmartEmailing (API key and list in the admin), or to any service through the
   existing webhook (Make, Zapier). Unsubscribing in Kaleta removes the address there too. Deliverability, bounces and
   spam rules stay with the specialist service.
2. **A minimal built-in mailing for small lists** – “send the latest news to subscribers”:
   - an e-mail editor in the same builder, with an e-mail-safe set of elements (section, one or two columns, heading,
     text, image, button, divider, news list) rendered to table-based HTML with inline styles from the design system;
   - preview on desktop and phone, test e-mail to yourself, send now or scheduled;
   - one-click unsubscribe (`List-Unsubscribe`, RFC 8058), plain-text part, sending in batches through the existing mail
     queue;
   - sending only through an SMTP relay (Brevo, Amazon SES, Mailgun…) set in Settings – shared-hosting `mail()` is not
     good enough for bulk mail; without a relay the feature stays off;
   - no open tracking by default (privacy); click counts optional.

   A full campaign tool (segments, automations, A/B tests) stays out of scope – that is what the connected services are for.

## Later

- English identifiers in the code base.
- Legal text templates with a clear disclaimer (privacy policy, terms, cookie policy) per country.
- Right-to-left languages.
- “Related content” block across collections for hub-and-spoke sites.
