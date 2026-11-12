# Code layers – which namespace may call which

Since 3.39 (audit 10 November 2026, Q7). One rule for where new code goes, kept by the unit test
`tools/tests/unit/3100-3.39-t5-namespace-layers.php`.

## The rule

Code may reference its own namespace and the ones listed for it. Everything under `system/src` counts; files directly in
`system/src` (`helpers.php`) count as Core.

| Namespace | May reference | What it is |
|---|---|---|
| `Compat` | – | the HTML5 parser and serializer for PHP 8.3 |
| `Core` | Compat, Connectors, Extension | database, settings, rules of the data (news, CRM, bookings, imports, reports, updates) |
| `Connectors` | Core, Compat | adapters of outside services (Google, HubSpot, Pipedrive, Raynet, Bing) |
| `Extension` | Core, Compat | the add-on API (`Extension\Api`) and its registry |
| `Import` | Core, Compat, Builder | structured importers |
| `Builder` | Core, Compat, Connectors, Extension, Front | builds, elements, design system, collections |
| `Front` | Core, Compat, Connectors, Extension, Builder | the public site: routing, rendering, cache, forms |
| `Fleet` | Core, Compat, Connectors, Extension, Builder, Front | the fleet console and the site's heartbeat |
| `Mcp` | everything except Admin and Install | the MCP server, its tools and translators |
| `Install` | Core, Compat, Builder, Front | the installer |
| `Admin` | everything | the administration: modules and their actions |

Core calls Connectors and Extension on purpose: adapters of outside services and the add-on hooks are services Core
uses, and neither of them reaches back into anything but Core.

## In practice

- **A rule of the data goes into Core**, not into an admin module, even when only the admin uses it today: MCP, the
  background jobs and the importers will need it tomorrow. An admin module reads the request, calls Core and renders.
- **Mcp calls Core (or Builder, Front), never `Admin\Modules\…`.** What an MCP tool shares with a screen lives below both.
- **Core never names `Admin\…`, `Front\…`, `Builder\…`, `Fleet\…` or `Mcp\…`.** When Core needs something from there, the
  class belongs in Core (or the call belongs in the caller).

## Today's exceptions

The test keeps a frozen list of the references that break the rule today (98 files on 12 November 2026, mostly Core
writing `Admin\ChangeLog` – 44 files – and clearing `Front\Cache` – 13 files; Mcp calling static methods of admin
modules – 17 files). A new reference fails the test; a reference that goes away must leave the list too, so it only
shrinks. The planned steps (audit Q7), each done when a feature touches the code, never as one big change:

1. `Admin\ChangeLog` → `Core\ChangeLog` and `Front\Cache` → `Core\PageCache` (search and replace; the updater removes
   old class files) – about 70 % of the list.
2. Static domain methods out of admin modules into the Core class they belong to (`Enquiries::deleteExpired`, the
   trash methods, what Mcp calls on `Modules\Pages`, `Media` and `Categories`).
3. Splitting Core into sub-namespaces (`Core\Crm`, `Core\Import`…) only after that, if it still pays.
