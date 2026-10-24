// walk-4p.js - Patch 4p keyboard-only walk in a headless browser (Edge on the Edutek PC; Chrome in development).
// Drives a THROWAWAY importer with key presses only (no mouse) on a copy of "Import Big Test":
// scan -> Found (left-out list) -> list (damaged video cannot be ticked; books need a shelf; Next blocked)
// -> shelf picker (M, typed filter of existing shelves) -> new shelf (C) -> loose videos to a category -> check
// -> Publish -> new-category confirm (No is focused; Esc = nothing requested; Y) -> simulated publisher (throwaway data only)
// -> Done summary (tiles, category links on <page host>:8080, Open PDF per book, left-out list, N focused, U asks with Keep focused).
// usage: node walk-4p.js --browser <exe> --base <url> --data <throwaway data dir> --out <screenshot dir> --src <windows folder> --cat <existing category> --shelf <existing Books shelf>
'use strict';
const http=require('http'),crypto=require('crypto'),fs=require('fs'),path=require('path'),os=require('os'),cp=require('child_process');
const A={};for(let i=2;i<process.argv.length;i+=2)A[process.argv[i].replace(/^--/,'')]=process.argv[i+1];
for(const k of['browser','base','data','out','src','cat','shelf'])if(!A[k]){console.error('missing --'+k);process.exit(2);}
const BASE=A.base.replace(/\/$/,'')+'/bulk-import-guided.php';
let pass=0,fail=0;const errs=[];
function ok(c,what,got){if(c){pass++;console.log('ok   '+what);}else{fail++;console.log('FAIL '+what+(got!==undefined?'  got: '+String(got).slice(0,300):''));}}
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
// minimal WebSocket client (RFC 6455, text frames) - Node 20 has no built-in WebSocket
function wsConnect(url){return new Promise((res,rej)=>{const u=new URL(url);const key=crypto.randomBytes(16).toString('base64');
 const rq=http.request({host:u.hostname,port:u.port,path:u.pathname+u.search,headers:{Connection:'Upgrade',Upgrade:'websocket','Sec-WebSocket-Version':'13','Sec-WebSocket-Key':key}});
 rq.on('upgrade',(resp,sock)=>{sock.setNoDelay(true);let buf=Buffer.alloc(0),parts=[];const L={msg:null,close:null};
  sock.on('data',d=>{buf=Buffer.concat([buf,d]);for(;;){if(buf.length<2)break;const fin=buf[0]&0x80,op=buf[0]&15;let len=buf[1]&127,o=2;
    if(len===126){if(buf.length<4)break;len=buf.readUInt16BE(2);o=4;}else if(len===127){if(buf.length<10)break;len=Number(buf.readBigUInt64BE(2));o=10;}
    const masked=buf[1]&0x80;if(masked)o+=4;if(buf.length<o+len)break;const pl=buf.subarray(o,o+len);buf=buf.subarray(o+len);
    if(op===9){send(pl,10);continue;}if(op===8){sock.end();continue;}if(op===1||op===2||op===0){parts.push(Buffer.from(pl));if(fin){const m=Buffer.concat(parts).toString('utf8');parts=[];L.msg&&L.msg(m);}}}});
  sock.on('close',()=>L.close&&L.close());sock.on('error',()=>{}); // Edge closing its end at shutdown (ECONNRESET) is not a test failure
  function send(data,op=1){const p=Buffer.isBuffer(data)?data:Buffer.from(data,'utf8');const mask=crypto.randomBytes(4);let h;
   if(p.length<126){h=Buffer.alloc(2);h[1]=0x80|p.length;}else if(p.length<65536){h=Buffer.alloc(4);h[1]=0x80|126;h.writeUInt16BE(p.length,2);}else{h=Buffer.alloc(10);h[1]=0x80|127;h.writeBigUInt64BE(BigInt(p.length),2);}
   h[0]=0x80|op;const m=Buffer.alloc(p.length);for(let i=0;i<p.length;i++)m[i]=p[i]^mask[i&3];sock.write(Buffer.concat([h,mask,m]));}
  res({send,on:(ev,f)=>{L[ev]=f;},close:()=>{try{send(Buffer.alloc(0),8);}catch(e){}sock.end();}});});
 rq.on('response',r=>rej(new Error('websocket upgrade refused: HTTP '+r.statusCode)));rq.on('error',rej);rq.end();});}
function getJson(url){return new Promise((res,rej)=>{http.get(url,r=>{let b='';r.on('data',d=>b+=d);r.on('end',()=>{try{res(JSON.parse(b));}catch(e){rej(e);}});}).on('error',rej);});}
function cdpOf(ws){let n=0;const pend=new Map(),subs={};ws.on('msg',m=>{const j=JSON.parse(m);if(j.id&&pend.has(j.id)){const[a,b]=pend.get(j.id);pend.delete(j.id);j.error?b(new Error(j.error.message)):a(j.result);}else if(j.method&&subs[j.method])subs[j.method].forEach(f=>f(j.params));});
 return {send:(method,params={})=>new Promise((a,b)=>{const id=++n;pend.set(id,[a,b]);ws.send(JSON.stringify({id,method,params}));}),on:(m,f)=>{(subs[m]=subs[m]||[]).push(f);},close:()=>ws.close()};}
const KEYS={Enter:[13,'\r'],Escape:[27,''],Tab:[9,''],ArrowDown:[40,''],ArrowUp:[38,''],ArrowLeft:[37,''],ArrowRight:[39,''],Home:[36,''],End:[35,''],Backspace:[8,''],' ':[32,' ']};
let C;
async function press(k,mods=0){if(KEYS[k]){const[vk,t]=KEYS[k];const code=k===' '?'Space':k;
  await C.send('Input.dispatchKeyEvent',{type:t?'keyDown':'rawKeyDown',key:k,code,windowsVirtualKeyCode:vk,nativeVirtualKeyCode:vk,text:t||undefined,unmodifiedText:t||undefined,modifiers:mods});
  await C.send('Input.dispatchKeyEvent',{type:'keyUp',key:k,code,windowsVirtualKeyCode:vk,nativeVirtualKeyCode:vk,modifiers:mods});}
 else{const vk=/[a-z]/i.test(k)?k.toUpperCase().charCodeAt(0):(/[0-9]/.test(k)?k.charCodeAt(0):0);
  await C.send('Input.dispatchKeyEvent',{type:'keyDown',key:k,text:k,unmodifiedText:k,windowsVirtualKeyCode:vk,nativeVirtualKeyCode:vk});
  await C.send('Input.dispatchKeyEvent',{type:'keyUp',key:k,windowsVirtualKeyCode:vk,nativeVirtualKeyCode:vk});}
 await sleep(160);}
async function keys(...ks){for(const k of ks)await press(k);}
async function type(s){for(const ch of s)await press(ch);}
async function ev(expr){const r=await C.send('Runtime.evaluate',{expression:expr,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw new Error('evaluate failed: '+expr.slice(0,80)+' '+JSON.stringify(r.exceptionDetails).slice(0,300));return r.result.value;}
async function waitFor(expr,ms=15000,what){const t0=Date.now();for(;;){let v;try{v=await ev(expr);}catch(e){v=false;}if(v)return v;if(Date.now()-t0>ms){if(what)ok(false,what,'timed out after '+ms+' ms');return false;}await sleep(250);}}
async function loaded(){await sleep(300);await waitFor("document.readyState==='complete'&&!!document.body",30000);await sleep(400);}
async function nav(u){await C.send('Page.navigate',{url:u});await loaded();}
let shotN=0;async function shot(name){const r=await C.send('Page.captureScreenshot',{format:'png'});shotN++;const f=path.join(A.out,String(shotN).padStart(2,'0')+'-'+name+'.png');fs.writeFileSync(f,Buffer.from(r.data,'base64'),{flag:'wx'});}
const focus="(()=>{const a=document.activeElement;return a?(a.id||a.dataset.k||a.tagName):''})()";
const h2="(document.querySelector('#ui4o h2')?.innerText||'')";
const ui="(document.querySelector('#ui4o')?.innerText||'')";
const dlg="(document.querySelector('.dialog4o')?.innerText||'')";
async function rowIndex(){return ev("[...document.querySelectorAll('li.item4o')].indexOf(document.activeElement.closest?document.activeElement.closest('li.item4o'):null)");}
async function goRow(i){let c=await rowIndex();if(c<0){await ev("document.querySelector('li.item4o[tabindex=\"0\"]')?.focus()");c=await rowIndex();}
 while(c<i){await press('ArrowDown');c++;}while(c>i){await press('ArrowUp');c--;}return (await rowIndex())===i;}
const rows="[...document.querySelectorAll('li.item4o')].map(l=>({t:l.innerText.replace(/\\s+/g,' ').trim(),needs:l.classList.contains('needs'),skip:l.classList.contains('skipped'),sel:l.getAttribute('aria-selected')==='true'}))";
function rmIf(f){try{fs.unlinkSync(f);}catch(e){}}
function putNew(f,obj){rmIf(f);fs.writeFileSync(f,JSON.stringify(obj),{flag:'wx'});}

const bookRows="[...document.querySelectorAll('li.item4o')].map((l,i)=>({i,t:l.innerText.replace(/\\s+/g,' ').trim()})).filter(x=>/\\bbook\\b/.test(x.t))";
const NEWSHELF='Walk Shelf '+Date.now().toString(36).slice(-4);
(async()=>{
 fs.mkdirSync(A.out,{recursive:true});const prof=fs.mkdtempSync(path.join(os.tmpdir(),'p4p-browser-'));
 const br=cp.spawn(A.browser,['--headless=new','--remote-debugging-port=0','--user-data-dir='+prof,'--no-first-run','--no-default-browser-check','--disable-extensions','--disable-sync','--window-size=1366,900','--lang=en-US','about:blank'].concat(process.platform==='linux'?['--no-sandbox']:[]),{stdio:'ignore'});
 let port=0;for(let i=0;i<120&&!port;i++){await sleep(250);try{port=parseInt(fs.readFileSync(path.join(prof,'DevToolsActivePort'),'utf8').split('\n')[0],10)||0;}catch(e){}}
 if(!port)throw new Error('the browser did not start (no DevToolsActivePort)');
 const ver=await getJson(`http://127.0.0.1:${port}/json/version`);const B=cdpOf(await wsConnect(ver.webSocketDebuggerUrl));
 const tl=(await getJson(`http://127.0.0.1:${port}/json/list`)).find(t=>t.type==='page');C=cdpOf(await wsConnect(tl.webSocketDebuggerUrl));
 C.on('Runtime.exceptionThrown',p=>errs.push('JS error: '+(p.exceptionDetails.exception?.description||p.exceptionDetails.text).slice(0,200)));
 C.on('Runtime.consoleAPICalled',p=>{if(p.type==='error')errs.push('console.error: '+p.args.map(a=>a.value||a.description).join(' ').slice(0,200));});
 C.on('Page.javascriptDialogOpening',p=>{errs.push('browser dialog: '+p.message.slice(0,120));C.send('Page.handleJavaScriptDialog',{accept:false}).catch(()=>{});});
 await C.send('Runtime.enable');await C.send('Page.enable');
 await C.send('Emulation.setDeviceMetricsOverride',{width:1366,height:900,deviceScaleFactor:1,mobile:false});
 try{
  console.log('Keyboard-only walk (4p): '+BASE);
  /* 1. scan the Big Test copy */
  await nav(BASE+'?srcpath='+encodeURIComponent(A.src)+'#start');
  ok((await ev(ui)).includes(path.win32.basename(A.src)),'4p walk: chosen folder is shown');
  await press('Enter');await waitFor("/[?&]id=[a-f0-9]{32}/.test(location.href)",180000,'4p walk: Enter scans the folder');await loaded();
  const ID=(await ev("location.href")).match(/id=([a-f0-9]{32})/)[1];console.log('import '+ID);
  ok(/^Found/.test(await ev(h2)),'4p walk: "Found ..." screen',await ev(h2));
  const fu=await ev(ui);await ev("document.querySelector('.left4p')?.scrollIntoView({block:'center'})");await shot('found-left-out');
  ok(/Left out of this import \(\d+\)/.test(fu),'4p walk: Found screen lists what is left out',fu.slice(0,400));
  ok(/Damaged Download Test\.mp4 \u2014 Can\u2019t be played \u2014 file is damaged/.test(fu),'4p walk: the damaged video is in the left-out list with its reason');
  ok(/\.mov \u2014 Not imported: only \.mp4/.test(fu)&&/\.mkv \u2014 Not imported: only \.mp4/.test(fu),'4p walk: .mov and .mkv are listed as left out with the reason');
  ok(/4i conversion/.test(fu),'4p walk: the left-out list mentions the 4i conversion for .mov/.mkv');
  ok(/notes\.txt \u2014 Not imported/.test(fu),'4p walk: other unsupported files are listed too (not silently skipped)');
  ok(/never put on a shelf by folder name/.test(fu),'4p walk: Found screen says books get the shelf you choose');
  ok(/loose audio file.*Music \u203A /.test(fu),'4p walk: Found screen says where loose audio goes (Music \u203A <import folder>)');
  /* 2. list */
  await press('n');await waitFor("document.querySelectorAll('li.item4o').length>0",10000,'4p walk: N opens the item list');await sleep(400);
  let R=await ev(rows);const dv=R.findIndex(r=>/Damaged Download Test/.test(r.t));
  ok(dv>=0&&R[dv].skip&&/file is damaged/.test(R[dv].t),'4p walk: the damaged video is shown as damaged and left out',dv>=0?R[dv].t.slice(0,160):'not listed');
  if(dv>=0){await goRow(dv);await press('x');await sleep(300);const tt=await ev("document.querySelector('.toast4o')?.innerText||''");ok(/damaged/.test(tt)&&(await ev(rows))[dv].skip,'4p walk: X cannot tick the damaged video',tt);await shot('damaged-video-x');}
  let BR=await ev(bookRows);ok(BR.length>=6&&BR.every(b=>/Needs a shelf/.test(b.t)),'4p walk: every book starts with no shelf ("Needs a shelf"), none filed by folder ('+BR.length+')',BR.map(b=>b.t.slice(0,70)).join(' | '));
  await press('n');await sleep(500);ok((await ev("document.body.innerText")).includes('still need')&&await ev("document.querySelectorAll('li.item4o').length>0"),'4p walk: Next is blocked while a book has no shelf');await shot('next-blocked-shelf');
  /* 3. shelf picker: M on two books, typed filter of existing shelves */
  await goRow(BR[0].i);await press(' ');await goRow(BR[1].i);await press(' ');
  await press('m');await sleep(400);ok(await ev("!!document.querySelector('.dialog4o [data-k=shelf-input]')"),'4p walk: M on books opens the shelf picker');
  ok(await ev("document.activeElement===document.querySelector('.dialog4o [data-k=shelf-input]')"),'4p walk: the typed filter has focus');
  const n0=await ev("document.querySelectorAll('.dialog4o [role=option]').length");
  await type(A.shelf.slice(0,Math.min(5,A.shelf.length)));await sleep(300);const n1=await ev("document.querySelectorAll('.dialog4o [role=option]').length");
  ok(n1<n0&&(await ev(dlg)).includes(A.shelf),'4p walk: typing filters the existing shelves ('+n0+' -> '+n1+')');
  await type(A.shelf.slice(Math.min(5,A.shelf.length)));await sleep(300);await shot('shelf-picker');
  await press('Enter');await sleep(800);
  BR=await ev(bookRows);ok(BR[0].t.includes('Books \u203A '+A.shelf)&&BR[1].t.includes('Books \u203A '+A.shelf),'4p walk: Enter puts the selected books on '+A.shelf,BR.slice(0,2).map(b=>b.t.slice(0,90)).join(' | '));
  ok(!(await ev("!!document.querySelector('.dialog4o')")),'4p walk: the picker closes');
  /* 4. C: a new shelf for the next books (bulk select) */
  const rest=BR.filter(b=>/Needs a shelf/.test(b.t));const keep=rest.pop();
  for(const b of rest){await goRow(b.i);await press(' ');}
  await press('c');await sleep(400);ok(await ev("!!document.querySelector('.dialog4o [data-k=shelf-input]')"),'4p walk: C on selected books opens the shelf picker for a new shelf');
  await type(NEWSHELF);await sleep(400);await shot('new-shelf');await press('Enter');await sleep(1200);
  if(await ev("!!document.querySelector('.dialog4o [data-k=yes]')")){console.log('  new-shelf question: '+(await ev(dlg)).replace(/\s+/g,' ').slice(0,160));await press('y');await sleep(900);}
  BR=await ev(bookRows);ok(rest.every(r=>BR.find(b=>b.i===r.i).t.includes(NEWSHELF+' (new shelf)')),'4p walk: C made the new shelf "'+NEWSHELF+'" for '+rest.length+' books',BR.map(b=>b.t.slice(0,80)).join(' | '));
  /* 5. skip the last book (X) */
  await goRow(keep.i);await press('x');await sleep(300);ok((await ev(rows))[keep.i].skip,'4p walk: X skips a book instead of choosing a shelf');
  /* 6. loose videos to a category */
  R=await ev(rows);const needIdx=R.map((r,i)=>r.needs&&!r.skip?i:-1).filter(i=>i>=0);
  for(const i of needIdx){await goRow(i);await press(' ');}
  if(needIdx.length){await press('m');await sleep(300);await type(A.cat);await sleep(300);await press('Enter');await sleep(800);}
  R=await ev(rows);ok(R.every(r=>!r.needs||r.skip),'4p walk: every included item has a category or shelf',R.filter(r=>r.needs&&!r.skip).map(r=>r.t.slice(0,60)).join(' | '));
  /* 7. check -> Publish -> new-category confirm */
  {const tN=Date.now();let okN=false;for(;Date.now()-tN<240000;){await press('n');for(let k=0;k<20;k++){if(await ev("!!document.querySelector('#publish4o')")){okN=true;break;}await sleep(500);}if(okN)break;const why=await ev("(document.querySelector('[role=alert],.toast4o')||{}).textContent||''");console.log('     (N not yet: '+String(why).replace(/\s+/g,' ').slice(0,120)+')');await sleep(5000);}ok(okN,'4p walk: N goes to the check screen (once cover images are ready)');}await loaded();
  const ck=await ev(ui);ok(ck.includes(A.shelf)&&ck.includes(NEWSHELF),'4p walk: check screen lists the shelves',ck.slice(0,300));
  ok(/other files? left out/i.test(ck),'4p walk: check screen says how many other files are left out (the list is on the first and Done screens)',ck.slice(0,400));
  let t=0;for(;t<8&&(await ev(focus))!=='publish4o';t++)await press('Tab');ok((await ev(focus))==='publish4o','4p walk: Tab reaches Publish ('+t+' Tab)');
  await press('Enter');await sleep(700);
  const cf=await ev(dlg);ok(/New Books shelves/.test(cf)&&cf.includes(NEWSHELF),'4p walk: Publish first lists the new categories and shelves',cf.replace(/\s+/g,' ').slice(0,300));
  ok(await ev("document.activeElement===document.querySelector('.dialog4o [data-k=no]')"),'4p walk: "No, go back and check" has the focus (safe choice)',await ev(focus));
  await shot('new-category-confirm');
  const q=path.join(A.data,'publish-queue',ID+'.request.json');
  await press('Escape');await sleep(700);ok(!(await ev("!!document.querySelector('.dialog4o')"))&&!fs.existsSync(q),'4p walk: Esc = No: nothing is published or requested');
  await press('Enter');await sleep(300);ok(true,'4p walk: (Enter repeats Publish from the button that kept focus)');
  if(!(await ev("!!document.querySelector('.dialog4o [data-k=yes]')"))){t=0;for(;t<8&&(await ev(focus))!=='publish4o';t++)await press('Tab');await press('Enter');await sleep(600);}
  ok(await ev("!!document.querySelector('.dialog4o [data-k=yes]')"),'4p walk: the confirm comes back on Publish');
  await press('y');
  const t0=Date.now();let seen=false;for(;Date.now()-t0<900000;){if(fs.existsSync(q)){seen=true;break;}await sleep(2000);}
  ok(seen,'4p walk: Y -> videos converted and the publish request queued ('+Math.round((Date.now()-t0)/1000)+' s)');
  /* 8. simulated publisher (throwaway data only) -> Done */
  const PS=path.join(A.data,'publish-status');fs.mkdirSync(PS,{recursive:true});rmIf(q);
  const nowIso=new Date().toISOString();
  putNew(path.join(PS,ID+'.json'),{batch:ID,state:'published',finished:nowIso.slice(0,19)+'Z',learning:R.filter(r=>!r.skip).length,replaced_learning:0,message:'Published (walk simulation)'});
  await nav(BASE+'?id='+ID);await sleep(1500);await shot('done-top');
  const dn=await ev(ui);ok(/Done/.test(await ev(h2)),'4p walk: Done screen',await ev(h2));
  ok(await ev("document.activeElement&&document.activeElement.dataset.k==='again'"),'4p walk: "Start a new import" has the focus',await ev(focus));
  ok(/Took|took|time/i.test(dn)&&/videos/.test(dn)&&/PDF books/.test(dn),'4p walk: totals and time taken are shown',dn.slice(0,300));
  const hrefs=await ev("[...document.querySelectorAll('a.link4p')].map(a=>a.href)");const host=await ev("location.hostname");
  ok(hrefs.length>0&&hrefs.every(h=>h.startsWith(location_base(host))),'4p walk: every link uses this page\u2019s host with port 8080 ('+hrefs.length+' links)',hrefs.slice(0,3).join(' '));
  ok(await ev("[...document.querySelectorAll('a.link4p')].some(a=>/Open (section|category)/.test(a.innerText))"),'4p walk: category links are shown');
  ok(!/\b(P|L)\b.*(phone|laptop) link/i.test(dn),'4p walk: no L/P link toggle');
  await press('c');await sleep(300);ok(await ev("document.activeElement?.dataset.k==='h-cats'"),'4p walk: C jumps to the category links');await shot('done-links');
  await press('b');await sleep(300);const pdfs=await ev("[...document.querySelectorAll('.docs4p a')].map(a=>a.href)");
  ok(pdfs.length===BR.filter(b=>b.i!==keep.i).length&&pdfs.every(h=>/readpdf\.php\?id=content\/Books\//.test(h)),'4p walk: B jumps to the books: one Open PDF link per book ('+pdfs.length+')',pdfs.slice(0,2).join(' '));await shot('done-books');
  await press('l');await sleep(300);const lf=await ev("document.querySelector('[data-k=h-left]')?.parentElement?.innerText||''");
  ok(await ev("document.activeElement?.dataset.k==='h-left'")&&/Damaged Download Test/.test(lf)&&/\.mkv/.test(lf),'4p walk: L jumps to the left-out list with reasons',lf.slice(0,200));await shot('done-left-out');
  await press('u');await sleep(400);ok(/undo/i.test(await ev(dlg))&&await ev("/keep the import/i.test(document.activeElement?.innerText||'')"),'4p walk: U asks first; "No, keep the import" has the focus',await ev("document.activeElement?.innerText||''"));await shot('undo-ask');
  await press('Escape');await sleep(300);ok(!fs.existsSync(path.join(A.data,'publish-queue',ID+'.undo-plan.request.json')),'4p walk: Esc = keep: nothing is requested');
  ok(await ev("!!document.activeElement&&document.activeElement!==document.body"),'4p walk: focus stays on the page after Esc');
  await press('n');await loaded();ok(!/id=[a-f0-9]{32}/.test(await ev("location.href"))&&/import/i.test(await ev(h2)),'4p walk: N starts a new import',await ev("location.href"));await shot('new-import');
  rmIf(path.join(PS,ID+'.json'));
 }catch(e){ok(false,'4p walk: '+e.message);try{await shot('error');}catch(_){}} 
 const bad=errs.filter(x=>!/favicon|404/.test(x));ok(bad.length===0,'4p walk: no JavaScript errors and no browser pop-ups',bad.join(' | '));
 try{await B.send('Browser.close');}catch(e){}try{br.kill();}catch(e){}
 await sleep(800);try{fs.rmSync(prof,{recursive:true,force:true});}catch(e){}
 console.log(`walk-4p: ${pass} passed, ${fail} failed`);if(fail===0)console.log('WALK PASSED');process.exit(fail===0?0:1);
})().catch(e=>{console.log('FAIL walk crashed: '+e.stack);process.exit(1);});
function location_base(host){return 'http://'+host+':8080/';}
