import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source = fs.readFileSync(new URL('../mikhmonv3-upstream/js/voucher-batch.js', import.meta.url), 'utf8');
class Data {
  constructor(form) { this.data = new Map(form ? Object.entries(form.fields) : []); }
  append(k,v) { this.data.set(k,v); }
  set(k,v) { this.data.set(k,v); }
  get(k) { return this.data.get(k); }
  forEach(fn) { this.data.forEach(fn); }
}
async function scenario(saved, loseStart = false) {
  const calls = [], events = [], storage = new Map();
  if (saved) storage.set('ngw-voucher-batch:test', saved);
  const elements = Object.fromEntries(['voucher-batch-status','voucher-batch-progress','voucher-batch-resume'].map(id => [id, {style:{}}]));
  const form = {fields:{qty:'200'}, elements:[{}], reportValidity:()=>true, addEventListener:(name,fn)=>{ form[name]=fn; }};
  elements['voucher-generate-form'] = form;
  let redirected, progress = 0;
  const win = {ngwVoucherBatch:{router:'test', csrf:'token', job:'a'.repeat(32)}, dispatchEvent:e=>events.push(e.type), location:{replace:url=>{redirected=url;}}};
  vm.runInNewContext(source, {window:win, document:{getElementById:id=>elements[id]},
    FormData:Data, AbortController, Event, Number, Promise,
    setTimeout:(fn,ms)=>ms === 35000 ? 0 : setTimeout(fn,0), clearTimeout,
    sessionStorage:{setItem:(k,v)=>storage.set(k,v), getItem:k=>storage.get(k), removeItem:k=>storage.delete(k)},
    fetch:async (url, options)=>{
      const action=options.body.get('action');
      calls.push({action, job:options.body.get('job')});
      if (loseStart && calls.length === 1) throw new Error('response lost');
      if (action === 'step') progress = Math.min(200, progress+3);
      return {ok:true, json:async()=>({completed:progress, total:200, done:progress===200, redirect:'./?hotspot-user=generate&session=test&voucher-result=1'})};
    }});
  if (!saved) {
    let prevented = 0;
    form.submit({preventDefault:()=>prevented++});
    form.submit({preventDefault:()=>prevented++});
    assert.equal(prevented,2);
  }
  for (let i=0; i<200 && !redirected; i++) await new Promise(resolve=>setTimeout(resolve,1));
  assert.ok(redirected?.includes('voucher-result=1'), 'Completion must automatically return to Generate');
  assert.equal(calls.filter(x=>x.action==='step').length,67);
  assert.equal(calls.filter(x=>x.action==='start').length,saved ? 0 : loseStart ? 2 : 1);
  assert.equal(new Set(calls.map(x=>x.job)).size,1,'Retries must keep the same batch ID');
  assert.equal(storage.size,0,'Completed batch must be removed from browser resume state');
  assert.ok(events.includes('ngw-voucher-progress'),'Active generation must refresh idle activity');
}
await scenario(null);
await scenario(null,true);
await scenario('b'.repeat(32));
console.log('PASS: client 200-voucher progress, duplicate-click protection, lost-response retry, reload resume and automatic redirect');
