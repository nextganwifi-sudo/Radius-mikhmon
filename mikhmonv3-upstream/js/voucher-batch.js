(function () {
  'use strict';
  var config = window.ngwVoucherBatch;
  var form = document.getElementById('voucher-generate-form');
  if (!config || !form) return;
  var status = document.getElementById('voucher-batch-status');
  var progress = document.getElementById('voucher-batch-progress');
  var resume = document.getElementById('voucher-batch-resume');
  var key = 'ngw-voucher-batch:' + config.router;
  var job = config.job;
  var busy = false;
  var startData = null;
  var completed = 0;
  var total = 0;
  function remember(value) {
    try { if (value) sessionStorage.setItem(key, value); else sessionStorage.removeItem(key); } catch (e) {}
  }
  function lock(value) {
    Array.prototype.forEach.call(form.elements, function (element) {
      if (element !== resume) element.disabled = value;
    });
  }
  async function request(action) {
    var body = new FormData();
    if (action === 'start' && startData) startData.forEach(function (value, name) { body.append(name, value); });
    body.set('action', action); body.set('job', job); body.set('router', config.router); body.set('csrf', config.csrf);
    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, 35000);
    try {
      var response = await fetch('./process/voucher_batch.php', {method: 'POST', credentials: 'same-origin', body: body, signal: controller.signal});
      var data;
      try { data = await response.json(); } catch (e) { throw new Error('The server response was interrupted. Your batch is saved.'); }
      if (typeof data.completed === 'number') completed = data.completed;
      if (typeof data.total === 'number') total = data.total;
      progress.max = total || 1; progress.value = completed;
      if (!response.ok || data.error) {
        var error = new Error(data.error || 'The server could not complete this step.');
        error.retryable = data.retryable !== false;
        throw error;
      }
      window.dispatchEvent(new Event('ngw-voucher-progress'));
      return data;
    } finally { clearTimeout(timer); }
  }
  async function run(action) {
    if (busy) return;
    busy = true; lock(true); resume.style.display = 'none'; progress.style.display = 'block';
    remember(job);
    var retries = 0;
    try {
      while (true) {
        status.textContent = 'Generating vouchers: ' + completed + ' / ' + (total || '…') + '. Keep this page open.';
        var data;
        try { data = await request(action); retries = 0; }
        catch (error) {
          if (error.retryable === false || retries >= 2) throw error;
          retries++;
          status.textContent = 'Connection interrupted. Resuming the same batch (' + completed + ' confirmed)…';
          await new Promise(function (resolve) { setTimeout(resolve, 1500); });
          continue;
        }
        if (data.done) {
          remember(null);
          status.textContent = 'Successfully generated ' + data.total + ' vouchers. Opening Generate page…';
          window.location.replace(data.redirect);
          return;
        }
        action = 'step'; startData = null;
      }
    } catch (error) {
      status.textContent = completed + ' / ' + (total || '?') + ' confirmed. ' + error.message;
      if (error.retryable !== false) {
        resume.style.display = 'inline-block';
        resume.onclick = function () { run(startData ? 'start' : 'step'); };
      } else {
        remember(null);
        status.textContent += ' Check Hotspot Users, then reload this page before starting another batch.';
      }
    } finally { busy = false; }
  }
  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (busy || !form.reportValidity()) return;
    startData = new FormData(form);
    total = Number(startData.get('qty')); completed = 0;
    run('start');
  });
  try {
    var saved = sessionStorage.getItem(key);
    if (saved && /^[a-f0-9]{32}$/.test(saved)) { job = saved; run('status'); }
  } catch (e) {}
})();
