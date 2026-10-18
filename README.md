# Hot Tub Status

A dead-simple page that shows **READY** (warm gradient + steam) or **NOT READY** (cool gradient), big and centered.

The whole site is one file: [`public/index.php`](public/index.php). It runs on any regular PHP web host (Bluehost, etc.). No database and nothing to install.

## Put it on Bluehost

1. In Bluehost, open your site's **File Manager** and go to its **Document Root** folder (shown on the site's settings page).
2. Delete anything already in that folder (e.g. an `index.php` or `default.html`).
3. Upload `index.php`, or create a new file named `index.php` and paste in its contents.
4. Edit `index.php` and change line 3 to your password, keeping the quotes:
   ```php
   $ADMIN_PASSWORD = 'your-password-here';
   ```
5. Visit your site. It starts as **NOT READY**.

## Changing the status

1. Click the faint 🔒 in the bottom-right corner.
2. Enter your password.
3. A **Ready** switch appears. Flip it.

Your browser remembers the password, so next time the switch is already there. Everyone else only sees the status (open pages refresh every 15 seconds). The server rejects changes without the password.

The status is saved to `status.json` next to `index.php`. PHP creates it the first time you flip the switch.

## Troubleshooting

- **Switch flips back after reload:** the folder isn't writable by PHP. In File Manager, set the folder's permissions to `755`.
- **Password never works:** make sure you changed line 3 and it isn't still `change-me` (that one is always rejected).
- **Blank page:** the file was only partly copied. Paste the whole thing again.

## Try it locally

```sh
php -S localhost:8000 -t public
```
