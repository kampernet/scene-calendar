=== Scene Calendar ===
Contributors: kampernet
Tags: events, calendar, music, concerts, gigs
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A weekly-updated local live music calendar: collects shows from venue sites, ticketing platforms and Instagram, deduplicates them, and lets editors curate.

== Description ==

Scene Calendar builds your city's gig guide automatically. Add the venues and promoters you cover; every week the plugin collects their upcoming shows, files them under the right venue, merges duplicate listings, tags genres, and publishes them with the `[scene_calendar]` shortcode (list or month view, with venue/genre filters and search).

* Direct parsers for 3common, Showpass, Eventbrite, FestApp, RSS/Atom, iCal (incl. Google Calendar), TicketWeb (list and calendar), Squarespace, The Events Calendar, GoDaddy calendars and schema.org JSON-LD, plus poster images read by an AI vision model.
* Optional AI agent (OpenAI, Anthropic, or any OpenAI-compatible server) for venues without a supported events page, using their site, Instagram (via Apify) and web search.
* Editors can add, edit, hide and delete events. Edits are locked against future runs and deletions are never re-added.
* Designed for shared hosting: runs are split into small resumable background steps. WP-CLI command for SSH users.

This plugin sends data to third-party services only when you configure them:

* Your LLM provider: page text and event data. [OpenAI terms](https://openai.com/policies/terms-of-use) / [privacy](https://openai.com/policies/privacy-policy); [Anthropic terms](https://www.anthropic.com/legal/commercial-terms) / [privacy](https://www.anthropic.com/legal/privacy).
* Your web search provider: search queries. [Brave](https://search.brave.com/help/terms-of-service) / [privacy](https://search.brave.com/help/privacy-policy); [Tavily](https://tavily.com/terms); Google Custom Search ([terms](https://developers.google.com/custom-search/terms)); or your own SearXNG instance.
* Apify: Instagram handles. [Terms](https://docs.apify.com/legal/general-terms-and-conditions) / [privacy](https://docs.apify.com/legal/privacy-policy).
* The public event pages and ticketing APIs of the venues you add (3common, Showpass, Eventbrite, and others).

Visitors' browsers contact Google only if they click "Google Calendar" in an event's details.

== Installation ==

1. Install and activate the plugin.
2. Scene Calendar → Settings: choose an AI provider and enter API keys (optional; or define SCENECAL_* constants in wp-config.php).
3. Scene Calendar → Venues: add venues or import a JSON list.
4. Scene Calendar → Runs → Run now.
5. Add `[scene_calendar]` to a page. Options: `view="list|month"`, `days="90"` (0 = all), `venue`, `genre`, `filters="yes|no"`, `limit`, `align="wide|full"`.

Timezones: times are converted to the site timezone (Settings → General). If your region recently changed its clock rules (e.g. Manitoba's permanent UTC−5 from November 2026) and your host's PHP hasn't caught up, use a fixed offset such as UTC−5. Scene Calendar → Settings shows which clock changes PHP expects.

== Frequently Asked Questions ==

= Do I need an AI API key? =

No. Direct parsers work without one. The key enables the agent, fuzzy duplicate detection, genre tags and poster reading.

= Runs stall on my host =

WP-Cron needs site traffic. Add a server cron job hitting wp-cron.php every 5 minutes (see Settings), or run `wp scene-calendar run` over SSH.

== Changelog ==

= 0.1.0 =
* First release.
