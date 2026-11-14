# Fridge camera (Raspberry Pi)

The Pi watches the fridge door. About 90 seconds after the door closes, it turns on the LED, takes a photo and sends it to the site. The site has Claude count the drinks and updates the fridge list.

Several quick trips to the fridge share one photo, and it sends at most one photo every 10 minutes. It also sends one photo a day, so "Checked … ago" stays fresh.

## 1. Wiring

**Unplug the Pi before connecting anything.** Pin numbers are the physical positions on the Pi's 40-pin header: pin 1 is at the end farthest from the USB ports, on the row nearer the middle of the board. Odd numbers (1, 3, 5…) run along that inner row and even numbers (2, 4, 6…) along the outer edge. [pinout.xyz](https://pinout.xyz) has a picture.

| From | To Pi pin |
|---|---|
| Door switch, either wire | **Pin 11** (GPIO 17) |
| Door switch, other wire | **Pin 9** (GND) |
| MOSFET module **VIN+** | **Pin 2** (5V) |
| MOSFET module **VIN−** | **Pin 6** (GND) |
| MOSFET module **TRIG / PWM +** | **Pin 12** (GPIO 18) |
| MOSFET module **TRIG / PWM −** | **Pin 14** (GND) |

Then connect the LED strip to the MOSFET module's output: strip **+** (usually red) to **OUT+**, strip **−** (usually black) to **OUT−**. To get bare wires, cut the USB plug off the strip's cable, and the inline dimmer too if it doesn't turn on at full brightness by itself.

If your door switch has three wires, use **COM** and **NO**. The labels on MOSFET modules vary a little; match them as closely as you can.

## 2. Install

On the Pi (`ssh yourusername@fridgecam.local`):

```sh
sudo apt-get install -y git
git clone -b claude/fridge-inventory https://github.com/mikeymoMotion/Hottubstatus.git ~/Hottubstatus
cd ~/Hottubstatus/pi
./install.sh
nano config.ini
```

In `config.ini`, set `camera_key` to the camera password from line 13 of `index.php` on Bluehost. Save with Ctrl+O, Enter, then Ctrl+X.

## 3. Test at your desk

Run these from `~/Hottubstatus/pi`:

1. **Door switch:** run `python3 fridgecam.py --door`, then move the magnet up to the switch and away. It should say CLOSED when they touch and OPEN when they're apart. If it's backwards, change `door_closed_when` in `config.ini` to `high`. Ctrl+C stops it.
2. **Camera and LED:** run `python3 fridgecam.py --snap`. The LED should light for a few seconds and save `latest.jpg`. To look at it, run this **on your computer**:
   ```sh
   scp yourusername@fridgecam.local:Hottubstatus/pi/latest.jpg .
   ```
3. **Whole thing:** run `python3 fridgecam.py --send`. It takes a photo and sends it to the site. The drink list on the site should update (at your desk, it'll be "no drinks seen").

`--snap` costs nothing; `--send` costs one count (see Costs below).

## 4. Start it

```sh
sudo systemctl enable --now fridgecam
```

It now runs in the background and starts by itself whenever the Pi boots. To see what it's doing:

```sh
journalctl -u fridgecam -f
```

## Settings

All in `config.ini`. After changing anything, run `sudo systemctl restart fridgecam`.

| Setting | What it does |
|---|---|
| `settle_seconds` | Wait after the door closes before the photo (default 90). |
| `min_minutes_between_photos` | At most one photo per this many minutes (default 10). |
| `refresh_hours` | Daily check photo (default 24; 0 turns it off). |
| `led_brightness` | 0.1 to 1.0. Lower it if photos look washed out. |
| `camera_args` | Extra camera options, e.g. `--rotation 180` if the camera is mounted upside down. |

## Updating the software

```sh
cd ~/Hottubstatus && git pull && sudo systemctl restart fridgecam
```

## Costs

Each count uses Claude Opus 5.5 and costs roughly 2–5 cents. A normal day (a few fridge trips plus the daily check) is a few counts, so expect very roughly $2–10 a month, more after a big party. Set a monthly spending limit in the Anthropic Console to be safe.
