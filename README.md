# Zoom Chat (local_zoomchat)

A Moodle plugin that adds a site-wide floating chat bubble integrated with [Zoom Team Chat](https://www.zoom.com/en/products/team-chat/). Users can send direct messages, images, and files to course participants without leaving Moodle.

## Dependencies

- [tool_zoomapi](https://github.com/jrchamp/moodle-tool_zoomapi) — Zoom API authentication and user mapping
- [tool_realtime](https://github.com/marinaglancy/moodle-tool_realtime) — Real-time message delivery

## Installation

1. Place the plugin in `local/zoomchat/`
2. Visit Site administration → Notifications to install
3. Ensure `tool_zoomapi` and `tool_realtime` are installed and configured

## Configuration

Navigate to **Site administration → Plugins → Local plugins → Zoom Chat**.

| Setting | Description |
|---|---|
| Webhook secret | Must match the secret configured in your Zoom Marketplace app (see below) |

### Capabilities

| Capability | Default | Description |
|---|---|---|
| `local/zoomchat:chat` | Allow (authenticated user) | Use Zoom Chat |

## Zoom Marketplace App Setup

Create a [Zoom Marketplace](https://marketplace.zoom.us/) Server-to-Server OAuth app. This plugin requires the following scopes:

| Scope | Endpoint |
|---|---|
| `user:read:user:admin` | Resolve Zoom users |
| `team_chat:write:user_message:admin` | Send chat messages |
| `team_chat:read:file:admin` | Retrieve chat file metadata |
| `team_chat:write:files:admin` | Upload files to attach to messages |

Configure the app credentials in `tool_zoomapi` (see that plugin's documentation).

### Webhook Configuration

In the Zoom Marketplace app, add the **Team Chat** event subscription and set the **Event notification endpoint URL** to:

```
https://your-moodle.example.com/local/zoomchat/webhook.php
```

Enable the following events, organized by category as they appear in the Zoom Marketplace web UI:

**Chat Message** (`Event Subscriptions → Add Events → Chat Message`)

| Event |
|---|
| Chat Message Sent |
| Chat Message Updated |
| Chat Message Deleted |

**Team Chat** (`Event Subscriptions → Add Events → Team Chat`)

| Event |
|---|
| Team Chat DM Message Posted |
| Team Chat DM Message Updated |
| Team Chat DM Message Deleted |
| Team chat file shared |

Set the same secret in the Zoom Marketplace app **Webhook secret** field and in Moodle's plugin settings.

### Validation

Zoom sends a validation challenge when the endpoint URL is configured. The plugin responds automatically. Check the **Event subscription** status in the Marketplace app — if it shows **Enabled**, the webhook is working.

## Usage

A chat bubble appears in the bottom-right corner of every page. Click it to:

- See conversations with any Zoom Team Chat contact
- Send text messages, images, and file attachments
- Receive real-time notifications (when `tool_realtime` is configured) or 5-second polling

### Contacts

The contact list includes:
- **Course contacts** — teachers (`moodle/course:update`) can message any enrolled user with a linked Zoom account; students can message editing teachers with Zoom accounts
- Anyone with a **previous Zoom Team Chat conversation** with you (from Zoom or from Moodle)

## Architecture

```
Moodle (local/zoomchat)
    ├── webhook.php          ← Incoming Zoom Team Chat events
    ├── ajax.php             ← File upload handler
    ├── classes/
    │   ├── callbacks.php    ← Hook to inject chat bubble on every page
    │   ├── helper.php       ← Message storage, conversation queries, Zoom API calls
    │   ├── api.php          ← Zoom API client (file upload/download)
    │   ├── external/        ← AJAX APIs (get_users, get_messages, send_message)
    │   └── output/
    │       └── webhook.php  ← Webhook event dispatch and notification
    ├── amd/src/main.js      ← Frontend: bubble, modal, real-time handler
    └── styles.css           ← Styling (Bootstrap CSS variables)
```

Messages are stored with raw Zoom user IDs. Moodle user resolution happens at display time via `tool_zoomapi`'s user mapping cache.
