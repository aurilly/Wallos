// Run with: node tests/ai_recommendation_polling_test.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../scripts/settings.js'), 'utf8');
const polling = source.slice(source.indexOf('let aiRecommendationsBusy = false;'));

function harness(responses) {
  const elements = new Map();
  const calls = [];
  const messages = [];
  const context = vm.createContext({
    AbortController,
    window: { csrfToken: 'token' },
    document: {
      readyState: 'loading',
      addEventListener() {},
      querySelector(selector) {
        if (!elements.has(selector)) elements.set(selector, { checked: true, textContent: '', classList: { toggle() {} } });
        return elements.get(selector);
      },
    },
    translate: key => key,
    showSuccessMessage: message => messages.push(['success', message]),
    showErrorMessage: message => messages.push(['error', message]),
    setTimeout: (callback, delay) => { if (delay === 3000) queueMicrotask(callback); return 1; },
    clearTimeout() {},
    fetch: async (url, options) => {
      calls.push(url);
      assert.equal(options.method, 'POST');
      assert.equal(options.headers['X-CSRF-Token'], 'token');
      assert.equal(options.cache, 'no-store');
      assert.ok(responses.length, 'unexpected extra request');
      const response = responses.shift();
      if (response instanceof Error) throw response;
      return { ok: true, status: 200, json: async () => response };
    },
  });
  vm.runInContext(polling, context);
  return { context, calls, messages, elements };
}

const state = status => ({ success: true, status, message: status, job_id: 'job' });

(async () => {
  const normal = harness([state('queued'), state('running'), state('completed')]);
  await Promise.all([normal.context.runAiRecommendations(), normal.context.runAiRecommendations()]);
  assert.equal(normal.calls.filter(url => url.includes('generate_recommendations')).length, 1, 'double clicks enqueue once');
  assert.equal(normal.calls.length, 3, 'poll until completion');
  assert.deepEqual(normal.messages, [['success', 'completed']]);

  const resume = harness([state('running'), new Error('offline'), state('completed')]);
  await resume.context.resumeAiRecommendations();
  assert.ok(resume.calls.every(url => url.includes('recommendation_status')), 'resuming never starts another generation');
  assert.deepEqual(resume.messages, [['success', 'completed']], 'temporary failure is retried');

  const failure = harness([state('queued'), state('failed')]);
  await failure.context.runAiRecommendations();
  assert.deepEqual(failure.messages, [['error', 'failed']]);
  assert.equal(vm.runInContext('aiRecommendationsBusy', failure.context), false, 'failed jobs restore controls');

  const offline = harness([state('running'), ...Array.from({ length: 5 }, () => new Error('offline'))]);
  await offline.context.runAiRecommendations();
  assert.equal(offline.calls.length, 6, 'network retries are bounded');
  assert.deepEqual(offline.messages, [['error', 'offline']]);
  assert.equal(vm.runInContext('aiRecommendationsBusy', offline.context), false, 'network failure restores controls');

  const completed = harness([state('completed')]);
  await completed.context.resumeAiRecommendations();
  assert.equal(completed.elements.get('#aiJobStatus').textContent, 'completed', 'returning after completion shows the result');
  assert.deepEqual(completed.messages, [], 'page loads do not repeat old toasts');
  console.log('5 AI polling tests passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
