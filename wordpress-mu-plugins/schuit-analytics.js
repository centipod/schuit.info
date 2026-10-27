/* SCHUIT GA4: basic consent mode; no Google request before explicit consent. */
(() => {
  'use strict';
  const hosts = ['schuit.info', 'www.schuit.info', 'schuit.centipod.ai'];
  const id = 'G-LB9BXB5TC4';
  const key = 'schuit-analytics-consent-v1';
  const lifetime = 180 * 86400000;
  const path = location.pathname.toLowerCase();
  if (!hosts.includes(location.hostname) || location.protocol !== 'https:') return;
  // Suppress administration, authentication, forms, searches and legacy query routes.
  if (/wp-admin|wp-login|wp-json|login|logout|register|password|admin|contact|search|zoeken/.test(path) || location.search) return;
  // Only broad sections leave the browser; no names, tree IDs, record URLs or titles.
  const section = path.startsWith('/tree/') ? '/tree/' :
    path === '/' ? '/' : path.startsWith('/publicaties') ? '/publicaties/' :
      path.startsWith('/de-stichting') ? '/de-stichting/' : '/website/';
  let choice = null;
  try {
    const saved = JSON.parse(localStorage.getItem(key));
    if (saved && ['yes', 'no'].includes(saved.value) && saved.until > Date.now()) choice = saved.value;
  } catch { /* No storage means ask again; never assume consent. */ }
  let referrer = '';
  try {
    const url = new URL(document.referrer);
    if (/^(www\.)?(google\.(com|nl|co\.za)|bing\.com|duckduckgo\.com|facebook\.com|linkedin\.com)$/.test(url.hostname)) referrer = url.origin + '/';
  } catch { /* Direct visits and unknown sources have no exported referrer. */ }
  let started = false;
  let sent = false;
  const gtag = function () { window.dataLayer.push(arguments); };
  function start() {
    if (started || choice !== 'yes') return;
    started = true;
    window['ga-disable-' + id] = false;
    window.dataLayer = window.dataLayer || [];
    gtag('consent', 'default', {analytics_storage:'granted', ad_storage:'denied', ad_user_data:'denied', ad_personalization:'denied'});
    gtag('js', new Date());
    const safe = {page_location:location.origin + section, page_title:section === '/tree/' ? 'SCHUIT stamboom' : 'SCHUIT website', page_referrer:referrer, allow_google_signals:false, allow_ad_personalization_signals:false};
    gtag('set', safe);
    gtag('config', id, {...safe, send_page_view:false, cookie_domain:location.hostname, cookie_expires:15552000, cookie_flags:'SameSite=Lax;Secure'});
    if (!sent) { gtag('event', 'page_view', {...safe, send_to:id}); sent = true; }
    const script = document.createElement('script');
    script.async = true;
    script.referrerPolicy = 'no-referrer';
    script.src = 'https://www.googletagmanager.com/gtag/js?id=' + id;
    document.head.append(script);
  }
  function save(value) {
    choice = value;
    try { localStorage.setItem(key, JSON.stringify({value, until:Date.now() + lifetime})); } catch { /* Session-only choice. */ }
    panel.hidden = true;
    if (value === 'yes') start();
    else {
      window['ga-disable-' + id] = true;
      // Remove only this site's Analytics cookies. Never touch authentication cookies.
      for (const cookie of document.cookie.split(';')) {
        const name = cookie.trim().split('=')[0];
        if (name !== '_ga' && name !== '_ga_LB9BXB5TC4') continue;
        for (const domain of ['', '; Domain=' + location.hostname]) {
          document.cookie = name + '=; Max-Age=0; Path=/; Secure; SameSite=Lax' + domain;
        }
      }
      if (started) location.reload(); // Remove loaded Google code and pending activity.
    }
  }
  const style = document.createElement('style');
  style.textContent = '#schuit-analytics-choice{position:fixed;bottom:14px;right:14px;z-index:10000;max-width:420px;margin-left:14px;padding:20px;background:#fffdf7;color:#24382e;border:1px solid #617d68;border-radius:8px;box-shadow:0 4px 24px #0002;font:15px/1.5 sans-serif}#schuit-analytics-choice[hidden]{display:none}#schuit-analytics-choice button,#schuit-analytics-settings{font:inherit;cursor:pointer;padding:9px 14px;margin:4px;border:1px solid #526e5b;border-radius:4px;background:#fffdf7;color:#24382e}#schuit-analytics-settings{display:block;margin:12px auto;font:13px sans-serif}#schuit-analytics-choice p{margin:10px 0}';
  document.head.append(style);
  const panel = document.createElement('section');
  panel.id = 'schuit-analytics-choice';
  panel.setAttribute('aria-label', 'Keuze voor bezoekersstatistieken');
  panel.innerHTML = '<strong>Bezoekersstatistieken</strong><p>Mogen we Google Analytics gebruiken om bezoeken aan deze website te tellen? Google verwerkt daarbij onder meer uw IP-adres en een cookie-ID. We sturen geen stamboomnamen, zoektermen of volledige pagina-adressen. U kunt uw keuze later wijzigen via “Statistiekvoorkeuren”.</p><button type="button" data-choice="no">Weigeren</button><button type="button" data-choice="yes">Toestaan</button>';
  panel.hidden = choice !== null;
  panel.querySelectorAll('button').forEach(button => button.addEventListener('click', () => save(button.dataset.choice)));
  const settings = document.createElement('button');
  settings.id = 'schuit-analytics-settings'; settings.type = 'button'; settings.textContent = 'Statistiekvoorkeuren';
  settings.addEventListener('click', () => { panel.hidden = false; panel.querySelector('button').focus(); });
  document.body.append(settings, panel);
  if (choice === 'yes') start();
})();
