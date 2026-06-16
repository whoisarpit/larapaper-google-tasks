# LaraPaper Google Tasks Connector

Reusable Google Tasks read-only connector for LaraPaper.

This repo has two parts:

- a tiny OAuth-backed feed service that talks to Google Tasks
- a LaraPaper recipe archive that reads the feed and renders tasks on screen

## What it does

- Uses Google OAuth 2.0 server-side authorization.
- Requests the narrow `tasks.readonly` scope.
- Stores refresh tokens locally so auth is not hardcoded.
- Exposes `/tasks` as a JSON feed for LaraPaper.
- Ships a recipe archive in `recipe/` that can be imported into LaraPaper.

Google Tasks requires user consent for scopes, and Google recommends offline access for web-server apps so a refresh token can be used without re-prompting the user.

## Setup

1. Create an OAuth client in your Google Cloud project.
2. Enable the Google Tasks API.
3. Copy `.env.example` to `.env` and fill in the client ID, client secret, and redirect URI.
4. Run the connector locally:

```bash
php -S localhost:8080 -t public
```

5. Open `/connect` and complete the Google consent flow.
6. Open `/tasks` to verify the feed.

The Tasks API lists are fetched from `GET https://tasks.googleapis.com/tasks/v1/users/@me/lists` and tasks from `GET https://tasks.googleapis.com/tasks/v1/lists/{tasklist}/tasks`.

## LaraPaper recipe

Import the archive from `recipe/` into LaraPaper. Set the `Connector Feed URL` to the deployed `/tasks` endpoint.

To build the importable archive:

```bash
./scripts/package-recipe.sh
```

## Endpoints

- `GET /` - status page and auth link
- `GET /connect` - starts Google OAuth
- `GET /callback` - OAuth callback
- `GET /tasks` - JSON feed for LaraPaper
- `GET /lists` - task list helper
- `POST /disconnect` - clears the stored token

## Environment

- `GOOGLE_CLIENT_ID`
- `GOOGLE_CLIENT_SECRET`
- `GOOGLE_REDIRECT_URI`
- `GOOGLE_TASKS_LIST_ID`
- `GOOGLE_TOKEN_STORAGE`
- `GOOGLE_DEFAULT_PAGE_SIZE`
- `GOOGLE_TASKS_SHOW_COMPLETED`
- `GOOGLE_TASKS_SHOW_HIDDEN`

## Notes

- This connector is read only.
- If you want to use a different task list, set `GOOGLE_TASKS_LIST_ID` or use `/lists` to inspect available lists first.
- If the OAuth consent screen is still in testing, Google may show an unverified-app warning during authorization. Google also notes that sensitive scopes can require verification for public apps.
