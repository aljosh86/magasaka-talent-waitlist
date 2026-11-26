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

  async function request(action, input) {
    const email = input.email.trim().toLowerCase();
    const token = tokenFor(email, action === 'join');
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(apiUrl, {
        method: 'POST', credentials: 'omit', cache: 'no-store', signal: controller.signal,
        headers: {'Content-Type': 'application/json', 'X-Waitlist-Token': token},
        body: JSON.stringify({...input, email, action})
      });
      let result;
      try { result = await response.json(); }
      catch (_) { throw new Error('We could not confirm your signup. Please try again shortly.'); }
      if (!response.ok || !result.ok) {
        const error = new Error(result.message || 'We could not save your details. Please try again.');
        error.code = result.code;
        throw error;
      }
      const entry = result.entry;
      if (!entry || !Number.isInteger(entry.no) || entry.no < 1 || entry.no > 10000
        || typeof entry.ref !== 'string' || !Array.isArray(entry.picks)) {
        throw new Error('We could not confirm your signup. Please try again shortly.');
      }
      return entry;
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
    shareUrl: ref => {
      const url = new URL(config.publicPageUrl || location.pathname, location.href);
      url.search = ''; url.hash = ''; url.searchParams.set('ref', ref);
      return url.href;
    }
  };
})();
