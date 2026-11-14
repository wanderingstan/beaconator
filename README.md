# Beaconator

A social network with no mandated UI.

Friends **broadcast** what they're up to (statuses, updates, info) into a simple open data layer —
an RSS feed, a SQL endpoint, whatever's simplest. Their friends **consume** that data however they
like: an app, a table, a chronological timeline, even a generated video.

## Origins
- **Stan & Gabe** — a social network could just be a data endpoint (even plain SQL) people post into.
- **Casey** — "Beaconator": friends broadcast what they're up to via RSS.
- **Joel** — joined the conversation and got excited about both.

## Status
Live prototype being built on a call, November 2026.

## Prototype v0
Live at https://demos.wanderingstan.com/beaconator/ — one PHP file (`index.php`) + SQLite.

- `GET /` default viewer + post form
- `GET ?format=json[&who=name][&since=id]`
- `GET ?format=rss[&who=name]`
- `POST who, text, url?, key` (shared key, v0 only)
