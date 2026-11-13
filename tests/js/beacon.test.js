const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const script = fs.readFileSync(`${__dirname}/../../public/beacon.js`, 'utf8');

function openPage(options = {}) {
  const requests = [];
  const listeners = {};
  const document = {
    visibilityState: 'visible',
    prerendering: false,
    referrer: 'https://google.com/',
    addEventListener(name, callback) { listeners[name] = callback; },
    ...options.document
  };
  const context = {
    document,
    location: { pathname: '/ceny/ram', search: '?utm_source=chatgpt.com', ...options.location },
    navigator: {
      sendBeacon(url, body) { requests.push({ url, body }); },
      ...options.navigator
    },
    window: options.window || {},
    URLSearchParams,
    Blob,
    fetch(url, request) { requests.push({ url, ...request }); }
  };
  vm.runInNewContext(script, context);
  return { requests, document, listeners };
}

(async function () {
  for (const options of [
    { navigator: { webdriver: true } },
    { navigator: { doNotTrack: '1' } },
    { navigator: { globalPrivacyControl: true } },
    { window: { doNotTrack: '1' } },
    { location: { pathname: '/admin' } }
  ]) {
    assert.equal(openPage(options).requests.length, 0);
  }

  const visible = openPage();
  visible.listeners.visibilitychange();
  assert.equal(visible.requests.length, 1);
  assert.deepEqual(JSON.parse(await visible.requests[0].body.text()), {
    p: '/ceny/ram', r: 'https://google.com/', s: 'chatgpt.com'
  });

  const hidden = openPage({ document: { visibilityState: 'hidden' } });
  assert.equal(hidden.requests.length, 0);
  hidden.document.visibilityState = 'visible';
  hidden.listeners.visibilitychange();
  hidden.listeners.visibilitychange();
  assert.equal(hidden.requests.length, 1);

  const prerendered = openPage({ document: { prerendering: true } });
  assert.equal(prerendered.requests.length, 0);
  prerendered.document.prerendering = false;
  prerendered.listeners.prerenderingchange();
  assert.equal(prerendered.requests.length, 1);

  const fallback = openPage({ navigator: { sendBeacon: undefined } });
  assert.equal(fallback.requests.length, 1);
  assert.equal(fallback.requests[0].method, 'POST');
  assert.equal(fallback.requests[0].url, '/api/pa/hit');

  const customEndpoint = openPage({
    document: { currentScript: { getAttribute() { return '/analytics/hit'; } } }
  });
  assert.equal(customEndpoint.requests[0].url, '/analytics/hit');

  const direct = openPage({
    document: { referrer: '' },
    location: { search: '' },
    navigator: { webdriver: false }
  });
  assert.deepEqual(JSON.parse(await direct.requests[0].body.text()), {
    p: '/ceny/ram', r: null, s: null
  });
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
