// Run with: node tests/frontend_asset_freshness_test.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const read = file => fs.readFileSync(path.join(__dirname, '..', file), 'utf8');

async function checkServiceWorker() {
  const handlers = {};
  const requests = [];
  const entries = new Map();
  let offline = false;
  const key = request => typeof request === 'string' ? new URL(request, 'https://wallos.test/').href : request.url;
  const cache = {
    put: async (request, response) => entries.set(key(request), response),
    match: async (request, options = {}) => {
      for (const [url, response] of entries) {
        if (url === key(request) || (options.ignoreSearch && url.split('?')[0] === key(request).split('?')[0])) return response.clone();
      }
    },
  };
  const context = vm.createContext({
    URL,
    self: { location: { origin: 'https://wallos.test' }, addEventListener: (name, handler) => { handlers[name] = handler; }, skipWaiting() {} },
    caches: { open: async () => cache },
    fetch: async (request, options) => {
      requests.push({ url: key(request), options });
      if (offline) throw new Error('offline');
      return new Response('current deployed script');
    },
  });
  vm.runInContext(read('service-worker.js'), context);
  const dispatch = url => {
    let response;
    handlers.fetch({ request: new Request(url), respondWith: result => { response = result; } });
    return response;
  };

  for (const script of ['settings', 'subscriptions']) {
    const url = `https://wallos.test/scripts/${script}.js?new-content-hash`;
    entries.set(url, new Response('stale script'));
    const result = await dispatch(url);
    assert.equal(await result.text(), 'current deployed script', `${script} uses the current server response despite stale cache`);
    assert.equal(requests.at(-1).options.cache, 'no-cache', 'HTTP cache is revalidated');
    assert.equal(await entries.get(url).clone().text(), 'current deployed script', 'offline cache is refreshed');
    offline = true;
    assert.equal(await (await dispatch(url)).text(), 'current deployed script', 'offline fallback still works');
    offline = false;
  }

  requests.length = 0;
  let installation;
  handlers.install({ waitUntil: promise => { installation = promise; } });
  await installation;
  assert.ok(requests.length > 10, 'installation precaches assets');
  assert.ok(requests.every(request => request.options.cache === 'reload'), 'installation bypasses stale HTTP cache');
}

(async () => {
  await checkServiceWorker();
  console.log('Asset freshness, offline fallback, and precache refresh tests passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
