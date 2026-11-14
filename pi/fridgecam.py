#!/usr/bin/env python3
"""Fridge camera for the hot tub site.

Waits for the fridge door to close, lets things settle, turns on the LED,
takes a photo and sends it to the site, which has Claude count the drinks.

    python3 fridgecam.py           run (this is what the background service does)
    python3 fridgecam.py --snap    take a photo and save it as latest.jpg, without sending it
    python3 fridgecam.py --send    take a photo and send it to the site right now
    python3 fridgecam.py --door    show the door sensor's state, to check the wiring
"""

import argparse
import configparser
import json
import logging
import subprocess
import sys
import time
from pathlib import Path

import requests
from gpiozero import Button, PWMLED

HERE = Path(__file__).resolve().parent
PHOTO = HERE / "latest.jpg"
STATE = HERE / "state.json"

log = logging.getLogger("fridgecam")


class Config:
    def __init__(self, path):
        parser = configparser.ConfigParser()
        if not parser.read(path):
            sys.exit(f"Can't read {path}. Run: cp config.example.ini config.ini, then fill it in.")
        s = parser["fridgecam"]
        self.site_url = s.get("site_url", "").strip().rstrip("/")
        self.camera_key = s.get("camera_key", "").strip()
        self.door_pin = s.getint("door_pin", 17)
        self.door_closed_when = s.get("door_closed_when", "low").strip().lower()
        self.led_pin = s.getint("led_pin", 18)
        self.led_brightness = max(0.0, min(1.0, s.getfloat("led_brightness", 1.0)))
        self.settle_seconds = s.getint("settle_seconds", 90)
        self.min_minutes_between_photos = s.getint("min_minutes_between_photos", 10)
        self.refresh_hours = s.getfloat("refresh_hours", 24)
        self.camera_args = s.get("camera_args", "").split()

        if self.door_closed_when not in ("low", "high"):
            sys.exit("door_closed_when in config.ini must be low or high.")

    def check_upload_settings(self):
        if not self.site_url.startswith("http"):
            sys.exit("Set site_url in config.ini, e.g. https://ismikeshottubready.com")
        if not self.camera_key or self.camera_key == "your-camera-password":
            sys.exit("Set camera_key in config.ini to the camera password from line 13 of index.php.")


class Fridge:
    def __init__(self, config):
        self.config = config
        # The door switch connects the pin to ground. With a "normally open" switch
        # that is when the magnet is next to it, i.e. the door is closed.
        self.door = Button(config.door_pin, pull_up=True, bounce_time=0.05)
        self.led = PWMLED(config.led_pin)

    def door_closed(self):
        grounded = self.door.is_pressed
        return grounded if self.config.door_closed_when == "low" else not grounded

    def take_photo(self):
        """Lights the fridge, takes a photo, and saves it as latest.jpg."""
        self.led.value = self.config.led_brightness
        try:
            time.sleep(1)
            command = [
                "rpicam-still", "--nopreview",
                "--timeout", "2000",  # lets exposure and focus adjust to the LED
                "--width", "2304", "--height", "1296",
                "--quality", "85",
                "--autofocus-on-capture",
                "--output", str(PHOTO),
            ] + self.config.camera_args
            subprocess.run(command, check=True, capture_output=True, text=True, timeout=60)
        finally:
            self.led.off()
        return PHOTO


def send_photo(config, path):
    """Sends a photo to the site. Returns True when the drink list was updated."""
    url = config.site_url + "/?api=fridge-photo"
    for attempt in range(1, 4):
        try:
            with open(path, "rb") as photo:
                response = requests.post(
                    url,
                    files={"photo": ("fridge.jpg", photo, "image/jpeg")},
                    data={"key": config.camera_key},
                    timeout=180,
                )
            try:
                body = response.json()
            except ValueError:
                body = {}
            if response.status_code == 200:
                drinks = ", ".join(f"{i['name']}: {i['count']}" for i in body.get("items", []))
                log.info("Drink list updated: %s", drinks or "no drinks seen")
                if body.get("note"):
                    log.info("Claude's note: %s", body["note"])
                return True
            if response.status_code == 401:
                log.error("The site rejected the camera password. Check camera_key in config.ini "
                          "matches line 13 of index.php.")
                return False
            if response.status_code == 400:
                log.error("The site couldn't use the photo: %s", body.get("error", response.text[:200]))
                return False
            log.warning("Try %d: the site answered %d: %s", attempt, response.status_code,
                        body.get("error", response.text[:200]))
        except requests.RequestException as error:
            log.warning("Try %d: couldn't reach the site: %s", attempt, error)
        if attempt < 3:
            time.sleep(30 * attempt)
    log.error("Gave up sending this photo. The next one will try again.")
    return False


def load_last_sent():
    try:
        return float(json.loads(STATE.read_text())["last_sent"])
    except (OSError, ValueError, KeyError):
        return 0.0


def save_last_sent(when):
    STATE.write_text(json.dumps({"last_sent": when}))


def run(config):
    config.check_upload_settings()
    fridge = Fridge(config)
    last_sent = load_last_sent()
    was_closed = fridge.door_closed()
    closed_since = time.time()
    photo_needed = False

    log.info("Fridge camera started. Door is %s.", "closed" if was_closed else "open")

    while True:
        now = time.time()
        closed = fridge.door_closed()

        if closed != was_closed:
            log.info("Door %s.", "closed" if closed else "opened")
            was_closed = closed
            if closed:
                closed_since = now
            else:
                # Someone may have taken or added drinks. Photograph once it's closed again.
                photo_needed = True

        settled = closed and now - closed_since >= config.settle_seconds
        rested = now - last_sent >= config.min_minutes_between_photos * 60
        refresh_due = config.refresh_hours > 0 and now - last_sent >= config.refresh_hours * 3600

        if settled and rested and (photo_needed or refresh_due):
            reason = "after the door closed" if photo_needed else "for the daily check"
            log.info("Taking a photo %s.", reason)
            photo_needed = False
            last_sent = now
            save_last_sent(now)
            try:
                send_photo(config, fridge.take_photo())
            except subprocess.CalledProcessError as error:
                log.error("The camera failed: %s", (error.stderr or "").strip()[-500:])
            except subprocess.TimeoutExpired:
                log.error("The camera took too long to answer.")

        time.sleep(0.5)


def watch_door(config):
    fridge = Fridge(config)
    print("Open and close the fridge door. Press Ctrl+C to stop.")
    last = None
    while True:
        closed = fridge.door_closed()
        if closed != last:
            print("Door is CLOSED" if closed else "Door is OPEN")
            last = closed
        time.sleep(0.1)


def main():
    parser = argparse.ArgumentParser(description="Fridge camera for the hot tub site.")
    parser.add_argument("--config", default=str(HERE / "config.ini"))
    group = parser.add_mutually_exclusive_group()
    group.add_argument("--snap", action="store_true", help="take a photo and save it as latest.jpg only")
    group.add_argument("--send", action="store_true", help="take a photo and send it to the site now")
    group.add_argument("--door", action="store_true", help="show the door sensor's state")
    args = parser.parse_args()

    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(message)s", datefmt="%Y-%m-%d %H:%M:%S")
    config = Config(args.config)

    try:
        if args.door:
            watch_door(config)
        elif args.snap:
            print("Saved", Fridge(config).take_photo())
        elif args.send:
            config.check_upload_settings()
            path = Fridge(config).take_photo()
            print("Saved", path, "and sending it to the site...")
            sys.exit(0 if send_photo(config, path) else 1)
        else:
            run(config)
    except KeyboardInterrupt:
        pass
    except subprocess.CalledProcessError as error:
        sys.exit("The camera failed: " + (error.stderr or "").strip()[-500:])


if __name__ == "__main__":
    main()
