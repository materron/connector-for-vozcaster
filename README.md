# Connector for VozCaster

WordPress plugin that connects your site to the **[VozCaster](https://vozcaster.com)** Telegram bot, so you can publish podcast episodes from voice notes — automatically, using [PowerPress](https://wordpress.org/plugins/powerpress/).

You send a voice note to the VozCaster bot on Telegram, and a podcast episode appears on your WordPress, with audio, title, content and featured image. No editing, no manual uploads.

## What this plugin does

This is the **WordPress side** of the VozCaster system. It exposes the REST API the bot uses to:

- Upload audio, images and intro/outro files to your media library
- Create podcast episodes with PowerPress (episode/season numbering, feeds)
- Authenticate Telegram users against your WordPress without ever handling passwords

The audio processing (transcription, content generation, noise reduction, mixing) happens on the VozCaster bot's server, not in this plugin.

## Requirements

- WordPress 6.3+
- PHP 8.0+
- [PowerPress](https://wordpress.org/plugins/powerpress/) installed and active
- A Telegram account
- A WordPress user authorised in the plugin settings

## Installation

1. Install and activate the plugin.
2. Go to **Settings → VozCaster** and authorise the WordPress users that may publish via the bot.
3. Open Telegram, talk to the VozCaster bot, run `/conectar` and link your site.

See the full manual at **[vozcaster.com/manual](https://vozcaster.com/manual)**.

## External service

This plugin connects to the VozCaster bot, an external service operated by the author. See the [Privacy Policy](https://vozcaster.com/privacidad) and the `== External Services ==` section of `readme.txt` for details on what data is transmitted.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

---

A [Potencia Pro](https://potencia.pro) project by Miguel Ángel Terrón Bote.
