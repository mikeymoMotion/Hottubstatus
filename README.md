# Hot Tub Status

A dead-simple page that shows **READY** (warm gradient + steam) or **NOT READY** (cool gradient), big and centered.

The whole site is one file: [`public/index.php`](public/index.php). It runs on any regular PHP web host (Bluehost, etc.). No database and nothing to install.

## Put it on Bluehost

1. In Bluehost, open your site's **File Manager** and go to its **Document Root** folder (shown on the site's settings page).
2. Delete anything already in that folder (e.g. an `index.php` or `default.html`).
3. Upload `index.php`, or create a new file named `index.php` and paste in its contents.
4. Edit `index.php` and replace `change-me` on line 4 with your password. No quotes needed, and any characters are fine:
   ```php
   $ADMIN_PASSWORD = <<<'PASSWORD'
   your-password-here
   PASSWORD;
   ```
5. For the fridge list, also fill in the two lines under **Fridge settings** (lines 9 and 13). See [The fridge](#the-fridge) below. You can skip this for now; the rest of the site works without it.
6. Visit your site. It starts as **NOT READY**.

## Changing the status

1. Click the faint 🔒 in the bottom-right corner.
2. Enter your password.
3. A **Ready** switch appears. Flip it.

Your browser remembers the password, so next time the switch is already there. Everyone else only sees the status (open pages refresh every 15 seconds). The server rejects changes without the password.

The status is saved to `status.json` next to `index.php`. PHP creates it the first time you flip the switch.

## The fridge

A **What's in the fridge?** button under the status opens a list of the drinks in the mini fridge. Each drink shows **Plenty** (4 or more), **Running low** (1–3) or **Out**, and the list says when it was last checked. Counts that Claude had to estimate show as "about 13".

### Setting it up

1. Get an API key at [console.anthropic.com](https://console.anthropic.com) (Settings → API Keys) and add a few dollars of credit. Each photo costs a few cents or less.
2. In `index.php`, replace `paste-your-anthropic-api-key-here` (line 9) with the key.
3. Replace `change-me-too` (line 13) with a second password. Only the fridge camera uses it, so it never needs your admin password.

Never commit the real key or passwords to GitHub; this repository is public. Change them only in the copy on Bluehost.

### Updating the list

Unlock with the 🔒, then open **What's in the fridge?**. Under **Fridge admin**:

- **Count from photo:** take or choose a photo of the open fridge. Claude counts the drinks (20–60 seconds) and the list updates.
- **Edit:** fix counts with − and +, add or remove drinks, and edit **Drinks you usually stock**. Put one drink per line, with a description after a dash, e.g. `Gatorade Glacier Freeze - blue bottles, stored bottoms out`. Claude uses this list to name what it sees, even when labels are hidden. Drinks on this list that Claude can't find show as **Out**.

The list is saved to `fridge.json` next to `index.php`. Photos aren't kept.

### For the fridge camera

The camera sends a photo the same way:

```sh
curl -F photo=@fridge.jpg -F key=YOUR-CAMERA-PASSWORD "https://ismikeshottubready.com/?api=fridge-photo"
```

## Troubleshooting

- **Switch flips back after reload:** the folder isn't writable by PHP. In File Manager, set the folder's permissions to `755`.
- **Password never works:** make sure you replaced line 4 and it isn't still `change-me` (that one is always rejected).
- **"Add your Anthropic API key" or "API key was rejected":** check line 9 of `index.php` has your whole key, with nothing else on the line.
- **Count from photo takes a long time:** that's normal; Claude can take up to a minute.
- **Blank page:** the file was only partly copied, or something other than the password and key lines was changed. Re-upload the file and only replace the lines this README tells you to.

## Try it locally

```sh
php -S localhost:8000 -t public
```
