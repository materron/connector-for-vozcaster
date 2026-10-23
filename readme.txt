=== Connector for VozCaster ===
Contributors: materron
Tags: podcast, powerpress, podcast production, transcription, audio
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.12.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

You talk, VozCaster does the rest: it cleans your audio, writes the post, makes the cover and publishes the episode on your WordPress.

== Description ==

**You talk, VozCaster does the rest.**

Recording an episode takes minutes. Producing it takes hours: cleaning the audio, levelling the voice, adding the intro and outro, writing a title and show notes, making a cover, uploading it all to WordPress, filling in PowerPress, numbering the episode… VozCaster does that work for you. You record, on your phone or your computer, and a few clicks later the episode is on your site, ready to review or publish.

**Connector for VozCaster** is the WordPress side. It receives the finished episode from the VozCaster service and publishes it with [PowerPress](https://wordpress.org/plugins/powerpress/), on your own site and in your own media library.

= What VozCaster does for you =

* **The sound**: trims silences, removes noise, levels the voice and mixes your intro and outro, with the music fading under the voice. The same, every time.
* **Several audios, one episode**: record an interview or a show in parts and VozCaster joins them, with an optional separator sound between them.
* **The words** (Pro): transcribes what you said and writes a title, an excerpt and a full article from it, in the language you speak.
* **The cover** (Pro): generates a featured image from what the episode is about, with the element you choose for your show, if you set one.
* **The publishing**: uploads the audio, creates the post with PowerPress, numbers the episode and the season, and sets the featured image and the Apple Podcasts artwork. Draft or live: you decide.
* **The platforms**: checks that your feed has everything Spotify, Apple Podcasts and the rest ask for, and walks you through submitting it to each one.
* **Blog posts too** (Pro): dictate or jot down an idea and get a written post, without audio.

= Record wherever suits you =

* **The VozCaster app** (app.vozcaster.com): records from the phone or the computer browser and can be installed like any app. You connect it with one button in wp-admin, with no account to create.
* **Telegram**: send a voice note or an audio file to the VozCaster bot.
* **An audio file** you already recorded somewhere else, uploaded from the app or sent to the bot.

Whichever you use, the result is the same: the finished episode on your WordPress.

= See it in action =

From an empty WordPress to your first published episode in five minutes (video in Spanish):

https://www.youtube.com/watch?v=WexfifEie7M

Can't see the player? [Watch it on YouTube](https://www.youtube.com/watch?v=WexfifEie7M).

= Your podcast stays yours =

* **It lives alongside what you already have**: each episode is one more PowerPress episode. Your settings, your feed and your earlier episodes are not touched.
* **Episode by episode**: publish one with VozCaster and the next one by hand, and switch whenever you like.
* **No lock-in**: the audio, the posts and the feed are in your WordPress. Stop using VozCaster and your podcast keeps working exactly the same.

= What the plugin adds to WordPress =

* A **VozCaster** menu with **Connect in the app** and **Connect in Telegram** buttons for each podcast: no address to type, no second login.
* **Per-podcast settings**: title prefix, season, intro, outro and separator sound with their mixing times, post signature, a fixed element for the covers, and category mapping.
* **Several podcasts on the same site**, with PowerPress custom channels or category podcasting.
* **Permissions by role**: Authors and above can publish with VozCaster by default, and administrators can authorise custom roles as well.
* **Podcast details** (artwork, Apple Podcasts category, owner, language, explicit flag) written straight into PowerPress, with a checklist of what platforms need.
* **Distribute**: Spotify, Apple Podcasts, YouTube Music, iVoox, Amazon Music, Podcast Index, Pocket Casts, Castbox and Deezer, ordered by importance, with submit links and a status for each one.
* The `[vozcaster_connect]` **shortcode**, to show the connect buttons on any page, so people can connect without opening wp-admin.
* **Optional review**: episodes from roles that cannot publish posts can be held as "Pending review", with an email to the administrator.
* **AI content notice**: posts whose text was written by AI show a short, customisable notice (on by default, can be turned off).
* **Token-based authentication**: VozCaster never sees your WordPress password.

= Free and Pro =

This plugin is free and open source, and basic publishing is free too: no card, no invitation. The audio is always produced for you: silence trimming, basic noise reduction and intro/outro mixing.

The paid **Pro** and **Studio** plans add the AI part: automatic transcription (Whisper), a complete article written from what you said, a generated featured image and advanced noise reduction (DeepFilter). The episode arrives with its title, text and cover already done, sounding its best. Pro includes 30 AI episodes per month, Studio 100. See [plans and pricing](https://vozcaster.com/pricing.html).

= Languages =

The VozCaster app and bot speak Spanish and English. The episode text is written in the language you record in: the transcription detects it automatically.

= How it works =

1. Install and activate PowerPress and this plugin on your WordPress site.
2. Open the new **VozCaster** menu in wp-admin and press **Connect in the app** (or **Connect in Telegram**).
3. Record, or upload an audio. VozCaster produces it and the episode appears on your site, ready to review or publish.

If your site's address cannot be passed on automatically (for example, WordPress installed in a subfolder), the same page tells you to send `/conectar` to the bot and paste the address instead.

= Requirements =

* WordPress with the [PowerPress](https://wordpress.org/plugins/powerpress/) plugin installed and activated.
* A phone or a computer with a web browser for the VozCaster app, or a Telegram account.
* A WordPress user with an authorised role: Author, Editor or Administrator by default, or any custom role the administrator authorises in **VozCaster → Access**.

== Installation ==

1. Upload the `connector-for-vozcaster` folder to `/wp-content/plugins/`, or install via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Open the **VozCaster** menu and press **Connect in the app** or **Connect in Telegram**, then confirm.
4. Optional: administrators find the settings in the same menu — **Episodes** (titles, signature, image style, AI notice), **Audio** (intro/outro), **Access** (roles and connected users) and **History**.

== Frequently Asked Questions ==

= Does it work with PowerPress? =

Yes. PowerPress is a hard requirement — the plugin uses PowerPress to register episode metadata, enclosures, season and episode numbers.

= Do I need a paid account to use it? =

This plugin is free and open source. The VozCaster service it connects to offers a free tier and a paid (Pro) tier. Basic episode publishing (including basic audio cleanup) works on the free tier; the Pro features — automatic transcription, a fully written article, a generated featured image and advanced noise reduction (DeepFilter) — require a Pro or Studio subscription. See https://vozcaster.com/pricing.html for the current plans.

= Where is my audio stored? =

Permanently, only in your own WordPress media library. VozCaster processes the audio on a private server operated by the author (located in Spain) and deletes the temporary files after publishing.

= Does VozCaster send my audio to third-party AI services? =

Your **audio is never sent to a third-party AI service**. Transcription runs on a local Whisper model on VozCaster's server, so the audio file itself stays on that server (and on Telegram, if you record there).

To draft the episode **title, description and content**, VozCaster sends the transcribed *text* to Anthropic's Claude API. If you ask it to generate a cover image, the *image prompt* is sent to OpenAI's image API. These providers receive text only, and only when you request content or image generation. See the "External Services" section below for the providers and their privacy policies.

= Do I need Telegram? =

No. The VozCaster app (app.vozcaster.com) does everything the bot does, from the phone or the computer browser: record, produce and publish episodes, write blog posts, edit the podcast details and submit the feed to platforms. Telegram is just another way in, if you prefer it.

= Can I connect more than one WordPress site? =

Yes. Each site needs its own installation of this plugin and its own connection: press the connect button on each site.

= Can a single WordPress site host more than one podcast? =

Yes. The plugin works with PowerPress's multi-podcast (custom channels) configuration. You switch between podcasts in the app's site settings, or with `/feed` in Telegram.

= Who can publish with VozCaster? =

Anyone whose role is authorised in **VozCaster → Access**. By default that is every role that can publish posts (Author, Editor, Administrator). Administrators can also authorise a custom role: users with it can then publish with VozCaster even if they cannot publish from wp-admin. Every authorised user can publish to every podcast on the site. The same screen lists who has connected and lets you revoke access.

= Can people publish without access to wp-admin, for example the teachers of a school? =

Yes. Create a role for them (for example "Podcast teachers", a copy of Subscriber made with any role editor plugin) and check it in **VozCaster → Access**. Then add the `[vozcaster_connect]` shortcode to a page: once logged in, they see the connect buttons there and never need to open wp-admin. Avoid authorising the whole Subscriber role if anyone can register on your site.

= Can I review episodes before they go live? =

Yes. Turn on **Review before publishing** in **VozCaster → Access**. Episodes sent by users whose role cannot publish posts in WordPress are saved as "Pending review" and the site administrator gets an email with a link to approve them; VozCaster tells the sender that the episode is waiting for review. Authors and above keep publishing directly.

= Why do some posts show a notice about AI? =

When VozCaster writes the text of a post with AI, the plugin shows a short notice with it so readers know. It is on by default, and you can change its text and position or turn it off in **VozCaster → Episodes**, or hide it on a single post from the editor. The notice is added when the post is displayed, so changes apply to all posts at once.

= How do I get my podcast on Spotify, Apple Podcasts and the rest? =

Go to **VozCaster → Distribute** in wp-admin, open **Distribute to platforms** in the app, or send `/difundir` to the bot. First it checks that the feed has everything platforms ask for (title, description, square artwork of 1400–3000 px, category, author, owner email and at least one episode). Then it lists the platforms by importance, each with its submit link, a link to find your podcast there and a status you can mark: not submitted, submitted or live. You only submit once: after that every platform picks up new episodes by itself. VozCaster also checks Apple Podcasts on its own and marks it as live when your feed appears there.

= Can I change the podcast artwork, category or owner without opening PowerPress? =

Yes, in **Podcast details** in the app or with `/podcast` in Telegram (site administrators only). It writes into the same PowerPress settings, so you can keep editing them in PowerPress too. The artwork is cropped to a square and resized to 3000 px if needed.

= Does the plugin add links to my site? =

Not unless you ask it to. There is an optional "Published with VozCaster" credit line with a link, which is **off by default** and can be turned on in **VozCaster → Episodes**.

= How do I get support? =

For documentation, see the [VozCaster manual](https://vozcaster.com/manual). For issues or questions, email info@vozcaster.com.

== External Services ==

This plugin requires an external service to function: **VozCaster**, operated by the plugin author (Miguel Ángel Terrón Bote), which you use through its web app (https://app.vozcaster.com) or its Telegram bot. The plugin is the WordPress-side component; the VozCaster service performs audio processing and orchestrates publication.

The following data is transmitted:

1. **From the bot to your WordPress site, via this plugin's REST API**
   - Audio files you recorded in the VozCaster app or sent to the bot via Telegram.
   - Optional featured images.
   - Episode metadata: title, description, content, episode number, season, feed slug.
   - An authentication token issued to your WordPress user during the connection flow.

   These calls are initiated by the bot, not by the plugin. They reach your site through standard REST endpoints registered by this plugin under `/wp-json/vozpress/v1/`.

2. **From your WordPress site to the bot (only when a user connects)**
   - When a user presses **Connect in Telegram** in wp-admin, the browser opens a `t.me` link to the bot containing a single-use code and your site's address. The bot then calls this plugin to read who is connecting (username, display name, podcast name) and, after the user confirms, to redeem the code for a token.
   - When a user connects with `/conectar` instead, the browser is redirected to your WordPress login; after login, this plugin issues a token and returns it to the bot.
   - When a user presses **Connect in the app**, the browser opens the VozCaster web app (https://app.vozcaster.com, operated by the plugin author) with the same single-use code and your site's address in the link. The app hands them to the VozCaster service, which redeems the code exactly as the bot does. Recordings made in the app are sent to the VozCaster service for processing, as with Telegram.
   - The plugin itself makes no outgoing requests; the "Connect in Telegram" and "Connect in the app" buttons only open Telegram or the app in the user's browser.

3. **From the bot's server to Telegram's servers**
   - User-sent voice messages and audio files pass through Telegram's infrastructure as part of normal bot communication. See Telegram's Privacy Policy: https://telegram.org/privacy

4. **From the bot's server to Apple's public catalogue**
   - When you open the distribution list in the bot, it searches the public iTunes Search API for your podcast title to check whether your feed is already on Apple Podcasts. Only the podcast title is sent; the plugin itself makes no such request. Apple's privacy policy: https://www.apple.com/legal/privacy/

5. **From the bot's server to AI providers (text only)**
   - To draft the episode title, description and content, the bot sends the transcribed *text* of your recording to **Anthropic (Claude)**.
   - If you request a cover image, the bot sends a *text prompt* to **OpenAI** to generate the image.
   - Only text is sent, and only when content or image generation is requested. Your audio file is **never** transmitted to these providers.

Audio is transcribed locally with a Whisper model on the bot's server; other audio processing (DeepFilter noise reduction, ffmpeg, and an Ollama model used only as a local fallback) also runs locally. The audio file itself is not sent to any third-party AI service.

Service URLs and policies:

* VozCaster (bot service): https://vozcaster.com
* VozCaster privacy policy: https://vozcaster.com/privacy
* VozCaster terms of service: https://vozcaster.com/terms
* Telegram Messenger: https://telegram.org
* Telegram privacy policy: https://telegram.org/privacy
* Telegram terms of service: https://telegram.org/tos
* Anthropic (Claude, AI text generation): https://www.anthropic.com
* Anthropic privacy policy: https://www.anthropic.com/legal/privacy
* Anthropic terms of service: https://www.anthropic.com/legal/consumer-terms
* OpenAI (AI image generation): https://openai.com
* OpenAI privacy policy: https://openai.com/policies/privacy-policy
* OpenAI terms of service: https://openai.com/policies/terms-of-use
* Apple Podcasts / iTunes Search API (catalogue check from the bot): https://performance-partners.apple.com/search-api
* Apple privacy policy: https://www.apple.com/legal/privacy/
* Apple Media Services terms: https://www.apple.com/legal/internet-services/itunes/

== Screenshots ==

1. VozCaster → Connect: one "Connect in Telegram" button per podcast. No address to type and no second login.
2. The bot asks for confirmation before connecting the site.
3. `/podcast` in Telegram: the podcast data with its artwork, and whether it is ready to submit to platforms.
4. VozCaster → Distribute: feed address, readiness checklist and the platforms ordered by importance.
5. VozCaster → Episodes: title prefix, season and episode numbering, and the post signature.
6. VozCaster → Access: roles authorised to publish from the bot and connected users.

== Changelog ==

= 1.12.2 =
* Fix: signing in to WordPress from the VozCaster app (typing your site's address) was refused for app accounts not linked to Telegram.

= 1.12.1 =
* Changed: the texts in wp-admin (VozCaster → Connect, the settings pages and the plugin description) now present VozCaster as what it is: you talk, VozCaster does the production and publishes here, from the VozCaster app or from Telegram.
* Changed: the optional "Published with VozCaster" credit links to vozcaster.com instead of the Telegram bot.
* Fix: the image style description still said the text was prepended verbatim to the cover prompt; it is a recurring element woven into each cover.

= 1.12.0 =
* Changed: default mixing times follow the length of your music — the intro plays alone for 20 s and drops to 30 % within 3 s, the outro mirrors it so its last 20 s play alone; music of 20 s or less plays whole. Saved settings are kept. The outro can now start 0 s before the end of the voice (whole, after it).
* Changed: the image style example is now neutral («An orange cat wearing studio headphones») and its description explains that the element is woven into each cover.
* New: **Connect in the app** button (VozCaster → Connect and the `[vozcaster_connect]` shortcode), next to Connect in Telegram. It opens the VozCaster web app, which records from the phone or computer and publishes here without Telegram. Same single-use code as the Telegram button.

= 1.11.0 =
* New: **separator sound** (VozCaster → Audio). A short sound the bot plays between the sections of an episode recorded in several audios, such as interviews at an event. It can also be uploaded from the bot. New REST endpoint `POST/DELETE /media/separator`; `/media/intro-outro` now also reports it.

= 1.10.1 =
* Fix: strings added in recent versions (the AI content notice, the VozCaster pages, the connect shortcode) showed in English on sites using a language pack from translate.wordpress.org that did not have them yet. The translation shipped with the plugin now fills in whatever the language pack is missing.

= 1.10.0 =
* New: `[vozcaster_connect]` shortcode — the "Connect in Telegram" buttons on any page of the site, with a login link for visitors and a notice for users without access. Optional `feed` attribute to show a single podcast.
* New: **Review before publishing** (VozCaster → Access). Episodes and posts from roles that cannot publish posts in WordPress are saved as "Pending review", and the administrator receives an email to approve them.
* Change: episode numbering also counts pending and scheduled episodes, so episodes waiting for review never share a number; ties between episodes created in the same second are broken by ID.

= 1.9.0 =
* New: podcast data from the bot. `/podcast` shows and changes the show data PowerPress publishes in the feed — title, description, artwork, Apple Podcasts category, author/owner, owner email, language and explicit — for the main feed, custom channels and category feeds, with a checklist of what platforms need. New REST endpoint `GET/POST /podcast`.
* New: distribution to platforms. **VozCaster → Distribute** (and `/difundir` in the bot) lists Spotify, Apple Podcasts, YouTube Music, iVoox, Amazon Music, Podcast Index, Pocket Casts, Castbox and Deezer by importance, with submit and search links and a status per platform. New REST endpoint `GET/POST /distribution`.

= 1.8.1 =
* Fix: saving a form on the Audio, Access or History page went back to the Episodes page instead of staying on the same page.

= 1.8.0 =
* New: **VozCaster** menu in wp-admin. Everyone authorised sees **Connect**; administrators also get **Episodes**, **Audio**, **Access** and **History**, which replace the single long page under Settings → VozCaster (old links redirect).
* New: connect the bot from wp-admin. **VozCaster → Connect** has a "Connect in Telegram" button for each podcast; it opens the bot, which asks for confirmation and connects you. No address to type and no second login. `/conectar` still works.
* New: permissions by role. The per-user allowlist and the per-podcast permissions are gone: every role that can publish posts (Author and above) can publish from the bot, in every podcast, and administrators can authorise custom roles as well. The settings screen lists the connected users with a Revoke button.
* New: AI content notice. Posts whose text was written by AI show a short notice, on by default, with customisable text and position, and a per-post option to hide it. It is rendered by the plugin, so changes apply to all posts.
* New: optional "Published with VozCaster" credit line, off by default.
* Fix: an allowlisted user without the capability to publish (e.g. a Contributor) could publish episodes directly.
* Security: bot tokens are now stored hashed. Existing connections are migrated automatically and keep working.

= 1.7.1 =
* Fix: episodes published by the bot never set PowerPress's per-episode "Apple Podcast Episode Artwork" (`itunes_image`) — only the regular WordPress featured image. It had to be filled in by hand every time. New episodes now get it automatically from the same image (the one uploaded by the bot, or the podcast's default cover as fallback). Episodes published before this fix are unaffected — their Apple Podcasts artwork field stays empty until edited manually or the episode is republished.

= 1.7.0 =
* New "Image style" setting under Settings → VozCaster: fixed text prepended to every AI-generated cover image prompt for this podcast (e.g. a recurring subject or look). Previously only settable from the bot with `/estilo`, and stored only in the bot's own database — it now lives on the site (`GET`/`POST /settings`), so `/estilo` and wp-admin both read and write the same value.

= 1.6.1 =
* Season change now reports the real next episode number instead of always announcing E1: reverting to a season that already has published episodes continues from the last one (e.g. back to T3E6, not T3E1). New season with no episodes still starts at E1.
* `POST /season/increment` returns `next_episode_number` and `next_episode_in_season`.

= 1.6.0 =
* Season number: any authorised user who can edit posts can now view and change the podcast season from the bot with `/temporada` — it no longer requires a WordPress administrator. `/temporada 4` fixes the season to a given number after a confirmation prompt; `/temporada` on its own shows the current season and offers a one-tap jump to the next one.
* Season number is now shown and editable under Settings → VozCaster.
* Reconciliation: if an editor bumps the season directly in the PowerPress episode box, the connector adopts it instead of silently reverting to the stored value. Moving to a season below the latest published episode still requires editing that episode.

= 1.5.15 =
* Performance: the episode log no longer autoloads on every request. Existing installs are migrated automatically on first load after the update.
* Hardening: the PowerPress `enclosure` field is now read with `unserialize()` restricted to `allowed_classes => false`, ruling out PHP object injection.
* Accessibility: added accessible labels to the per-user permission checkboxes and to the intro/outro file pickers on the settings screen.
* Code quality: the category-feed lookup now goes through `$wpdb->prepare()`, and `uninstall.php` carries an explicit `ABSPATH` guard.

= 1.5.14 =
* Fixed a fatal error on audio episode upload: `wp-admin/includes/media.php` (which defines `wp_read_audio_metadata()`) was not loaded in the REST API request context, causing attachment metadata generation to fail for audio files.

= 1.5.13 =
* Added plugin banner, icon and screenshots for the WordPress.org listing.

= 1.5.12 =
* Documentation: corrected the "paid account" FAQ to describe the free and Pro tiers of the VozCaster bot (removed outdated "beta" wording).

= 1.5.11 =
* Settings-changing REST endpoints (global intro/outro, mix settings, plugin options, season) now require an administrator capability, not just an authorised bot user.
* The media picker script on the settings screen is now enqueued via `admin_enqueue_scripts` and `wp_localize_script` instead of inline output.
* Core admin includes in the REST upload helpers are loaded only where needed, immediately before the function that uses them.

= 1.5.10 =
* Passes Plugin Check with no errors. File operations during chunked audio assembly now use the WordPress Filesystem API; output escaping, input unslashing and `wp_parse_url()` applied where flagged.
* The Telegram connection result pages are now fully translatable (English source strings with an updated Spanish es_ES translation).

= 1.5.9 =
* Hardened the REST authentication token handling (sanitised header input).
* Documentation: the "External Services" section and FAQ now disclose the AI providers used by the VozCaster bot to generate episode text (Anthropic) and cover images (OpenAI). Your audio is never sent to these providers; only text is.

= 1.5.8 =
* Spanish (es_ES) translation added. The plugin automatically displays in Spanish on WordPress installations set to Spanish.

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
