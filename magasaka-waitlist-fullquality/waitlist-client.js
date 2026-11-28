/* The page remains a static HTML frontend; only signup persistence requires PHP. */
(() => {
  'use strict';
  const config = window.MAGASAKA_WAITLIST || {};
  const apiUrl = new URL(config.apiUrl || 'api/waitlist.php', location.href).href;
  const memory = new Map();

  function tokenFor(email, create) {
    const key = 'mg-waitlist-token:' + apiUrl + ':' + email;
    let token = memory.get(key);
    if (!token) { try { token = localStorage.getItem(key); } catch (_) {} }
    if (!/^[a-f0-9]{64}$/.test(token || '') && create) {
      token = [...crypto.getRandomValues(new Uint8Array(32))]
        .map(byte => byte.toString(16).padStart(2, '0')).join('');
      // Persist before sending: a retry after a lost response must reuse the same key.
      try { localStorage.setItem(key, token); } catch (_) {}
    }
    if (!/^[a-f0-9]{64}$/.test(token || '')) {
      throw new Error('Please re-enter your email to continue your signup.');
    }
    memory.set(key, token);
    return token;
  }

  // Opt-in local design preview only. A failed real API must never silently verify a signup.
  const isLocal = config.previewMode === true && /^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname);
  function previewEntry(action, input, email) {
    const KEY = 'mg-waitlist-preview:' + email;
    let entry = null;
    try { entry = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (_) {}
    if (!entry) {
      entry = {email, name: '', title: '', picks: [], no: 1 + Math.floor(Math.random() * 999), complete: false, verified: false,
        ref: 'preview' + Math.random().toString(36).slice(2, 8), referredBy: input.referredBy || ''};
    }
    if (action === 'name' || action === 'complete') entry.name = input.name;
    if (action === 'complete') Object.assign(entry, {title: input.title, picks: input.picks, complete: true});
    entry.preview = true;
    if (action === 'send_code') entry.verification = {sent:true, resendAfter:0};
    if (action === 'verify') {
      if (input.code !== '000000') throw new Error('Design preview: enter 000000. No email is sent.');
      entry.verified = true;
    }
    try { localStorage.setItem(KEY, JSON.stringify(entry)); } catch (_) {}
    console.warn('[waitlist] No PHP API on this local preview; using a pretend entry (nothing is saved to the database).');
    return entry;
  }

  async function request(action, input) {
    try { return await send(action, input); }
    catch (error) {
      if (isLocal && error.noApi) return previewEntry(action, input, input.email.trim().toLowerCase());
      throw error;
    }
  }

  async function send(action, input) {
    const email = input.email.trim().toLowerCase();
    // recovery hands an existing signup to this browser's key, so it may create one like join
    const token = tokenFor(email, action === 'join' || action === 'recover_send' || action === 'recover');
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), action === 'send_code' || action === 'recover_send' ? 45000 : 15000);
    try {
      const response = await fetch(apiUrl, {
        method: 'POST', credentials: 'omit', cache: 'no-store', signal: controller.signal,
        headers: {'Content-Type': 'application/json', 'X-Waitlist-Token': token},
        body: JSON.stringify({...input, email, action})
      });
      let result;
      try { result = await response.json(); }
      catch (_) {
        const error = new Error('We could not confirm your signup. Please try again shortly.');
        error.noApi = true;   // not JSON: there is no API behind this page
        throw error;
      }
      if (!response.ok || !result.ok) {
        const error = new Error(result.message || 'We could not save your details. Please try again.');
        error.code = result.code;
        error.retryAfter = Number(response.headers.get('Retry-After')) || 0;
        throw error;
      }
      if (action === 'recover_send') return result.verification || {sent: true, resendAfter: 60};
      const entry = result.entry;
      if (!entry || !Number.isInteger(entry.no) || entry.no < 1 || entry.no > 10000
        || typeof entry.ref !== 'string' || !Array.isArray(entry.picks)) {
        throw new Error('We could not confirm your signup. Please try again shortly.');
      }
      return {...entry, verification: result.verification || null};
    } catch (error) {
      if (error.name === 'AbortError' || error instanceof TypeError) {
        throw new Error('We could not confirm your signup. Check your connection and try again.');
      }
      throw error;
    } finally { clearTimeout(timeout); }
  }

  window.MagasakaWaitlist = {
    apiUrl,
    join: (email, referredBy) => request('join', {email, referredBy}),
    saveName: (email, name) => request('name', {email, name}),
    complete: ({email, name, title, picks}) => request('complete', {email, name, title, picks}),
    sendCode: (email, resend = false) => request('send_code', {email, resend}),
    verify: (email, code) => request('verify', {email, code}),
    // an email already on the waitlist, in a browser without its key: a code to that inbox moves the signup here
    recoverSend: (email, resend = false) => send('recover_send', {email, resend}),
    recover: (email, code) => send('recover', {email, code}),
    shareUrl: ref => {
      const url = new URL(config.publicPageUrl || location.pathname, location.href);
      url.search = ''; url.hash = ''; url.searchParams.set('ref', ref);
      return url.href;
    }
  };
})();
