(function () {
'use strict';
var header = document.querySelector('.site-header');
var toggle = document.querySelector('.nav-toggle');
if (header && toggle) {
toggle.addEventListener('click', function () {
var open = header.classList.toggle('nav-open');
toggle.setAttribute('aria-expanded', String(open));
toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
});
document.addEventListener('keydown', function (e) {
if (e.key === 'Escape' && header.classList.contains('nav-open')) {
toggle.click();
toggle.focus();
}
});
header.addEventListener('click', function (e) {
if (e.target.closest('.nav a') && header.classList.contains('nav-open')) toggle.click();
});
}
if (header) {
var ticking = false;
var update = function () {
header.classList.toggle('is-scrolled', window.scrollY > 4);
ticking = false;
};
window.addEventListener('scroll', function () {
if (!ticking) { ticking = true; window.requestAnimationFrame(update); }
}, { passive: true });
window.requestAnimationFrame(update);
}
var CONSENT_KEY = 'th_consent';
var banner = document.getElementById('consent');
function readConsent() {
try { return localStorage.getItem(CONSENT_KEY); } catch (e) { return null; }
}
function applyConsent(value) {
try { localStorage.setItem(CONSENT_KEY, value); } catch (e) {  }
if (typeof window.gtag === 'function') {
var v = value === 'granted' ? 'granted' : 'denied';
window.gtag('consent', 'update', { ad_storage: v, ad_user_data: v, ad_personalization: v, analytics_storage: v });
}
}
if (banner) {
if (!readConsent()) banner.hidden = false;
banner.addEventListener('click', function (e) {
var choice = e.target.closest('[data-consent]');
if (!choice) return;
applyConsent(choice.getAttribute('data-consent'));
banner.hidden = true;
});
}
document.addEventListener('click', function (e) {
if (!e.target.closest('[data-open-consent]')) return;
if (banner) {
banner.hidden = false;
var first = banner.querySelector('button');
if (first) first.focus();
return;
}
var shown = false;
window.googlefc = window.googlefc || {};
window.googlefc.callbackQueue = window.googlefc.callbackQueue || [];
window.googlefc.callbackQueue.push({
CONSENT_API_READY: function () {
if (typeof window.googlefc.showRevocationMessage === 'function') {
shown = true;
window.googlefc.showRevocationMessage();
}
}
});
setTimeout(function () {
if (!shown) window.location.href = '/cookie-policy#other-ways-to-control-cookies';
}, 1500);
});
var gaId = document.documentElement.getAttribute('data-ga');
if (gaId) {
var loadGa = function () {
var s = document.createElement('script');
s.async = true;
s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(gaId);
document.head.appendChild(s);
};
window.addEventListener('load', function () {
if ('requestIdleCallback' in window) window.requestIdleCallback(loadGa, { timeout: 4000 });
else setTimeout(loadGa, 1500);
});
}
window.thTrack = function (name, params) {
if (typeof window.gtag === 'function') window.gtag('event', name, params || {});
};
var toastEl = null;
var toastTimer = null;
window.thToast = function (message) {
if (!toastEl) {
toastEl = document.createElement('div');
toastEl.className = 'toast';
toastEl.setAttribute('role', 'status');
document.body.appendChild(toastEl);
}
toastEl.textContent = message;
toastEl.classList.add('show');
clearTimeout(toastTimer);
toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 2400);
};
})();
