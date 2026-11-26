// A static preview can use a hosted PHP API by changing apiUrl.
// That server must explicitly allow the preview's origin. No database secrets belong here.
window.MAGASAKA_WAITLIST = Object.assign({
  apiUrl: 'api/waitlist.php',
  // Set this to the deployed HTML page URL for public referral links.
  publicPageUrl: ''
}, window.MAGASAKA_WAITLIST || {});
