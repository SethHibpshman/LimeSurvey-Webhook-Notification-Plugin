# DiscordNotify

A LimeSurvey plugin that posts a Discord notification whenever a survey response is completed, including the respondent's answers, directly to a channel via a Discord webhook.

No SMTP, no mail server, no extra dependencies. Just a webhook URL.

![LimeSurvey](https://img.shields.io/badge/LimeSurvey-6.x%20%7C%207.x-2ECC71) ![License](https://img.shields.io/badge/license-GPLv3-blue)

## Features

- Posts a rich embed to Discord on every completed survey response
- Shows the survey name, response ID, and (optionally) the full set of answers
- Configurable per-install:
  - Custom bot display name
  - Custom embed color
  - Toggle whether answers are included
  - Toggle submission date/time
  - Toggle respondent IP address (off by default, for privacy)
  - Restrict notifications to specific survey IDs, or leave blank for all surveys
- No SMTP required, posts over HTTPS directly to Discord

## Requirements

- LimeSurvey 6.x or 7.x (Community Edition)
- Outbound HTTPS access from your server to `discord.com`
- A Discord server where you can create a webhook

## Installation

1. Download the latest release, or clone this repo, into your LimeSurvey plugins directory:
   ```bash
   cd /path/to/limesurvey/upload/plugins
   git clone https://github.com/SethHibpshman/LimeSurvey-Webhook-Notification-Plugin.git DiscordNotify
   ```
   The folder must be named exactly `DiscordNotify` so it matches the plugin class name (the `DiscordNotify` at the end of the clone command above handles this for you).

2. In LimeSurvey, go to **Configuration > Plugins**, click **Scan files**, find **DiscordNotify** in the list, and click **Activate**.

3. Click into the plugin's settings and configure it (see below).

## Setting up the Discord webhook

1. In Discord, go to the channel you want notifications posted to.
2. **Channel Settings > Integrations > Webhooks > New Webhook**.
3. Copy the webhook URL.
4. Paste it into the plugin's **Discord webhook URL** setting in LimeSurvey.

Treat this URL like a password, anyone with it can post messages into your channel.

## Settings reference

| Setting | Type | Default | Description |
|---|---|---|---|
| Discord webhook URL | string | *(empty)* | Required. The webhook URL from your Discord channel. |
| Bot display name | string | `LimeSurvey` | Name shown as the message sender in Discord. |
| Embed color | string (hex) | `2ECC71` | Sidebar color on the embed. No `#` prefix. |
| Include response answers | boolean | on | Post the respondent's actual answers, or just survey name/response ID. |
| Include submission date/time | boolean | on | Adds a timestamp field. |
| Include respondent IP address | boolean | off | Useful for spotting duplicate/bot submissions. Off by default for privacy. |
| Only notify for these survey IDs | string | *(empty)* | Comma-separated survey IDs, e.g. `864547,123456`. Leave blank to notify for every survey. |

## How it works

The plugin hooks LimeSurvey's `afterSurveyComplete` event, looks up the response and its answers directly from the database, builds a Discord embed payload, and posts it via cURL over HTTPS. It doesn't touch LimeSurvey's email system at all, so it works even if SMTP is broken or unconfigured on your install.

## Troubleshooting

If notifications aren't showing up:

1. Confirm the plugin is **Activated**, not just installed.
2. Confirm you're testing with a real completed submission (all the way to the thank-you page), not a preview.
3. Check the log file for errors:
   ```bash
   cat /path/to/limesurvey/tmp/runtime/discordnotify.log
   ```
   (Only populated if something goes wrong, no news is good news.)

## License

GPLv3, matching LimeSurvey's own license.

## Contributing

Issues and pull requests welcome at [github.com/SethHibpshman/LimeSurvey-Webhook-Notification-Plugin](https://github.com/SethHibpshman/LimeSurvey-Webhook-Notification-Plugin).
