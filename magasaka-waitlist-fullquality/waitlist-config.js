// A static preview can use a hosted PHP API by changing apiUrl.
// That server must explicitly allow the preview's origin. No database secrets belong here.
window.MAGASAKA_WAITLIST = Object.assign({
  apiUrl: 'api/waitlist.php',
  // Mang Juan's AI answers (server side; the page falls back to built-in replies without it).
  juanUrl: 'api/juan.php',
  // Explicit localhost-only design preview. Demo entries are never real registrations.
  previewMode: false,
  // Set this to the deployed HTML page URL for public referral links.
  publicPageUrl: ''
}, window.MAGASAKA_WAITLIST || {});
