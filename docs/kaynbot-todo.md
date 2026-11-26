# To do: backend and launch (for Kayn-Bot)

Last updated November 27, 2026. The frontend changes listed here are already on `main`; pull first. `docs/waitlist-backend.md` still describes the API.

## Before launch (blocking)

1. **Deploy at the root of magasaka.com.** The page must open at `https://magasaka.com/`, not a sub-path.
   - The share preview tags in `<head>` use absolute `https://magasaka.com/` URLs (`og:url`, `canonical`, `og:image`, `twitter:image`). If the page lives elsewhere, update those lines.
   - Serve `magasaka-waitlist.html` as the index, or rename it to `index.html` and update the "Back to Magasaka" links in `terms.html` and `privacy.html`.
   - Set the web root to `magasaka-waitlist-fullquality/`, never the whole repo (as `waitlist-backend.md` says).
2. **Set `publicPageUrl`** in `magasaka-waitlist-fullquality/waitlist-config.js` to `https://magasaka.com/`. Without it, the share link at the end of signup points at whatever host the page was loaded from.
3. **Pull the title list change.** `backend/waitlist.php` `WAITLIST_TITLES` now has 48 titles (Upwork-style categories). `tests/waitlist.php` uses `Web Developer`. Run the test suite after pulling.
4. **Create `hello@magasaka.com`.** The Privacy Policy and Terms give it as the contact for privacy requests, deletion and complaints, so someone has to read it.
5. **404 page.** `404.html` is in the web root, and `.htaccess` there has `ErrorDocument 404 /404.html` for Apache. On Nginx use `error_page 404 /404.html;`; Netlify, Vercel and GitHub Pages pick up `404.html` automatically. It uses absolute paths (`/frames/...`, `/favicon.svg`), so it works from any missing URL.
6. **Test the share preview** after deploy with Facebook's Sharing Debugger (https://developers.facebook.com/tools/debug/) on `https://magasaka.com/`.

## Promises the site makes that need the backend

7. **Email system.** The last signup screen says:
   - "You're on the list. We'll email you when your invite is ready."
   - "10,000 founding spots. We'll ask you to confirm your email before launch."
   Needed: a transactional email provider, a welcome email, email confirmation (fill `email_verified_at`), the invite email, and an unsubscribe link on any update email. The Privacy Policy says updates can always be stopped.
8. **Deletion and access requests.** The Privacy Policy promises people can leave the waitlist, get a copy of their data, and have it deleted by emailing hello@magasaka.com. A small admin script for "export this email's data" and "delete this email" is enough for now. Remember `waitlist_skills` cascades; decide what happens to `referred_by` on rows that pointed at a deleted member (currently `ON DELETE SET NULL`).
9. **Retention.** The policy says waitlist data is deleted or anonymised if the person hasn't created an account within 12 months of launch, or if Magasaka doesn't launch.

## Nice to have

10. **Referral count.** `referred_by` is already stored. Show members how many people joined through their link (count only, or first name at most, never email; that's what the Privacy Policy says). The Terms say invites do not move people up the line or earn rewards yet; if that changes, the Terms need updating first.
11. **Analytics.** There is none, and the Privacy Policy currently says "no third-party analytics". If you add any (even privacy-friendly ones like Plausible), update the Privacy Policy first.
12. **Mang Juan with a real AI.** In `magasaka-waitlist.html`, search for `ASK_ENDPOINT` (empty now, so he uses fixed local answers).
    - Contract: `POST {messages: [{role, content}, ...]}` and return `{reply: "..."}`. On any error or empty reply the page falls back to the local answers.
    - Keep the API key on the server, rate-limit the route, and give the model the page facts (FAQ, launch timing, Seeds, Founding Members) plus his persona: a warm, playful Filipino tatay who answers in Taglish.
    - The Privacy Policy says chat messages are not sent anywhere. Update it before turning this on.

## Already done on the frontend (for reference)

- Signup flow wired to `api/waitlist.php`, with a pretend-entry fallback only on `localhost` when no PHP answers (for design previews).
- Privacy Policy and Terms rewritten for Magasaka, LLC (Delaware), contact hello@magasaka.com, Philippine Data Privacy Act. They still need a lawyer's review before launch.
- Share preview image (`og-image.jpg`) and favicons.
- Hero frames now load lazily: about 8MB on arrival on desktop, instead of about 95MB.
