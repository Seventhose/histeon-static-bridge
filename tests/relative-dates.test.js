'use strict';

// Unit tests for plugin/assets/relative-dates.js with a minimal DOM: spans with data-sb-since and data-sb-to, the
// language of <html lang>, a fixed Date.now() and the real Intl of Node (or none). A real builder page is covered by
// the e2e gate.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../plugin/assets/relative-dates.js'), 'utf8');
const NOW = 1800000000;
const MINUTE = 60;
const HOUR = 3600;
const DAY = 86400;

class FakeSpan {
    constructor(attributes, text) {
        this.attributes = attributes;
        this.textContent = text;
    }
    getAttribute(name) {
        return Object.prototype.hasOwnProperty.call(this.attributes, name) ? String(this.attributes[name]) : null;
    }
}

// Each span: { since, to, text }; to defaults to since + the difference the export saw.
function run(spans, options = {}) {
    const elements = spans.map((span) => new FakeSpan({ 'data-sb-since': span.since, 'data-sb-to': span.to }, span.text));
    const html = { getAttribute: (name) => (name === 'lang' ? (options.lang === undefined ? 'it-IT' : options.lang) : null) };
    const document = {
        documentElement: html,
        querySelectorAll: (selector) => (selector === '[data-sb-since][data-sb-to]' ? elements : []),
    };
    const window = { Intl: options.noIntl ? undefined : (options.intl || Intl) };
    const context = { window, document, Date: { now: () => (options.now === undefined ? NOW : options.now) * 1000 }, Math, parseInt };
    vm.runInNewContext(source, context);
    return { elements, state: window.staticBridgeRelativeDates };
}

const tests = [];
const test = (name, fn) => tests.push({ name, fn });

test('the steps are those of human_time_diff() at the boundaries', () => {
    const { state } = run([]);
    const cases = [
        [0, 'second', 1], [1, 'second', 1], [59, 'second', 59],
        [60, 'minute', 1], [89, 'minute', 1], [90, 'minute', 2], [59 * MINUTE + 59, 'minute', 60],
        [89 * MINUTE, 'hour', 1], [90 * MINUTE, 'hour', 2], [24 * HOUR - 1, 'hour', 24], [24 * HOUR, 'day', 1],
        [36 * HOUR, 'day', 2], [7 * DAY - 1, 'day', 7], [7 * DAY, 'week', 1], [30 * DAY - 1, 'week', 4],
        [30 * DAY, 'month', 1], [365 * DAY - 1, 'month', 12], [365 * DAY, 'year', 1], [3 * 365 * DAY, 'year', 3],
    ];
    // WordPress: < MINUTE seconds (min 1), < HOUR round(minutes), < DAY round(hours), < WEEK round(days),
    // < MONTH (30 days) round(weeks), < YEAR (365 days) round(months), then round(years); every count at least 1.
    const wp = (diff) => {
        if (diff < MINUTE) return ['second', Math.max(1, diff)];
        if (diff < HOUR) return ['minute', Math.max(1, Math.round(diff / MINUTE))];
        if (diff < DAY) return ['hour', Math.max(1, Math.round(diff / HOUR))];
        if (diff < 7 * DAY) return ['day', Math.max(1, Math.round(diff / DAY))];
        if (diff < 30 * DAY) return ['week', Math.max(1, Math.round(diff / (7 * DAY)))];
        if (diff < 365 * DAY) return ['month', Math.max(1, Math.round(diff / (30 * DAY)))];
        return ['year', Math.max(1, Math.round(diff / (365 * DAY)))];
    };
    for (const [diff, unit, value] of cases) {
        const step = state.step(diff);
        assert.deepEqual([step.unit, step.value], wp(diff), 'the steps differ from human_time_diff() at ' + diff + ' s');
        assert.deepEqual([step.unit, step.value], [unit, value], 'wrong step at ' + diff + ' s');
    }
});

test('a date that moved to another step is rewritten in the language of <html lang>', () => {
    const exported = NOW - 7 * DAY;
    const spans = [
        { since: exported - 3 * HOUR, to: exported, text: '3 ore' },
        { since: exported - 50, to: exported, text: '50 secondi' },
    ];
    const it = run(spans, { lang: 'it-IT' });
    assert.equal(it.elements[0].textContent, '1 settimana');
    assert.equal(it.elements[1].textContent, '1 settimana');
    assert.equal(it.state.updated, 2);
    const en = run(spans, { lang: 'en' });
    assert.equal(en.elements[0].textContent, '1 week');
    const later = run([{ since: NOW - 89 * MINUTE, to: NOW - 89 * MINUTE + 5 * MINUTE, text: '5 mins' }], { lang: 'en-US' });
    assert.equal(later.elements[0].textContent, '1 hour');
});

test('the text stays when the step and the number are those of the export', () => {
    // Exported 10 minutes after the date, read 10 minutes and 20 seconds after it: still «10 minutes».
    const since = NOW - 10 * MINUTE - 20;
    const { elements, state } = run([{ since, to: since + 10 * MINUTE, text: '10 min' }], { lang: 'en' });
    assert.equal(elements[0].textContent, '10 min', 'a page just exported lost the WordPress text');
    assert.equal(state.updated, 0);
});

test('a future date counts down, and a date whose side flipped is left alone', () => {
    const since = NOW + 2 * DAY;
    const ahead = run([{ since, to: since - 6 * DAY, text: '6 giorni' }]);
    assert.equal(ahead.elements[0].textContent, '2 giorni');
    const passed = run([{ since: NOW - HOUR, to: NOW - 2 * DAY, text: '1 giorno' }]);
    assert.equal(passed.elements[0].textContent, '1 giorno', 'a future date now in the past changed under a fixed «tra»');
});

test('without Intl, a language, a supported unit or valid numbers the text stays', () => {
    const span = { since: NOW - 30 * DAY, to: NOW - 29 * DAY, text: '1 giorno' };
    assert.equal(run([span], { noIntl: true }).elements[0].textContent, '1 giorno');
    assert.equal(run([span], { lang: '' }).elements[0].textContent, '1 giorno');
    const noUnit = { NumberFormat: function NumberFormat() { throw new RangeError('Invalid unit argument'); } };
    assert.equal(run([span], { intl: noUnit }).elements[0].textContent, '1 giorno');
    const decimalOnly = {
        NumberFormat: function NumberFormat() {
            return { resolvedOptions: () => ({ style: 'decimal' }), format: (n) => String(n) };
        },
    };
    assert.equal(run([span], { intl: decimalOnly }).elements[0].textContent, '1 giorno', 'an engine without unit style wrote a bare number');
    assert.equal(run([span], { lang: 'not a language tag!' }).elements[0].textContent, '1 giorno');
    for (const bad of [{ since: 'abc', to: NOW }, { since: NOW - DAY, to: '' }, { since: '-5', to: NOW }, { since: '1234567890123', to: NOW }]) {
        assert.equal(run([{ ...bad, text: 'x' }]).elements[0].textContent, 'x');
    }
});

(async () => {
    for (const { name, fn } of tests) {
        try {
            await fn();
        } catch (error) {
            console.error('FAIL ' + name + ': ' + error.message);
            process.exit(1);
        }
    }
    console.log(tests.length + ' relative date tests passed.');
})();
