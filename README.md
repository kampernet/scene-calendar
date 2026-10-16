# Scene Calendar

A WordPress plugin that builds a local live-music calendar for your city. Every week it collects upcoming shows from venue websites, ticketing platforms and Instagram, removes duplicates, tags genres, and publishes the result as a calendar on your site. Editors can fix, add, hide or delete any event from wp-admin.

It was written by Joel Klaverkamp (aka [Cookie Delicious](https://cookiedelicious.com/)) with the intent to help people know what shows are happening when and where without having to be on social media, relying on the algorithm to inform them.

It was then ported for community radio stations and local scene magazines (starting as the gig guide for [CKUW / Stylus](https://ckuw.ca/stylus/) in Winnipeg). You bring your own venue list and API keys.

## How it works

For each venue or promoter you add:

1. **Direct parser** (free, fast, reliable). If the venue's events page runs on a supported platform, Scene Calendar reads it directly:

   | Parser | Use for |
   |---|---|
   | 3common | `3common.com/u/<venue>` profiles (needs the owner ID) |
   | Showpass | `showpass.com/o/<slug>` or `/venues/<slug>` pages |
   | Eventbrite | Eventbrite organizer pages |
   | FestApp | `festapp.io` festival schedules (one event per stage per day) |
   | RSS / Atom | event feeds (e.g. Manitoba Music's `livemusic.rss`) |
   | iCal | `.ics` feeds, including public Google Calendars |
   | TicketWeb list | WordPress sites using the TicketWeb plugin's list layout |
   | TicketWeb calendar | WordPress sites using the TicketWeb plugin's calendar |
   | Squarespace events | Squarespace event pages (list or calendar view) |
   | The Events Calendar | WordPress sites using The Events Calendar (Tribe) |
   | GoDaddy calendar | GoDaddy Website Builder calendar widgets |
   | schema.org JSON-LD | any page that embeds `Event` structured data |
   | Poster image (AI vision) | venues that post their monthly lineup as a poster image |

   *Auto-detect* picks a parser from the URL where it can: 3common, Showpass, Eventbrite, FestApp, `.rss`/`.xml`/`.atom` feeds, `.ics`/Google Calendar feeds, and `*.squarespace.com` sites. Most venues use their own domain, so for TicketWeb, Squarespace, The Events Calendar, GoDaddy, JSON-LD and poster images you choose the parser on the venue screen. **Test parser** shows what it extracts without saving anything.

2. **AI agent** (optional, uses your LLM key). For venues without a parser, or as a supplement, an agent reads the venue's page, its recent Instagram posts and web search results, and submits the shows it can verify. Per venue you choose: *only if the parser finds nothing*, *always* (to catch Instagram-only announcements; skipped when the parser found events and there are no Instagram posts to read), or *never*. Turn on **Settings → Review** to hold agent-found events as *Pending review* instead of publishing them.

3. **Clean-up.** Events are normalized and filed under the right venue using each venue's "also known as" names. Exact duplicates are merged automatically. Fuzzy duplicates (a promoter and the venue listing the same show under different titles) are found with a cheap pre-filter plus the LLM and marked as duplicates; they're not deleted. Missing lineups are extracted from titles and every event gets a genre.

When the same source lists a show again with a changed time, price or link, the stored copy is updated. A different source only fills in missing details, so two listings of one show can't overwrite each other every week. When duplicates are merged, the venue's own listing is kept over a promoter's or aggregator's copy.

Edits stick. When an editor changes a scraped event, including publishing or hiding it, it's **locked**: later runs won't overwrite it, re-extract its lineup or mark it as a duplicate. Deleting a scraped event leaves a tombstone, so the next run won't add it back.

## Requirements

- WordPress 6.2+ and PHP 8.1+ (with the `dom`, `json` and `mbstring` extensions, which most hosts have)
- Optional: an **OpenAI** or **Anthropic** API key for the agent, duplicate detection, genres and poster reading. Any OpenAI-compatible server also works (OpenRouter, a self-hosted Ollama, …).
- Optional: **web search** for the agent (to find shows for venues without an events URL). Pick a provider in *Settings → Web search*. A weekly run uses a few hundred searches a month.

  | Provider | Cost (Oct 2026) |
  |---|---|
  | [Brave Search API](https://brave.com/search/api/) (default) | $5 of free credit per month (≈1,000 searches), then $5 per 1,000; card required |
  | [Tavily](https://tavily.com/) | 1,000 free searches per month, no card |
  | [SearXNG](https://docs.searxng.org/) | free; self-hosted, with the JSON format enabled |
  | Google Custom Search | existing customers only; [shuts down 1 January 2027](https://developers.google.com/custom-search/v1/overview) |
- Optional: an **Apify** token to read Instagram posts (`apify/instagram-scraper`, billed per result by Apify).

Without any keys the direct parsers still work, so you get a calendar from every venue that has a supported events page.

## Install

1. Download **`scene-calendar.zip`** from the [latest release](https://github.com/kampernet/scene-calendar/releases/latest). In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip, then **Install** and **Activate**.
   - The green **Code → Download ZIP** button also works; the plugin doesn't depend on its folder name. That zip installs as `scene-calendar-main`, though, so later release zips would install as a second copy. Prefer the release zip.
   - With WP-CLI: `wp plugin install https://github.com/kampernet/scene-calendar/releases/latest/download/scene-calendar.zip --activate`
   - **Updates:** WordPress.org doesn't host this plugin, so updates are manual. Upload the new release zip and choose *Replace current with uploaded*. [Git Updater](https://git-updater.com/) can do it automatically; the plugin declares its `GitHub Plugin URI`. The `Update URI` header stops WordPress offering an unrelated WordPress.org plugin with the same slug as an "update".
2. **Scene Calendar → Settings**: choose your AI provider and enter keys. You can also define them in `wp-config.php`, which keeps them out of the database:
   ```php
   define( 'SCENECAL_OPENAI_API_KEY', 'sk-...' );
   define( 'SCENECAL_ANTHROPIC_API_KEY', 'sk-ant-...' );
   define( 'SCENECAL_BRAVE_API_KEY', '...' );
   define( 'SCENECAL_TAVILY_API_KEY', '...' );
   define( 'SCENECAL_GOOGLE_API_KEY', '...' );
   define( 'SCENECAL_GOOGLE_CSE_ID', '...' );
   define( 'SCENECAL_APIFY_API_KEY', '...' );
   ```
   Check **Settings → General → Timezone**. Times from ticketing APIs are converted to the site timezone. If your region recently changed its clock rules, read *Timezones* below.
3. **Scene Calendar → Venues**: add your venues and promoters, or import a JSON list. `data/example-venues-winnipeg.json` shows the format and is a good starting point to copy.
4. **Runs → Run now** to collect events. It runs in the background and the page shows progress and a log.
5. Put `[scene_calendar]` on a page.

## Shortcode

```
[scene_calendar]
[scene_calendar view="month" days="120"]
[scene_calendar venue="Sidestage" filters="no"]
[scene_calendar genre="punk/hardcore"]
```

| Attribute | Default | |
|---|---|---|
| `view` | `list` | `list` or `month` (visitors can switch) |
| `days` | `90` | how far ahead to show; `0` = everything |
| `venue` | | only one venue |
| `genre` | | only one genre |
| `filters` | `yes` | venue / genre / search controls |
| `limit` | `300` | maximum events |
| `align` | | `wide` or `full` to break out of a narrow content column (month view needs room) |

The list is rendered on the server, so it works without JavaScript and search engines can read it. It also outputs schema.org `MusicEvent` markup. Styles inherit your theme's fonts and colours.

## Scheduling on shared hosting

A weekly run is scheduled on the weekday and hour you pick (site timezone). Shared hosts limit how long one PHP request may run, so a run is split into small steps of one parser, or one AI call, at a time. Each step saves its progress and starts the next via a background request to your own site. Only one process works on a run at a time. A process killed mid-step (out of memory, time limit) releases its lock at shutdown, and a lock that hasn't been refreshed for 15 minutes is taken over. A step that keeps crashing is skipped after two attempts, and a 5-minute WP-Cron healthcheck resumes a run that stalled. The Runs page shows each run's AI call and token counts.

WP-Cron only fires when someone visits your site. On a quiet site, add a real cron job in your host's panel (DreamHost: *Advanced → Cron Jobs*):

```
*/5 * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron >/dev/null
```

With SSH and WP-CLI you can instead run the whole scrape in one process, with no time limits:

```
wp scene-calendar run                     # all enabled venues
wp scene-calendar run --venues="Sidestage,The Park Theatre" --no-postprocess
wp scene-calendar test-parser "The Park Theatre"
wp scene-calendar import-venues my-venues.json
wp scene-calendar export-venues > venues.json
wp scene-calendar import-events events.json   # events.json from the original Python scraper
wp scene-calendar cancel
```

## Costs

Direct parsers cost nothing. Measured on the Winnipeg example list (Oct 2026, `gpt-5-mini`):
- **Agent:** 1–11 model calls per venue, depending on how much it had to search.
- **Post-processing of ~590 events:** 110 AI calls, about 142k input + 100k output tokens, ~16 minutes, roughly US$0.25 at gpt-5-mini list prices. That covers lineups, duplicate checks over 117 dates and genres for 442 events.
- **Later weekly runs cost less**, since only new events need lineups and genres.

These numbers come from the first full pass. The duplicate pre-filter has since been widened to always compare shows at the same venue on the same night. That's estimated at about 35 calls per pass on the same data, still bounded by roughly one call per date.

Each run's AI call and token counts are shown on the Runs page. Apify bills per Instagram post fetched. You can limit costs per venue (*AI agent: never*), with the **Agent step limit**, and by turning off AI duplicate detection or genres.

## Privacy and security notes

- The plugin only reads public pages and APIs. Scraping requests (including every URL the agent chooses, and redirects) go through `wp_safe_remote_request`, which refuses private, loopback and reserved addresses. Two WordPress-level caveats apply: requests to your own site's host are allowed, and DNS rebinding isn't prevented. Only the admin-configured LLM base URL may point at a private address, for self-hosted servers.
- Responses are capped at 5 MB (posters at 8 MB).
- Fetched pages and posts are passed to your LLM provider. The agent is told to treat their content as data, and what it submits is validated. A page written to mislead it can still produce plausible fake events. If that's a concern, enable **Review**, so agent-found events wait in *Pending review* until an editor publishes them.
- API keys entered on the Settings screen are stored in `wp_options`. Only their last 4 characters are shown afterwards. The wp-config constants keep them out of the database entirely.

## Extending

**Add a parser.** Extend `SceneCalendar\Parsers\Parser` and register it:

```php
add_filter( 'scene_calendar_parsers', function ( $classes ) {
	$classes[] = My_Venue_Parser::class;
	return $classes;
} );
```

A parser declares a `slug()`, `label()`, `description()` and optional `config_fields()` (shown on the venue form), plus a `parse( $venue, $ctx )` that returns raw event arrays (see the class doc). Use `SceneCalendar\Util\Http` for requests and `$ctx->to_local()` to convert timestamps to the site timezone. Pull requests for new platforms are very welcome.

**Other hooks:**
- `scene_calendar_search_web` / `scene_calendar_has_search`: plug in another web search provider (e.g. Serper) beyond the built-in Brave, Tavily, SearXNG and Google.
- `scene_calendar_llm_client`: supply a custom LLM client (`SceneCalendar\Llm\Client`).
- `scene_calendar_genres`: change the genre list the AI classifies into.
- `scene_calendar_genre_colors`: change genre labels and colours on the calendar. A genre added with `scene_calendar_genres` shows as "Other" unless it also gets a colour here; the event edit screen offers only the built-in genres.
- `scene_calendar_shortcode_events`: filter what the shortcode shows.
- `scene_calendar_output_json_ld`: return false to omit the schema.org markup.
- `scene_calendar_run_finished`: an action fired after each run with its stats.
- `scene_calendar_tick_budget`: seconds of work per background step (default 45).

## Releasing

Bump `Version:` in `scene-calendar.php` (and `Stable tag` in `readme.txt`), then push a matching tag:

```
git tag v0.1.0 && git push origin v0.1.0
```

The *Release zip* GitHub Action builds `scene-calendar.zip` (a `scene-calendar/` folder without tests, Docker files or other development files; see `.gitattributes`) and attaches it to a new GitHub Release. It refuses to build if the tag and the plugin version differ.

## Development

Everything runs in Docker: WordPress, MariaDB and WP-CLI.

```
docker compose up -d
bin/setup.sh          # installs WordPress at http://localhost:8080 (admin / admin), activates the plugin, creates a /shows page
bin/test.sh           # runs the test suite inside WordPress
bin/test.sh squarespace   # only tests whose name contains "squarespace"
docker compose run --rm cli scene-calendar import-venues data/example-venues-winnipeg.json
docker compose run --rm cli scene-calendar run
```

To give the dev site API keys, put `SCENECAL_OPENAI_API_KEY=...` etc. in a `.env` file next to `docker-compose.yml` (it's git-ignored).

Tests run inside a real WordPress via `wp eval-file`. Each test runs in a database transaction that is rolled back, starting from empty plugin tables, so your dev data is untouched. HTTP is mocked through WordPress's `pre_http_request` filter, and parsers are tested against saved copies of real pages in `tests/fixtures/`.

### Layout

```
scene-calendar.php       bootstrap + autoloader
includes/
  Plugin.php             hooks, activation, cron
  Runner.php             resumable run state machine (venues → lineups → dedup → genres → cleanup)
  Events.php, Venues.php storage, aliases, tombstones, locking
  Settings.php           options + wp-config/env key overrides
  Parsers/               one class per platform + Registry
  Agent/                 the event-finding agent and its tools
  Llm/                   OpenAI-compatible and Anthropic clients
  Pipeline/              normalize, process, dedup, classify
  Admin/                 wp-admin screens
  Frontend/Shortcode.php [scene_calendar]
assets/                  admin + public JS/CSS (no build step)
data/                    example venue lists
tests/                   harness, unit tests, parser tests, fixtures
```

## Known limitations

- The Brave, Tavily and SearXNG search integrations are tested against their documented request/response formats, not yet against the live services.
- 3common times use the UTC offset 3common sends for each event. If 3common ever sent one offset for a whole series across a daylight-saving change, those events would be an hour off.
- If you change the site timezone, save Scene Calendar's Settings once so the weekly run is rescheduled for the new time.
- The month view always starts weeks on Sunday.
- When an editor marks one event "not a duplicate", two other copies of the same show linked only through it can still be merged with each other.
- Genre chips sit outside the shortcode's `genre` attribute: `[scene_calendar genre="rock"]` shows only rock, and the chips filter within that.
- Imported events from the original Python scraper can be listed once more if their venue name was a fuzzy variant (this plugin files them under the configured venue name, which changes the event key). AI dedup catches most of these.
- If a run is started from two places at the same instant, two runs can be queued (the lock prevents them working at the same time, but both will run). Cancelling a run while a step is being saved can, rarely, be overwritten by that save; cancel again.

- **Timezones.** Times from APIs that send UTC are converted to the site timezone using PHP's own copy of the timezone database, which can lag behind real-world changes. Example: Manitoba dropped standard time in November 2026 and stays on UTC−5 year-round, but PHP builds with timezone data older than that still switch `America/Winnipeg` to UTC−6. Winter shows would then be listed an hour early. *Settings → Schedule* shows which clock changes PHP expects. If they're wrong for your region, set *Settings → General → Timezone* to the fixed offset (e.g. `UTC-5`) until your host updates PHP. With a fixed offset, times in iCal feeds are taken as the venue's local time.
- iCal recurring events (`RRULE`) only use their first date.
- 3common reports $0 for most ticket types even on paid shows, so its prices are left blank.
- The agent can only find what's published somewhere it can read. Venues that announce shows only in Instagram stories, or behind logins, need Apify, or manual entry.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
