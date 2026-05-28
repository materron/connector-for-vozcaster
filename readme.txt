=== Connector for VozCaster ===
Contributors: materron
Tags: podcast, telegram, powerpress, automation, transcription
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.5.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress to the VozCaster Telegram bot to publish podcast episodes from voice notes, directly into PowerPress.

== Description ==

**VozCaster** is a Telegram bot that turns voice notes into fully published podcast episodes on your WordPress site. This plugin — **Connector for VozCaster** — is the WordPress side of the system: it receives episodes from the bot and publishes them using [PowerPress](https://wordpress.org/plugins/powerpress/).

You send a voice note to the bot, and a published draft (or live episode) appears on your site with audio, title, content and featured image. No editing, no manual file uploads, no opening the WordPress dashboard.

= What it does =

* **Receives audio** from the VozCaster bot and uploads it to your WordPress media library.
* **Creates podcast episodes** using PowerPress, with episode and season numbering managed automatically.
* **Supports multiple podcasts on the same site** — works with any PowerPress custom channels you have configured.
* **Per-podcast settings**: title prefix, intro/outro audio, post footer (signature), category mapping.
* **Granular permissions**: choose which WordPress users can publish to which podcast.
* **Token-based authentication** so the bot never sees your WordPress password.

= How it works =

1. Install and activate this plugin on your WordPress site.
2. In **Settings → Connector for VozCaster**, authorise one or more WordPress users to publish via the bot.
3. Open Telegram, talk to the VozCaster bot, run `/conectar` and link your WordPress site.
4. Send a voice note or audio file to the bot. The episode appears on your site, ready to review or publish.

= Requirements =

* WordPress with the [PowerPress](https://wordpress.org/plugins/powerpress/) plugin installed and activated.
* A Telegram account.
* A WordPress user authorised to publish via the bot (configurable in the plugin settings — any role works as long as the administrator marks the user as authorised).

== Installation ==

1. Upload the `connector-for-vozcaster` folder to `/wp-content/plugins/`, or install via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Settings → Connector for VozCaster** and authorise the WordPress users that will publish via the bot.
4. Open Telegram, search for the VozCaster bot, send `/conectar` and follow the instructions to link your site.

== Frequently Asked Questions ==

= Does it work with PowerPress? =

Yes. PowerPress is a hard requirement — the plugin uses PowerPress to register episode metadata, enclosures, season and episode numbers.

= Do I need a paid account to use it? =

No. The bot operates in beta and is currently free for all connected sites. Advanced features (automatic transcription, AI-assisted content generation, audio noise reduction with DeepFilter) will move to a paid plan when the bot exits beta; sites already connected at that time will not lose access to features they were already using.

= Where is my audio stored? =

Permanently, only in your own WordPress media library. The bot processes the audio on a private server operated by the author (located in Spain) and deletes the temporary files after publishing.

= Does the bot send my audio to third-party AI services? =

No. Transcription uses a local Whisper model running on the bot's server. Content generation uses a local Ollama model. No audio or transcript leaves the bot's server unless you explicitly configure an optional third-party AI service.

= Can I connect more than one WordPress site to the bot? =

Yes. Each site needs its own installation of this plugin and its own connection from Telegram (`/conectar` for each site).

= Can a single WordPress site host more than one podcast? =

Yes. The plugin works with PowerPress's multi-podcast (custom channels) configuration. From Telegram you switch between podcasts with `/feed`.

= Who can publish from the bot? =

Any WordPress user whose ID is in the authorised users list (configured in Settings → Connector for VozCaster). Role does not matter — what matters is whether the administrator has authorised them.

= How do I get support? =

For documentation, see the [VozCaster manual](https://vozcaster.com/manual). For issues or questions, email info@vozcaster.com.

== External Services ==

This plugin requires an external service to function: the **VozCaster bot**, a Telegram bot operated by the plugin author (Miguel Ángel Terrón Bote). The plugin is the WordPress-side component; the bot performs audio processing and orchestrates publication.

The following data is transmitted:

1. **From the bot to your WordPress site, via this plugin's REST API**
   - Audio files received by the bot (sent by you via Telegram).
   - Optional featured images.
   - Episode metadata: title, description, content, episode number, season, feed slug.
   - An authentication token issued to your WordPress user during the connection flow.

   These calls are initiated by the bot, not by the plugin. They reach your site through standard REST endpoints registered by this plugin under `/wp-json/vozpress/v1/`.

2. **From your WordPress site to the bot (only during the user authorisation flow)**
   - When a WordPress user connects via `/conectar` in Telegram, your browser is redirected to your WordPress login. After login, this plugin issues a token and returns it to the bot to complete the handshake.

3. **From the bot's server to Telegram's servers**
   - User-sent voice messages and audio files pass through Telegram's infrastructure as part of normal bot communication. See Telegram's Privacy Policy: https://telegram.org/privacy

The bot's server processes audio with locally-running models (Whisper, Ollama, DeepFilter, ffmpeg). It does **not** transmit your audio or content to any third-party AI service unless the bot is explicitly configured to do so by its operator.

Service URLs and policies:

* VozCaster (bot service): https://vozcaster.com
* VozCaster privacy policy: https://vozcaster.com/privacy
* VozCaster terms of service: https://vozcaster.com/terms
* Telegram Messenger: https://telegram.org
* Telegram privacy policy: https://telegram.org/privacy
* Telegram terms of service: https://telegram.org/tos

== Screenshots ==

1. Settings page: authorised users and per-feed permissions.
2. Per-podcast settings: title prefix, intro/outro audio, post footer.
3. Episode log: list of episodes published via the bot.

== Changelog ==

= 1.5.7 =
* Renamed from VozPress Connector to Connector for VozCaster to align with WordPress.org plugin guidelines on naming.
* Endpoint `/episode/next-number` now also returns the chapter number within the current season so the bot can name uploaded media files using the season+chapter scheme.

= 1.5.6 =
* `/episode/next-number` includes `episode_no_in_season` for season-aware media file naming.

= 1.3.2 =
* Feed detection now includes PowerPress "category podcasting" feeds. Each linked category is exposed as a feed using the category slug.
* `assign_podcast_category()` no longer creates new categories — prevents duplicate "Podcast" categories on sites with custom slugs.

= 1.3.1 =
* Season and chapter-within-season numbering derived from the previous published episode's PowerPress metadata, instead of the previous year-based heuristic.
* Multi-feed detection now reads from `powerpress_general.custom_feeds` — the per-podcast permissions UI in wp-admin now appears correctly on sites with PowerPress custom channels.

= 1.3.0 =
* All admin UI strings translated and properly wrapped in i18n functions.
* Added `== External Services ==` section to readme.

= 1.2.x =
* Multi-feed support: per-feed permissions, automatic category assignment, per-feed episode counters.
* Chunked audio upload with gzip compression for large files (>25 MB).
* Manual season increment via REST endpoint and `/temporada` command in the bot.

= 1.1.x =
* Intro and outro mixing with configurable ducking.
* Settings endpoints for remote configuration from the bot.
* Transcript attachment via REST endpoint.

= 1.0.x =
* Initial release: `/ping`, `/auth/verify`, `/media/audio`, `/media/image`, `/episode` REST endpoints.
* Token-based authentication using `wp_check_password()`.
* Settings page with access token and episode log.
