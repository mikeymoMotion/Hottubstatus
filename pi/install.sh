#!/bin/bash
# Installs the fridge camera as a background service that starts when the Pi boots.
set -e
cd "$(dirname "$0")"
DIR="$(pwd)"

echo "Installing the Python packages it needs..."
sudo apt-get update -q
sudo apt-get install -y python3-gpiozero python3-lgpio python3-requests

if [ ! -f config.ini ]; then
  cp config.example.ini config.ini
  echo "Created config.ini."
fi

echo "Setting up the fridgecam service..."
sudo tee /etc/systemd/system/fridgecam.service > /dev/null <<EOF
[Unit]
Description=Fridge camera for the hot tub site
After=network-online.target
Wants=network-online.target

[Service]
User=$USER
WorkingDirectory=$DIR
ExecStart=/usr/bin/python3 $DIR/fridgecam.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF
sudo systemctl daemon-reload

echo
echo "Done. Next:"
echo "  1. Put your camera password in config.ini:  nano $DIR/config.ini"
echo "  2. Test it (see README.md), then start it:  sudo systemctl enable --now fridgecam"
