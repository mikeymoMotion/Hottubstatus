# Hot Tub Status

A dead-simple page that shows **READY** (warm gradient + steam) or **NOT READY** (cool gradient), big and centered.

It's plain HTML plus one small PHP file, so it runs on any regular web host (Bluehost, etc.). No database and nothing to install.

## Put it on Bluehost

1. Log in to Bluehost → **Websites** → your site → **cPanel** → **File Manager**.
2. Open `public_html` (or make a subfolder like `public_html/hottub` if you want it at `yoursite.com/hottub`).
3. Upload the three files from this repo's `public/` folder:
   - `index.html`
   - `api.php`
   - `config.example.php`
4. In File Manager, rename `config.example.php` to **`config.php`**, then edit it and change `change-me` to your own password. Save.
5. Visit your site. The page starts as **NOT READY**.

## Changing the status

1. Click the faint 🔒 in the bottom-right corner.
2. Enter your password.
3. A **Ready** switch appears. Flip it.

Your browser remembers the password, so next time the switch is already there. Everyone else only sees the status (open pages refresh every 15 seconds). The server rejects changes without the password.

The status is saved to `status.json` next to `api.php`. PHP creates the file the first time you flip the switch.

## Troubleshooting

- **Switch flips back / "could not save status":** the folder isn't writable by PHP. In File Manager, set the folder's permissions to `755`.
- **Password never works:** make sure the file is named exactly `config.php` and the password isn't still `change-me` (that one is always rejected).

## Try it locally

```sh
cp public/config.example.php public/config.php   # then edit the password
php -S localhost:8000 -t public
```
