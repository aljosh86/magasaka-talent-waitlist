Magasaka waitlist - full quality

magasaka-waitlist.html   the active page, including the new logo loader
frames/hd/              616 frames at 2560x1440 (laptops, desktops, tablets)
frames/sd/              616 frames at 1280x720 (phones)
video/remote-work-*.mp4  film-frame loop (1080p, 720p) + poster jpg
images/roles/           role photos
images/app-1..4.png      app screenshots

Keep magasaka-waitlist.html and its asset folders together.
For real signup saves, start MySQL in XAMPP and set up the database
from the repository root using:

    C:\xampp\php\php.exe scripts\setup-local.php

Then, from this public folder, run:

    C:\xampp\php\php.exe -S 127.0.0.1:4173

Open http://127.0.0.1:4173/magasaka-waitlist.html.
Refresh the page to replay the intro.

The old three-cut-out loader and its animation have been removed from the
active page. The new loader draws the Magasaka logo, fills it, and zooms
through the letter g into the hero. Reduced motion uses a static logo and
a short fade. The reveal waits for opening frames, with a timeout fallback.
There is no separate loader HTML file; the loader is embedded in the page.

This folder contains the website and its runtime assets. Original design
references and preview recordings are kept separately in ../design-assets/.

Signup records are saved by api/waitlist.php to MySQL/MariaDB. The API also
requires the sibling private ../backend/ directory outside the web root.
Member numbers start at #1 and stop at #10,000. Browser storage holds drafts
and a private editing key; email verification is deferred.

Static previews still display the HTML without a database. To accept real
signups from one, configure a reachable hosted API in waitlist-config.js.
See ../docs/waitlist-backend.md for the API, hosting, tests and local reset.

See ../README.md for the repository overview and loader verification notes.
