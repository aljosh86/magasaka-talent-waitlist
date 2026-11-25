# Magasaka talent waitlist

A static waitlist page for Magasaka, a marketplace connecting Filipino talent with clients. The page includes a logo intro, a scroll-driven hero, and a waitlist signup flow.

## Local preview

From the repository root, run:

```powershell
py -3 -m http.server 4173 --bind 127.0.0.1 --directory magasaka-waitlist-fullquality
```

Open <http://localhost:4173/magasaka-waitlist.html>. Refresh the page to replay the loader. No build step or package installation is required.

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
- `magasaka-waitlist-fullquality/video/1.mp4`: section video.
- `design-assets/hero-stills/`: supplied hero stills, previously in `A-E/`.
- `design-assets/hero-clips/`: supplied source clips, previously in `Clips/`.
- `design-assets/page-references/`: supplied page reference images and video, previously in `Page/`.
- `design-assets/previews/`: supplied preview video and screen recording, previously in `New Folder/`.

Keep the active page and its asset folders together when serving or deploying. The page filename is `magasaka-waitlist.html`.
Only `magasaka-waitlist-fullquality/` is needed to serve the website; `design-assets/` contains source material for editing.

## Hero navigation

The opening "Scroll to explore" cue has a static circular arrow. It is a visual prompt for manual scrolling, with no click-triggered scrolling or hover movement. The former "Skip hero story" controls have been removed from the hero and menu. In reduced motion, the scroll prompt is hidden alongside the scroll-driven sequence.

## Logo loader update

The old three-cut-out loader has been removed from the active page, including its embedded cut-out images, gathering styles, and panel-exit animation. The standalone loader demo previously at the repository root has also been removed, leaving one loader implementation in the active page.

The new intro draws the Magasaka logo on a green background, fills its letters, then zooms through the counter of the “g” to reveal the hero. Its SVG, styles, and animation are embedded in the active page.

- The reveal waits for the opening hero frames, with a timeout so stalled assets cannot keep the intro open indefinitely.
- Reduced motion shows the completed logo and fades into the page without drawing or zooming.
- Completion removes the overlay, restores scrolling, and clears the temporary hero transform and blur.
- Checked on desktop and mobile, with reduced motion and stalled frame requests. These checks passed without JavaScript errors or horizontal overflow.

## Signup storage

The signup flow currently stores its state in the visitor's browser using `localStorage`. A backend endpoint is not connected yet; submissions are not sent to a central waitlist.
