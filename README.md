# Magasaka talent waitlist

A waitlist page for Magasaka, a marketplace connecting Filipino talent with clients. The existing HTML page includes a logo intro, a scroll-driven hero, and a signup flow backed by PHP and MySQL/MariaDB.

## Local preview

For working signups on Windows with XAMPP, start **MySQL** in the XAMPP control panel. Create an empty `magasaka_waitlist` database in phpMyAdmin, then run this once from the repository root:

```powershell
& C:\xampp\php\php.exe scripts\setup-local.php
```

This imports `database/schema.sql`, creates a local application database user with only data access privileges, and writes private credentials to `backend/config.local.php` (excluded from Git). The setup uses local root access by default; set `WAITLIST_ADMIN_USER` and `WAITLIST_ADMIN_PASSWORD` in your terminal if your local administrator account differs. Re-running setup preserves entries and numbering.

Start the PHP preview:

```powershell
& C:\xampp\php\php.exe -S 127.0.0.1:4173 -t magasaka-waitlist-fullquality
```

Open <http://127.0.0.1:4173/magasaka-waitlist.html>. Use the same address consistently: `localhost` and `127.0.0.1` have separate browser storage. If the preview is already running, just refresh the page. This uses XAMPP's PHP runtime and MySQL; Apache can keep serving phpMyAdmin and your other projects. No package installation or build step is required.

The HTML and animations can also run on a static server without PHP/MySQL. Real signup saves need a running API. For a partner's static preview, configure a hosted API in `magasaka-waitlist-fullquality/waitlist-config.js` and explicitly allow that preview's origin on the API server. Failed saves do not show a success card. A local XAMPP database is not shared through GitHub.

See [backend setup and API details](docs/waitlist-backend.md) for deployment, testing, and resetting local test entries.

## Files

```text
.
|-- README.md
|-- design-assets/
|   |-- hero-stills/
|   |-- hero-clips/
|   |-- page-references/
|   `-- previews/
`-- magasaka-waitlist-fullquality/
    |-- magasaka-waitlist.html
    |-- README.txt
    |-- frames/
    |   |-- hd/
    |   `-- sd/
    |-- images/
    `-- video/
```

- `magasaka-waitlist-fullquality/magasaka-waitlist.html`: the active page, including its CSS, scripts, and logo loader.
- `magasaka-waitlist-fullquality/frames/hd/`: 616 hero frames at 2560 × 1440.
- `magasaka-waitlist-fullquality/frames/sd/`: 616 hero frames at 1280 × 720 for phones.
- `magasaka-waitlist-fullquality/images/`: app screenshots and role photos.
- `magasaka-waitlist-fullquality/video/remote-work-1080.mp4`, `remote-work-720.mp4`, `remote-work-poster.jpg`: looping film-frame video (720p is served at 900px wide and below).
- `design-assets/hero-stills/`: supplied hero stills, previously in `A-E/`.
- `design-assets/hero-clips/`: supplied source clips, previously in `Clips/`.
- `design-assets/page-references/`: supplied page reference images and video, previously in `Page/`.
- `design-assets/previews/`: supplied preview video and screen recording, previously in `New Folder/`.

Keep the active page and its asset folders together when serving or deploying. The page filename is `magasaka-waitlist.html`.
`magasaka-waitlist-fullquality/` is the public website folder. The PHP API also requires the sibling `backend/` directory, which must remain outside the public document root. `design-assets/` contains source material for editing.

## Hero navigation

The opening "Scroll" cue sits at the lower right, where the former skip control appeared. Its mouse wheel moves downward and fades in a gentle loop while the outline and label stay fixed. The animation pauses when the cue or browser tab is hidden. On phones, the cue sits above the signup bar to avoid overlap. It is a visual prompt for manual scrolling, with no click-triggered scrolling. The former "Skip hero story" controls remain removed from the hero and menu. In reduced motion, the scroll prompt and its animation are disabled alongside the scroll-driven sequence.

## Logo loader update

The old three-cut-out loader has been removed from the active page, including its embedded cut-out images, gathering styles, and panel-exit animation. The standalone loader demo previously at the repository root has also been removed, leaving one loader implementation in the active page.

The new intro draws the Magasaka logo on a green background, fills its letters, then zooms through the counter of the “g” to reveal the hero. Its SVG, styles, and animation are embedded in the active page.

- The reveal waits for the opening hero frames, with a timeout so stalled assets cannot keep the intro open indefinitely.
- Reduced motion shows the completed logo and fades into the page without drawing or zooming.
- Completion removes the overlay, restores scrolling, and clears the temporary hero transform and blur.
- Checked on desktop and mobile, with reduced motion and stalled frame requests. These checks passed without JavaScript errors or horizontal overflow.

## Signup storage

The PHP API saves signups in MySQL/MariaDB. A successful email submission reserves the next number from **1 through 10,000**; the name and completed profile update that same record. The database preserves professional titles, 1–3 skills (including custom skills), referral attribution, and UTC timestamps. Email verification and invitation sending are deferred; records remain unverified.

Numbers are assigned under a database transaction, so duplicate emails, retries, and simultaneous requests cannot allocate extra spots. The card receives its number and referral code from the backend, replacing the former email-derived demo number. The legacy `mg-waitlist` browser data is ignored, not automatically imported as real registrations.

Browser storage now holds drafts and a private per-email editing key. Returning from the original browser resumes that entry; entering an already registered email from another browser cannot view or overwrite its profile. Recovery across devices will require the later email-verification implementation. Referral links contain only a public referral code, never the private editing key. Referrals are recorded but do not change assigned numbers or queue order.
