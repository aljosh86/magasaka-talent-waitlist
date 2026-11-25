Magasaka waitlist - full quality

magasaka-waitlist.html   the active page, including the new logo loader
frames/hd/              616 frames at 2560x1440 (laptops, desktops, tablets)
frames/sd/              616 frames at 1280x720 (phones)
video/1.mp4             section video
images/roles/           role photos
images/app-1..4.png      app screenshots

Keep magasaka-waitlist.html and its asset folders together.
From this folder, run:

    py -3 -m http.server 4173 --bind 127.0.0.1

Then open http://localhost:4173/magasaka-waitlist.html.
Refresh the page to replay the intro.

The old three-cut-out loader and its animation have been removed from the
active page. The new loader draws the Magasaka logo, fills it, and zooms
through the letter g into the hero. Reduced motion uses a static logo and
a short fade. The reveal waits for opening frames, with a timeout fallback.
There is no separate loader HTML file; the loader is embedded in the page.

This folder contains the website and its runtime assets. Original design
references and preview recordings are kept separately in ../design-assets/.

Signup state currently stays in browser localStorage; no backend endpoint
is connected yet.

See ../README.md for the repository overview and loader verification notes.
