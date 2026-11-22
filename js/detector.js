(function () {
'use strict';
var root = document.getElementById('detector');
if (!root) return;
var MAX_CHARS = 10000;
var HANDOFF_KEY = 'th_handoff';
var SAMPLE = 'Artificial intelligence has fundamentally transformed the landscape of modern education. ' +
'Furthermore, the implementation of adaptive learning platforms has enabled unprecedented levels of personalization. ' +
'Additionally, educators can leverage data-driven insights to foster student engagement and improve outcomes. ' +
'Moreover, these innovative tools play a pivotal role in preparing learners for the future workforce. ' +
'Ultimately, embracing these technologies is crucial for institutions seeking to remain competitive.';
function $(id) { return document.getElementById(id); }
var input = $('det-input');
var count = $('det-count');
var runBtn = $('det-run');
var clearBtn = $('det-clear');
var sampleBtn = $('det-sample');
var results = $('det-results');
var statusEl = $('det-status');
var ring = $('det-ring');
var scoreEl = $('det-score');
var verdictEl = $('det-verdict');
var confidenceEl = $('det-confidence');
var signalsEl = $('det-signals');
var sentencesWrap = $('det-sentences-wrap');
var sentencesEl = $('det-sentences');
var handoffBtn = $('det-handoff');
var runLabel = runBtn.innerHTML;
var busy = false;
var skeleton = document.createElement('div');
skeleton.className = 'skeleton det-skeleton';
skeleton.setAttribute('aria-hidden', 'true');
skeleton.innerHTML = '<span></span><span></span><span></span><span></span><span></span>';
skeleton.hidden = true;
results.parentNode.insertBefore(skeleton, results);
function countWords(text) { var t = text.trim(); return t ? t.split(/\s+/).length : 0; }
function fmt(n) { return Number(n).toLocaleString('en-US'); }
function update() {
var text = input.value;
var over = text.length > MAX_CHARS;
count.textContent = fmt(countWords(text)) + ' words · ' + fmt(text.length) + ' / ' + fmt(MAX_CHARS) + ' characters';
count.classList.toggle('is-over', over);
runBtn.disabled = busy || !text.trim() || over;
clearBtn.disabled = busy || !text;
}
input.addEventListener('input', update);
function setStatus(message, isError) {
statusEl.textContent = message || '';
statusEl.className = isError ? 'tool-msg alert alert-error' : 'tool-msg muted small';
statusEl.hidden = !message;
}
function colorFor(score) {
if (score >= 56) return 'var(--danger)';
if (score >= 31) return 'var(--warn)';
return 'var(--success)';
}
function render(data) {
var score = Math.max(0, Math.min(100, Math.round(Number(data.score) || 0)));
ring.style.setProperty('--p', score);
ring.style.setProperty('--c', colorFor(score));
scoreEl.textContent = score;
verdictEl.textContent = data.verdict || 'Result';
var conf = data.confidence && data.confidence.label ? data.confidence.label : null;
var basis = data.mlSource ? 'writing-pattern metrics plus an external detection model' : 'writing-pattern metrics only';
confidenceEl.textContent = (conf ? 'Confidence: ' + conf + '. ' : '') + 'Based on ' + basis + '.';
signalsEl.textContent = '';
(Array.isArray(data.reasons) ? data.reasons : []).forEach(function (reason) {
var text = String(reason);
var li = document.createElement('li');
var dot = document.createElement('span');
var kind = 'dot-flag';
if (text.charAt(0) === '✓') { kind = 'dot-ok'; text = text.slice(1).trim(); }
else if (/^(Reliability note|High confidence)/.test(text)) { kind = ''; }
dot.className = 'dot ' + kind;
dot.setAttribute('aria-hidden', 'true');
var span = document.createElement('span');
span.textContent = text.replace(/\s+[—–]\s+/, ': ').replace(/\s+[—–]\s+/g, ', ');
li.appendChild(dot);
li.appendChild(span);
signalsEl.appendChild(li);
});
var sentences = Array.isArray(data.sentenceScores) ? data.sentenceScores : [];
sentencesEl.textContent = '';
if (sentences.length) {
sentences.forEach(function (s) {
var span = document.createElement('span');
var value = Number(s.score) || 0;
if (value >= 70) span.className = 's-high';
else if (value >= 40) span.className = 's-mid';
span.textContent = (s.text || '') + ' ';
sentencesEl.appendChild(span);
});
sentencesWrap.hidden = false;
} else {
sentencesWrap.hidden = true;
}
results.hidden = false;
handoffBtn.hidden = score <= 30;
}
function run() {
var text = input.value.trim();
if (busy || !text || text.length > MAX_CHARS) return;
busy = true;
update();
runBtn.innerHTML = '<span class="spinner" aria-hidden="true"></span> Analyzing…';
setStatus('');
results.hidden = true;
skeleton.hidden = false;
if (window.thTrack) window.thTrack('detector_start', { words: countWords(text) });
fetch('/api/detect.php', {
method: 'POST',
headers: { 'Content-Type': 'application/json' },
body: JSON.stringify({ text: text })
})
.then(function (res) {
return res.text().then(function (raw) {
var data = {};
try { data = raw ? JSON.parse(raw) : {}; } catch (e) { data = {}; }
return { res: res, data: data };
});
})
.then(function (r) {
if (r.res.status === 429) throw new Error('You’ve run several checks in a short time. Please wait a minute and try again.');
var err = r.data.error;
if (!r.res.ok || err) throw new Error((err && (err.message || err)) || 'The analysis failed. Please try again.');
render(r.data);
if (window.thTrack) {
var ml = r.data.mlSource;
window.thTrack('detector_result', {
verdict: r.data.verdict || 'unknown',
score: Math.max(0, Math.min(100, Math.round(Number(r.data.score) || 0))),
confidence: (r.data.confidence && r.data.confidence.label) || 'unknown',
model: ml ? (Array.isArray(ml) ? ml.join('+') : String(ml)) : 'none',
words: countWords(text)
});
}
results.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
})
.catch(function (e) {
results.hidden = true;
setStatus(e instanceof TypeError ? 'We couldn’t reach the server. Check your connection and try again.' : e.message, true);
})
.then(function () {
busy = false;
skeleton.hidden = true;
runBtn.innerHTML = runLabel;
update();
});
}
runBtn.addEventListener('click', run);
input.addEventListener('keydown', function (e) {
if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); if (!runBtn.disabled) run(); }
});
clearBtn.addEventListener('click', function () {
input.value = '';
results.hidden = true;
setStatus('');
update();
input.focus();
});
sampleBtn.addEventListener('click', function () {
input.value = SAMPLE;
results.hidden = true;
setStatus('');
update();
input.focus();
});
handoffBtn.addEventListener('click', function () {
try { sessionStorage.setItem(HANDOFF_KEY, input.value); } catch (e) {  }
window.location.href = '/#tool';
});
update();
})();
