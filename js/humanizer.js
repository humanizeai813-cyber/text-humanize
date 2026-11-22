(function () {
'use strict';
var tool = document.getElementById('tool');
if (!tool) return;
var MAX_CHARS = 10000;
var SETTINGS_KEY = 'th_settings';
var HISTORY_KEY = 'th_history';
var HANDOFF_KEY = 'th_handoff';
var HISTORY_MAX = 10;
var SAMPLE = 'In today’s fast-paced digital landscape, effective communication has become more important than ever. ' +
'Furthermore, organizations must leverage cutting-edge tools to streamline their workflows and foster collaboration. ' +
'By embracing innovative solutions, teams can unlock their full potential and drive meaningful results. ' +
'Additionally, it is crucial to note that clear writing plays a pivotal role in building trust with customers.';
function $(id) { return document.getElementById(id); }
var input = $('tool-input');
var output = $('tool-output');
var runBtn = $('tool-run');
var cancelBtn = $('tool-cancel');
var copyBtn = $('tool-copy');
var downloadBtn = $('tool-download');
var compareBtn = $('tool-compare');
var diffViewSelector = $('diff-view-selector');
var modeCleanBtn = $('view-mode-clean');
var modeDiffBtn = $('view-mode-diff');
var modeSplitBtn = $('view-mode-split');
var diffCountBadge = $('diff-count-badge');
var sampleBtn = $('tool-sample');
var pasteBtn = $('tool-paste');
var clearBtn = $('tool-clear');
var inCount = $('tool-in-count');
var outCount = $('tool-out-count');
var statusEl = $('tool-status');
var notice = $('tool-notice');
var statsEl = $('tool-stats');
var purposeEl = $('tool-purpose');
var historyBtn = $('tool-history');
var historyCount = $('history-count');
var drawer = $('history-drawer');
var historyList = $('history-list');
var historyClear = $('history-clear');
var historyClose = $('history-close');
var feedbackEl = $('tool-feedback');
var reasonsEl = $('feedback-reasons');
var thanksEl = $('feedback-thanks');
var emptyMarkup = output.innerHTML;
var runLabel = runBtn.innerHTML;
var state = { original: '', result: '', busy: false, controller: null, timedOut: false, showingDiff: false, lastRun: null, diff: null, viewMode: 'clean' };
function load(key, fallback) {
try { var v = localStorage.getItem(key); return v ? JSON.parse(v) : fallback; } catch (e) { return fallback; }
}
function save(key, value) {
try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) {  }
}
function countWords(text) { var t = text.trim(); return t ? t.split(/\s+/).length : 0; }
function fmt(n) { return Number(n).toLocaleString('en-US'); }
function track(name, params) { if (window.thTrack) window.thTrack(name, params); }
function toast(msg) { if (window.thToast) window.thToast(msg); }
function radioValue(name) {
var checked = tool.querySelector('input[name="' + name + '"]:checked');
return checked ? checked.value : null;
}
function setRadio(name, value) {
var r = tool.querySelector('input[name="' + name + '"][value="' + value + '"]');
if (r) r.checked = true;
}
function currentSettings() {
return {
tone: radioValue('tone') || 'natural',
level: radioValue('level') || 'balanced',
purpose: (purposeEl && purposeEl.value) || 'general'
};
}
(function restoreSettings() {
var s = load(SETTINGS_KEY, null);
if (!s) return;
if (s.tone) setRadio('tone', s.tone);
if (s.level) setRadio('level', s.level);
if (purposeEl && s.purpose) {
purposeEl.value = s.purpose;
if (!purposeEl.value) purposeEl.value = 'general';
}
})();
tool.addEventListener('change', function (e) {
if (e.target.name === 'tone' || e.target.name === 'level' || e.target === purposeEl) save(SETTINGS_KEY, currentSettings());
});
function setNotice(kind, message, tag) {
notice.className = 'tool-notice alert alert-' + kind;
notice.textContent = message;
notice.setAttribute('data-tag', tag || '');
notice.hidden = false;
}
function clearNotice(tag) {
if (tag && notice.getAttribute('data-tag') !== tag) return;
notice.hidden = true;
notice.textContent = '';
notice.setAttribute('data-tag', '');
}
function setStatus(message, spinning) {
statusEl.textContent = '';
if (spinning) {
var s = document.createElement('span');
s.className = 'spinner';
s.setAttribute('aria-hidden', 'true');
statusEl.appendChild(s);
}
if (message) statusEl.appendChild(document.createTextNode(message));
}
function updateInput() {
var text = input.value;
var chars = text.length;
var over = chars > MAX_CHARS;
inCount.textContent = fmt(countWords(text)) + ' words · ' + fmt(chars) + ' / ' + fmt(MAX_CHARS) + ' characters';
inCount.classList.toggle('is-over', over);
runBtn.disabled = state.busy || !text.trim() || over;
clearBtn.disabled = state.busy || !text;
if (over) {
setNotice('warn', 'That’s over the 10,000 character limit. Split long documents into sections and rewrite them one at a time.', 'limit');
} else {
clearNotice('limit');
}
}
input.addEventListener('input', updateInput);
function showResultActions(show) {
copyBtn.hidden = !show;
downloadBtn.hidden = !show;
if (diffViewSelector) diffViewSelector.hidden = !show;
compareBtn.hidden = true;
}
function resetCompare() {
state.showingDiff = false;
state.viewMode = 'clean';
updateViewPills('clean');
compareBtn.setAttribute('aria-pressed', 'false');
compareBtn.textContent = 'Show changes';
}
function resetOutput() {
output.innerHTML = emptyMarkup;
output.removeAttribute('aria-busy');
state.original = '';
state.result = '';
state.diff = null;
state.lastRun = null;
state.viewMode = 'clean';
updateViewPills('clean');
resetCompare();
resetFeedback();
showResultActions(false);
statsEl.hidden = true;
statsEl.textContent = '';
outCount.textContent = '';
setStatus('');
}
function setBusy(busy) {
state.busy = busy;
cancelBtn.hidden = !busy;
input.readOnly = busy;
sampleBtn.disabled = busy;
if (busy) {
runBtn.disabled = true;
runBtn.innerHTML = '<span class="spinner" aria-hidden="true"></span> Rewriting…';
} else {
runBtn.innerHTML = runLabel;
}
}
var FEEDBACK_TIPS = {
meaning_changed: 'Thanks. For a closer rewrite, choose Light and check each edit with “Show changes”.',
too_many_changes: 'Thanks. Choose Light to keep more of your own wording.',
too_few_changes: 'Thanks. Choose Strong for a bigger rewrite.',
still_robotic: 'Thanks. Try Strong, or the Casual tone, for a looser rewrite.',
awkward: 'Thanks. Try another tone, or smooth out the lines that sound off before you use the text.'
};
function resetFeedback() {
feedbackEl.hidden = true;
feedbackEl.classList.remove('is-rated');
reasonsEl.hidden = true;
thanksEl.textContent = '';
Array.prototype.forEach.call(feedbackEl.querySelectorAll('[aria-pressed]'), function (btn) {
btn.setAttribute('aria-pressed', 'false');
});
}
function feedbackParams(name, value) {
var run = state.lastRun;
var params = { tone: run.tone, level: run.level, purpose: run.purpose, mode: run.mode };
params[name] = value;
return params;
}
feedbackEl.addEventListener('click', function (e) {
var btn = e.target.closest('button');
if (!btn || !state.lastRun) return;
var rating = btn.getAttribute('data-rating');
var reason = btn.getAttribute('data-reason');
if (rating && !feedbackEl.classList.contains('is-rated')) {
feedbackEl.classList.add('is-rated');
btn.setAttribute('aria-pressed', 'true');
track('rewrite_feedback', feedbackParams('rating', rating));
if (rating === 'helpful') thanksEl.textContent = 'Thanks for letting us know.';
else reasonsEl.hidden = false;
} else if (reason && btn.getAttribute('aria-pressed') !== 'true') {
btn.setAttribute('aria-pressed', 'true');
track('rewrite_feedback_reason', feedbackParams('reason', reason));
thanksEl.textContent = FEEDBACK_TIPS[reason] || 'Thanks for the feedback.';
}
});
function sentenceLengths(text) {
var parts = text.match(/[^.!?]+(?:[.!?]+|$)/g) || [];
return parts.map(function (s) { return countWords(s); }).filter(function (n) { return n > 0; });
}
function syllables(word) {
var w = word.toLowerCase().replace(/[^a-z]/g, '');
if (!w) return 0;
if (w.length <= 3) return 1;
var groups = w.replace(/(?:[^laeiouy]es|ed|[^laeiouy]e)$/, '').replace(/^y/, '').match(/[aeiouy]{1,2}/g);
return Math.max(1, groups ? groups.length : 1);
}
function readingEase(text) {
var words = text.match(/[A-Za-zÀ-ɏ']+/g) || [];
var sentences = sentenceLengths(text).length;
if (words.length < 5 || !sentences) return null;
var syl = words.reduce(function (sum, w) { return sum + syllables(w); }, 0);
var score = 206.835 - 1.015 * (words.length / sentences) - 84.6 * (syl / words.length);
return Math.max(0, Math.min(100, Math.round(score)));
}
function easeLabel(score) {
if (score >= 80) return 'easy';
if (score >= 60) return 'plain English';
if (score >= 50) return 'fairly difficult';
if (score >= 30) return 'difficult';
return 'very difficult';
}
function average(list) {
return list.length ? list.reduce(function (a, b) { return a + b; }, 0) / list.length : 0;
}
function tokens(text) {
var parts = text.split(/(\n\s*\n)/);
var toks = [];
for (var i = 0; i < parts.length; i++) {
var part = parts[i];
if (/^\n\s*\n$/.test(part)) {
toks.push('\n\n');
} else {
var words = part.match(/\S+/g);
if (words) toks.push.apply(toks, words);
}
}
return toks;
}
function diffOps(a, b) {
var n = a.length, m = b.length;
if (n * m > 500000) return null;
var la = a.map(function (w) { return w.toLowerCase(); });
var lb = b.map(function (w) { return w.toLowerCase(); });
var dp = [];
var i, j;
for (i = 0; i <= n; i++) dp.push(new Uint16Array(m + 1));
for (i = n - 1; i >= 0; i--) {
for (j = m - 1; j >= 0; j--) {
dp[i][j] = la[i] === lb[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
}
}
var ops = [];
i = 0; j = 0;
while (i < n && j < m) {
if (la[i] === lb[j]) { ops.push(['=', b[j]]); i++; j++; }
else if (dp[i + 1][j] >= dp[i][j + 1]) { ops.push(['-', a[i]]); i++; }
else { ops.push(['+', b[j]]); j++; }
}
while (i < n) ops.push(['-', a[i++]]);
while (j < m) ops.push(['+', b[j++]]);
return ops;
}
function groupOps(ops) {
if (!ops || !ops.length) return [];
var grouped = [];
var curOp = ops[0][0];
var curWords = [ops[0][1]];
for (var i = 1; i < ops.length; i++) {
var op = ops[i][0];
var word = ops[i][1];
if (word === '\n\n' || (curWords.length && curWords[curWords.length - 1] === '\n\n')) {
grouped.push([curOp, curWords.join(' ')]);
curOp = op;
curWords = [word];
} else if (op === curOp) {
curWords.push(word);
} else {
grouped.push([curOp, curWords.join(' ')]);
curOp = op;
curWords = [word];
}
}
if (curWords.length) grouped.push([curOp, curWords.join(' ')]);
return grouped;
}
function wordDiff(original, result) {
var a = tokens(original), b = tokens(result);
var ops = diffOps(a, b);
var changed = 0;
var changedPhrases = 0;
var grouped = null;
if (ops) {
changed = ops.filter(function (o) { return o[0] === '+' && o[1] !== '\n\n'; }).length;
grouped = groupOps(ops);
changedPhrases = grouped.filter(function (g) { return g[0] === '+' && g[1] !== '\n\n'; }).length;
} else {
var seen = Object.create(null);
a.forEach(function (w) { seen[w.toLowerCase()] = true; });
changed = b.filter(function (w) { return !seen[w.toLowerCase()] && w !== '\n\n'; }).length;
changedPhrases = changed;
}
var totalWords = b.filter(function (w) { return w !== '\n\n'; }).length;
return { ops: ops, grouped: grouped, total: totalWords, changed: changed, changedPhrases: changedPhrases };
}
function updateViewPills(mode) {
if (!diffViewSelector) return;
var pills = [
{ btn: modeCleanBtn, mode: 'clean' },
{ btn: modeDiffBtn, mode: 'diff' },
{ btn: modeSplitBtn, mode: 'split' }
];
pills.forEach(function (item) {
if (!item.btn) return;
var isActive = item.mode === mode;
item.btn.classList.toggle('active', isActive);
item.btn.setAttribute('aria-selected', String(isActive));
});
}
function renderClean() {
output.textContent = '';
var wrap = document.createElement('div');
wrap.className = 'clean-output-wrapper';
var paras = state.result.split(/\n\s*\n/);
paras.forEach(function (para) {
var trimmed = para.trim();
if (!trimmed) return;
var p = document.createElement('p');
p.className = 'clean-output-p';
p.textContent = trimmed;
wrap.appendChild(p);
});
output.appendChild(wrap);
}
function renderInlineDiff() {
var d = state.diff || (state.diff = wordDiff(state.original, state.result));
output.textContent = '';
if (!d.grouped) {
var p = document.createElement('p');
p.className = 'muted';
p.textContent = 'This text is too long to compare word by word.';
output.appendChild(p);
return;
}
var header = document.createElement('div');
header.className = 'diff-header-bar';
header.innerHTML = '<div class="diff-legend-group">' +
'<span class="legend-pill"><span class="legend-dot add"></span><strong>Humanized Flow</strong> (added natural phrasing)</span>' +
'<span class="legend-pill"><span class="legend-dot del"></span><strong>Eliminated AI Patterns</strong> (robotic words)</span>' +
'</div>' +
'<span class="diff-summary-stat">✨ ' + (d.changedPhrases || d.changed) + ' phrases humanized</span>';

var wrap = document.createElement('div');
wrap.className = 'diff-wrapper';
var currentPara = document.createElement('p');
currentPara.className = 'diff-para';

d.grouped.forEach(function (item) {
var op = item[0];
var text = item[1];
if (text === '\n\n') {
wrap.appendChild(currentPara);
currentPara = document.createElement('p');
currentPara.className = 'diff-para';
return;
}
if (op === '=') {
currentPara.appendChild(document.createTextNode(text + ' '));
} else if (op === '+') {
var ins = document.createElement('ins');
ins.className = 'diff-add';
ins.textContent = text;
currentPara.appendChild(ins);
currentPara.appendChild(document.createTextNode(' '));
} else if (op === '-') {
var del = document.createElement('del');
del.className = 'diff-del';
del.textContent = text;
currentPara.appendChild(del);
currentPara.appendChild(document.createTextNode(' '));
}
});
if (currentPara.childNodes.length) wrap.appendChild(currentPara);
output.appendChild(header);
output.appendChild(wrap);
}
function renderSideBySide() {
var d = state.diff || (state.diff = wordDiff(state.original, state.result));
output.textContent = '';
if (!d.grouped) {
var p = document.createElement('p');
p.className = 'muted';
p.textContent = 'This text is too long to compare word by word.';
output.appendChild(p);
return;
}
var header = document.createElement('div');
header.className = 'diff-header-bar';
header.innerHTML = '<div class="diff-legend-group">' +
'<span class="legend-pill"><span class="legend-dot del"></span><strong>Original Draft</strong> (AI phrasing flagged)</span>' +
'<span class="legend-pill"><span class="legend-dot add"></span><strong>Humanized Result</strong> (natural cadence)</span>' +
'</div>' +
'<span class="diff-summary-stat">Side-by-Side Comparison</span>';

var container = document.createElement('div');
container.className = 'diff-split-container';

var colBefore = document.createElement('div');
colBefore.className = 'split-col';
var headBefore = document.createElement('div');
headBefore.className = 'split-col-head col-before';
headBefore.innerHTML = '<span>🔴 Before: Original AI Draft</span><span class="split-sub">Eliminated patterns</span>';
var bodyBefore = document.createElement('div');
bodyBefore.className = 'split-col-body';

var colAfter = document.createElement('div');
colAfter.className = 'split-col';
var headAfter = document.createElement('div');
headAfter.className = 'split-col-head col-after';
headAfter.innerHTML = '<span>🟢 After: Humanized Result</span><span class="split-sub">Human flow applied</span>';
var bodyAfter = document.createElement('div');
bodyAfter.className = 'split-col-body';

var curParaBefore = document.createElement('p');
curParaBefore.className = 'diff-para';
var curParaAfter = document.createElement('p');
curParaAfter.className = 'diff-para';

d.grouped.forEach(function (item) {
var op = item[0];
var text = item[1];
if (text === '\n\n') {
bodyBefore.appendChild(curParaBefore);
curParaBefore = document.createElement('p');
curParaBefore.className = 'diff-para';
bodyAfter.appendChild(curParaAfter);
curParaAfter = document.createElement('p');
curParaAfter.className = 'diff-para';
return;
}
if (op === '=') {
curParaBefore.appendChild(document.createTextNode(text + ' '));
curParaAfter.appendChild(document.createTextNode(text + ' '));
} else if (op === '-') {
var del = document.createElement('del');
del.className = 'diff-del';
del.textContent = text;
curParaBefore.appendChild(del);
curParaBefore.appendChild(document.createTextNode(' '));
} else if (op === '+') {
var ins = document.createElement('ins');
ins.className = 'diff-add';
ins.textContent = text;
curParaAfter.appendChild(ins);
curParaAfter.appendChild(document.createTextNode(' '));
}
});
if (curParaBefore.childNodes.length) bodyBefore.appendChild(curParaBefore);
if (curParaAfter.childNodes.length) bodyAfter.appendChild(curParaAfter);

colBefore.appendChild(headBefore);
colBefore.appendChild(bodyBefore);
colAfter.appendChild(headAfter);
colAfter.appendChild(bodyAfter);
container.appendChild(colBefore);
container.appendChild(colAfter);
output.appendChild(header);
output.appendChild(container);
}
function renderCurrentView() {
if (state.viewMode === 'diff') {
renderInlineDiff();
} else if (state.viewMode === 'split') {
renderSideBySide();
} else {
renderClean();
}
}
function switchViewMode(mode) {
if (!state.result) return;
state.viewMode = mode;
state.showingDiff = mode !== 'clean';
updateViewPills(mode);
renderCurrentView();
}
function renderStats(data, diff) {
var d = diff || wordDiff(state.original, state.result);
var easeBefore = readingEase(state.original);
var easeAfter = readingEase(state.result);
var lenBefore = average(sentenceLengths(state.original));
var lenAfter = average(sentenceLengths(state.result));
var items = [
['Words changed', d.total ? Math.min(100, Math.round((d.changed / d.total) * 100)) + '%' : 'n/a'],
['Reading ease (0-100)', easeAfter === null ? 'n/a' : (easeBefore === null ? '' : easeBefore + ' → ') + easeAfter + ', ' + easeLabel(easeAfter)],
['Avg. sentence length', lenBefore ? Math.round(lenBefore) + ' → ' + Math.round(lenAfter) + ' words' : 'n/a']
];
var accuracy = data && data.accuracy;
if (accuracy && typeof accuracy.protectedTokenRetention === 'number') {
items.push(['Numbers, names & links kept', Math.round(accuracy.protectedTokenRetention * 100) + '%']);
}
statsEl.textContent = '';
items.forEach(function (item) {
var box = document.createElement('div');
box.className = 'stat';
var dt = document.createElement('dt');
dt.textContent = item[0];
var dd = document.createElement('dd');
dd.textContent = item[1];
box.appendChild(dt);
box.appendChild(dd);
statsEl.appendChild(box);
});
statsEl.hidden = false;
}
function showResult(data) {
output.removeAttribute('aria-busy');
resetCompare();
resetFeedback();
outCount.textContent = fmt(countWords(state.result)) + ' words';
showResultActions(true);
feedbackEl.hidden = !(state.lastRun && data && data.mode && data.mode !== 'demo');
var missing = data && data.accuracy && Array.isArray(data.accuracy.missingProtectedTokens) ? data.accuracy.missingProtectedTokens : [];
if (data && data.mode === 'demo') {
setNotice('warn', 'Demo mode: the rewriting service isn’t configured on this server, so your text was returned unchanged.');
} else if (data && data.warning) {
setNotice('warn', data.warning);
} else if (missing.length) {
setNotice('warn', 'Please double-check these details in the rewrite: ' + missing.slice(0, 6).join(', '));
}
var diff = state.diff = wordDiff(state.original, state.result);
if (diffCountBadge) {
diffCountBadge.textContent = (diff.changedPhrases || diff.changed) + ' edits';
}
state.viewMode = 'clean';
updateViewPills('clean');
renderCurrentView();
if (data && data.mode === 'ai' && !data.warning && !missing.length && diff.total && diff.changed / diff.total < 0.03) {
setNotice('info', 'Your text already reads naturally, so very little needed changing. Choose Strong for a bigger rewrite.');
}
renderStats(data, diff);
setStatus('Done. Review it before you use it.');
}
function showError(message) {
output.removeAttribute('aria-busy');
output.textContent = '';
var box = document.createElement('div');
box.className = 'alert alert-error';
box.setAttribute('role', 'alert');
var p = document.createElement('p');
p.textContent = message;
var retry = document.createElement('button');
retry.type = 'button';
retry.className = 'btn btn-secondary btn-sm';
retry.textContent = 'Try again';
retry.addEventListener('click', run);
box.appendChild(p);
box.appendChild(retry);
output.appendChild(box);
setStatus('');
}
function errorMessage(status, data) {
var serverMsg = data && (typeof data.error === 'string' ? data.error : (data.error && data.error.message));
if (status === 429) return 'You’ve made several requests in a short time. Please wait about a minute and try again.';
if (status >= 500) return 'The rewriting service is having trouble right now. Please try again in a moment.';
return serverMsg || 'Something went wrong. Please try again.';
}
function run() {
var text = input.value.trim();
if (state.busy || !text || text.length > MAX_CHARS) return;
var opts = currentSettings();
save(SETTINGS_KEY, opts);
clearNotice();
state.lastRun = null;
resetFeedback();
statsEl.hidden = true;
showResultActions(false);
outCount.textContent = '';
state.controller = new AbortController();
state.timedOut = false;
setBusy(true);
output.setAttribute('aria-busy', 'true');
output.innerHTML = '<div class="skeleton" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span></div>';
setStatus('Rewriting your text…', true);
var started = Date.now();
var timers = [
setTimeout(function () { setStatus('Checking the rewrite…', true); }, 7000),
setTimeout(function () { setStatus('Taking longer than usual. Hang tight or cancel.', true); }, 18000),
setTimeout(function () { state.timedOut = true; if (state.controller) state.controller.abort(); }, 55000)
];
track('humanize_start', { tone: opts.tone, level: opts.level, purpose: opts.purpose });
fetch('/api/humanize.php', {
method: 'POST',
headers: { 'Content-Type': 'application/json' },
body: JSON.stringify({ text: text, tone: opts.tone, level: opts.level, purpose: opts.purpose }),
signal: state.controller.signal
})
.then(function (res) {
return res.text().then(function (raw) {
var data = {};
try { data = raw ? JSON.parse(raw) : {}; } catch (e) { data = {}; }
return { res: res, data: data };
});
})
.then(function (r) {
if (!r.res.ok || r.data.error) throw new Error(errorMessage(r.res.status, r.data));
if (typeof r.data.output !== 'string' || !r.data.output.trim()) throw new Error('The service returned an empty result. Please try again.');
state.original = text;
state.result = r.data.output.trim();
state.lastRun = { tone: opts.tone, level: opts.level, purpose: opts.purpose, mode: r.data.mode || 'ai' };
showResult(r.data);
addHistory(opts, text, state.result);
track('humanize_success', { mode: r.data.mode || 'ai', seconds: Math.round((Date.now() - started) / 1000) });
})
.catch(function (err) {
if (err && err.name === 'AbortError' && !state.timedOut) {
output.removeAttribute('aria-busy');
output.innerHTML = emptyMarkup;
setStatus('Cancelled.');
return;
}
if ((window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') && !state.timedOut) {
var demoOutput = 'Clear communication matters more than ever in a digital world. ' +
'Teams also need practical, modern tools to coordinate smoothly and stay on the same page. ' +
'When people adopt straightforward ways of working, they get better results with less friction. ' +
'Good writing also builds lasting credibility with customers.';
state.original = text;
state.result = demoOutput;
state.lastRun = { tone: opts.tone, level: opts.level, purpose: opts.purpose, mode: 'demo' };
showResult({ mode: 'demo', output: demoOutput });
return;
}
var msg = state.timedOut
? 'This is taking too long. Try a shorter passage or the Light setting, then try again.'
: (err instanceof TypeError ? 'We couldn’t reach the server. Check your connection and try again.' : err.message);
showError(msg);
track('humanize_error', { reason: state.timedOut ? 'timeout' : 'error' });
})
.then(function () {
timers.forEach(clearTimeout);
state.controller = null;
setBusy(false);
updateInput();
});
}
runBtn.addEventListener('click', run);
cancelBtn.addEventListener('click', function () { if (state.controller) state.controller.abort(); });
input.addEventListener('keydown', function (e) {
if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
e.preventDefault();
if (!runBtn.disabled) run();
}
});
if (/Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent)) {
var modKey = tool.querySelector('[data-mod-key]');
if (modKey) modKey.textContent = '⌘';
}
function copyText(text) {
if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
return new Promise(function (resolve, reject) {
var ta = document.createElement('textarea');
ta.value = text;
ta.setAttribute('readonly', '');
ta.style.position = 'fixed';
ta.style.opacity = '0';
document.body.appendChild(ta);
ta.select();
try { if (document.execCommand('copy')) resolve(); else reject(new Error('copy failed')); } catch (e) { reject(e); }
document.body.removeChild(ta);
});
}
copyBtn.addEventListener('click', function () {
copyText(state.result).then(function () {
toast('Copied to clipboard');
track('humanize_copy');
}, function () {
toast('Couldn’t copy automatically. Select the text and copy it manually.');
});
});
downloadBtn.addEventListener('click', function () {
var blob = new Blob([state.result], { type: 'text/plain;charset=utf-8' });
var url = URL.createObjectURL(blob);
var a = document.createElement('a');
a.href = url;
a.download = 'rewritten-text.txt';
document.body.appendChild(a);
a.click();
a.remove();
setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
});
if (modeCleanBtn) {
modeCleanBtn.addEventListener('click', function () { switchViewMode('clean'); });
}
if (modeDiffBtn) {
modeDiffBtn.addEventListener('click', function () { switchViewMode('diff'); });
}
if (modeSplitBtn) {
modeSplitBtn.addEventListener('click', function () { switchViewMode('split'); });
}
compareBtn.addEventListener('click', function () {
if (!state.result) return;
switchViewMode(state.viewMode === 'clean' ? 'diff' : 'clean');
});
if (!(navigator.clipboard && navigator.clipboard.readText)) pasteBtn.hidden = true;
pasteBtn.addEventListener('click', function () {
navigator.clipboard.readText().then(function (text) {
if (!text) { toast('Your clipboard is empty.'); return; }
input.value = text;
updateInput();
input.focus();
}, function () {
toast('Paste was blocked by your browser. Use Ctrl+V instead.');
});
});
sampleBtn.addEventListener('click', function () {
input.value = SAMPLE;
resetOutput();
clearNotice();
updateInput();
input.focus();
track('humanize_sample');
});
clearBtn.addEventListener('click', function () {
input.value = '';
resetOutput();
clearNotice();
updateInput();
input.focus();
});
function historyItems() {
var items = load(HISTORY_KEY, []);
return Array.isArray(items) ? items : [];
}
function updateHistoryBadge() {
var n = historyItems().length;
historyCount.textContent = n;
historyCount.hidden = n === 0;
}
function addHistory(opts, original, result) {
var items = historyItems();
items.unshift({ id: Date.now().toString(36), at: new Date().toISOString(), tone: opts.tone, level: opts.level, input: original, output: result });
save(HISTORY_KEY, items.slice(0, HISTORY_MAX));
updateHistoryBadge();
}
function timeAgo(iso) {
var mins = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
if (!isFinite(mins)) return '';
if (mins < 1) return 'just now';
if (mins < 60) return mins + ' min ago';
if (mins < 1440) return Math.round(mins / 60) + ' h ago';
return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}
function renderHistory() {
var items = historyItems();
historyList.textContent = '';
historyClear.disabled = items.length === 0;
if (!items.length) {
var empty = document.createElement('p');
empty.className = 'muted';
empty.textContent = 'No saved rewrites yet. Your last ' + HISTORY_MAX + ' results will appear here.';
historyList.appendChild(empty);
return;
}
items.forEach(function (item) {
var card = document.createElement('article');
card.className = 'history-item';
var meta = document.createElement('div');
meta.className = 'history-meta';
meta.innerHTML = '<span class="tag tag-brand"></span><span></span>';
meta.children[0].textContent = item.tone + ' · ' + item.level;
meta.children[1].textContent = timeAgo(item.at);
var preview = document.createElement('p');
preview.className = 'history-preview';
preview.textContent = item.output;
var actions = document.createElement('div');
actions.className = 'history-actions';
[['restore', 'Restore'], ['copy', 'Copy'], ['delete', 'Delete']].forEach(function (a) {
var b = document.createElement('button');
b.type = 'button';
b.className = 'btn btn-ghost btn-sm';
b.textContent = a[1];
b.setAttribute('data-history', a[0]);
b.setAttribute('data-id', item.id);
actions.appendChild(b);
});
card.appendChild(meta);
card.appendChild(preview);
card.appendChild(actions);
historyList.appendChild(card);
});
}
if (drawer && typeof drawer.showModal === 'function') {
historyBtn.addEventListener('click', function () { renderHistory(); drawer.showModal(); });
historyClose.addEventListener('click', function () { drawer.close(); });
drawer.addEventListener('keydown', function (e) {
if (e.key === 'Escape') { e.preventDefault(); drawer.close(); }
});
drawer.addEventListener('close', function () { historyBtn.focus(); });
drawer.addEventListener('click', function (e) {
if (e.target === drawer) { drawer.close(); return; }
var btn = e.target.closest('[data-history]');
if (!btn) return;
var id = btn.getAttribute('data-id');
var items = historyItems();
var item = items.filter(function (it) { return it.id === id; })[0];
var action = btn.getAttribute('data-history');
if (action === 'delete') {
save(HISTORY_KEY, items.filter(function (it) { return it.id !== id; }));
updateHistoryBadge();
renderHistory();
} else if (action === 'copy' && item) {
copyText(item.output).then(function () { toast('Copied to clipboard'); });
} else if (action === 'restore' && item) {
input.value = item.input;
state.original = item.input;
state.result = item.output;
state.lastRun = null;
clearNotice();
updateInput();
showResult({});
drawer.close();
tool.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
});
historyClear.addEventListener('click', function () {
save(HISTORY_KEY, []);
updateHistoryBadge();
renderHistory();
});
} else {
historyBtn.hidden = true;
}
try {
var handoff = sessionStorage.getItem(HANDOFF_KEY);
if (handoff) {
sessionStorage.removeItem(HANDOFF_KEY);
input.value = handoff;
setNotice('info', 'We brought over the text you checked in the AI Detector. Choose your settings, then click “Humanize text”.');
tool.scrollIntoView({ block: 'start' });
}
} catch (e) {  }
updateInput();
updateHistoryBadge();
})();
