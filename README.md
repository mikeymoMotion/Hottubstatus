# Hot Tub Status

A dead-simple page that shows **READY** (warm gradient + steam) or **NOT READY** (cool gradient), big and centered.

## Run it

```sh
ADMIN_PASSWORD=pick-something npm start
```

Then open http://localhost:3000. No dependencies — just Node 18+.

## Changing the status

1. Click the faint 🔒 in the bottom-right corner.
2. Enter your `ADMIN_PASSWORD`.
3. A **Ready** switch appears — flip it.

Your browser remembers the password, so next time the switch is just there. Everyone else only sees the status (it refreshes every 15 seconds). The server rejects changes without the password.

## Config

| Env var          | Default              | What it does                  |
| ---------------- | -------------------- | ----------------------------- |
| `ADMIN_PASSWORD` | *(none — required)*  | Password to change the status |
| `PORT`           | `3000`               | Port to listen on             |
| `DATA_FILE`      | `data/status.json`   | Where the status is saved     |

## Hosting

Any host that runs a Node app works (Render, Railway, Fly.io, a Raspberry Pi). Set `ADMIN_PASSWORD` in the host's environment settings and use `npm start` as the start command. If the host has an ephemeral disk, point `DATA_FILE` at a persistent volume, or the status resets to "Not Ready" on redeploy.
