import {test} from 'node:test';
import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,rm,readFile,writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {createServer} from 'node:net';
import {randomBytes,createHash} from 'node:crypto';
import {Client} from '@modelcontextprotocol/sdk/client/index.js';
import {StreamableHTTPClientTransport} from '@modelcontextprotocol/sdk/client/streamableHttp.js';

test('Private hub: sessions, money, migration, ownership, OAuth and MCP',async t=>{
  const php=process.env.PHP_BIN || (process.platform==='win32'?'C:/xampp/php/php.exe':'php');
  const temp=await mkdtemp(path.join(tmpdir(),'controlevida-test-'));
  const listener=createServer();await new Promise(resolve=>listener.listen(0,'127.0.0.1',resolve));const port=listener.address().port;await new Promise(resolve=>listener.close(resolve));
  const origin=`http://127.0.0.1:${port}`,base=origin+'/controlevida';
  const password=randomBytes(20).toString('hex');
  const env={...process.env,CV_CONFIG:path.join(temp,'config.php'),CV_DATA_DIR:path.join(temp,'data'),CV_URL:base,CV_ADMIN_EMAIL:'test@example.com',CV_ADMIN_PASSWORD:password};
  const setup=spawnSync(php,['scripts/setup.php'],{env,encoding:'utf8'});assert.equal(setup.status,0,setup.stderr);
  // Tests run from controlevida/; the document root is the site root, one level up.
  const server=spawn(php,['-S',`127.0.0.1:${port}`,'-t','..','scripts/router.php'],{env,stdio:['ignore','ignore','pipe'],windowsHide:true});
  let serverLog='';server.stderr.on('data',d=>serverLog+=d);
  t.after(async()=>{server.kill();await new Promise(resolve=>server.once('exit',resolve));await rm(temp,{recursive:true,force:true});});
  let ready=false;for(let i=0;i<60;i++){try{const r=await fetch(base+'/api.php?action=session');if(r.ok){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,100));}
  assert.ok(ready,serverLog);
  let cookie='',csrf='',lastSetCookie='';
  async function request(url,options={}){
    const res=await fetch(url,{redirect:'manual',...options,headers:{...(cookie?{Cookie:cookie}:{}),...options.headers}});
    const set=res.headers.get('set-cookie');if(set){lastSetCookie=set;cookie=set.split(';')[0];}return res;
  }
  async function api(action,body,headers={}){return request(`${base}/api.php?action=${action}`,{method:body===undefined?'GET':'POST',headers:{...(body===undefined?{}:{'Content-Type':'application/json','X-CSRF-Token':csrf}),...headers},body:body===undefined?undefined:JSON.stringify(body)});}
  let res=await api('bootstrap');assert.equal(res.status,401);
  const session=await(await api('session')).json();csrf=session.data.csrf;
  assert.equal((await api('login',{email:'test@example.com',password},{'X-CSRF-Token':'invalid'})).status,403);
  const login=await(await api('login',{email:'test@example.com',password})).json();assert.ok(login.ok);csrf=login.data.csrf;
  assert.doesNotMatch(lastSetCookie,/expires=/i,'a plain sign-in ends with the browser session');
  await t.test('cookie and private response policy',async()=>{
    res=await api('bootstrap');assert.match(res.headers.get('cache-control'),/no-store/);assert.equal((await res.json()).data.records.length,0);
    for(const hidden of ['/.private/config.php','/.private/chave-instalacao.txt','/server/core.php','/server/install-key.php','/scripts/setup.php','/tests/integration.mjs','/docs/deploy.md','/package.json','/README.md','/.gitignore','/.htaccess'])assert.equal((await fetch(base+hidden)).status,404,hidden);
    assert.equal((await fetch(base+'/install.php')).status,404,'the installer vanishes once configured');
    res=await fetch(origin+'/');assert.equal(res.status,200);assert.match(await res.text(),/<html lang="pt-BR"/,'the portfolio keeps answering at the site root');
  });
  let task;
  await t.test('validated records, CSRF, ownership and concurrent edits',async()=>{
    const payload={kind:'task',title:'Task <script>unsafe</script>',day:'2026-10-05',details:{area:'casa',priority:'alta'}};
    assert.equal((await api('save',payload,{Origin:'https://untrusted.example'})).status,403,'the app API stays strict about origins');
    assert.equal((await api('save',{...payload,day:'2026-02-30'})).status,400);
    task=(await(await api('save',payload)).json()).data;assert.equal(task.revision,1);
    res=await api('save',{...payload,id:task.id,revision:task.revision,title:'Updated'});task=(await res.json()).data;assert.equal(task.revision,2);
    assert.equal((await api('save',{...payload,id:task.id,revision:1})).status,409);
    assert.equal((await api('save',{...payload,id:'someone-elses-id',revision:1})).status,404);
    res=await api('mark',{id:task.id,revision:task.revision,done:true,day:'2026-10-05'});task=(await res.json()).data;assert.equal(task.status,'done');
    res=await api('archive',{id:task.id,revision:task.revision,archived:true});task=(await res.json()).data;assert.equal(task.status,'archived');
    res=await api('archive',{id:task.id,revision:task.revision,archived:false});task=(await res.json()).data;assert.equal(task.status,'done');
    assert.equal((await api('save',{kind:'transaction',title:'Invalid',day:'2026-10-05',details:{amount_cents:10.5}})).status,400);
    const habit=(await(await api('save',{kind:'habit',title:'Small step',day:'',details:{recurrence:'weekly',weekdays:[1,3]}})).json()).data;
    assert.equal(habit.details.weekdays.join(),'1,3');assert.equal(habit.details.size,undefined,'habits have no size any more');
    res=await api('mark',{id:habit.id,revision:1,day:'2026-10-05',done:true});assert.deepEqual((await res.json()).data.details.completed_dates,['2026-10-05']);
  });
  await t.test('money is filed by month and a goal is simply done or not',async()=>{
    const tx=(await(await api('save',{kind:'transaction',title:'',day:'2026-09-17',details:{direction:'expense',amount_cents:4590,category:'Mercado'}})).json()).data;
    assert.equal(tx.day,'2026-09-01','the stored day is the first of the month');
    assert.equal(tx.title,'Mercado','an entry with no description takes its category');
    const goal=(await(await api('save',{kind:'goal',title:'Correr 5 km',day:'',details:{notes:'passo a passo'}})).json()).data;
    assert.equal(goal.status,'open');assert.equal(goal.details.progress,undefined,'no percentage is kept');
    const reached=(await(await api('mark',{id:goal.id,revision:goal.revision,done:true})).json()).data;
    assert.equal(reached.status,'done');
    const reopened=(await(await api('mark',{id:reached.id,revision:reached.revision,done:false})).json()).data;
    assert.equal(reopened.status,'open');
    // Put the hub back as it was so the later counts stay exact.
    for(const r of [tx,reopened])assert.ok((await(await api('archive',{id:r.id,revision:r.revision,archived:true})).json()).ok);
  });
  await t.test('calendar feed is off by default, token-gated and revocable',async()=>{
    assert.equal((await fetch(`${base}/calendar.php`)).status,404,'no feed without a token');
    assert.equal((await fetch(`${base}/calendar.php?t=${'a'.repeat(43)}`)).status,404,'a wrong token looks like nothing');
    // Something dated, something repeating and something that must stay out of the feed.
    const evt=(await(await api('save',{kind:'event',title:'Reunião PNIP',day:'2026-10-07',details:{time:'09:00',end_time:'10:00',location:'Online'}})).json()).data;
    const chore=(await(await api('save',{kind:'task',title:'Varrer, passar & limpar',day:'',details:{recurrence:'weekly',weekdays:[1,3],area:'casa'}})).json()).data;
    const secret=(await(await api('save',{kind:'transaction',title:'Salário',day:'2026-10-01',details:{direction:'income',amount_cents:500000,category:'Trabalho'}})).json()).data;
    const token=(await(await api('link',{name:'calendar',enable:true})).json()).data.calendar_token;
    assert.match(token,/^[A-Za-z0-9_-]{30,}$/);
    const res=await fetch(`${base}/calendar.php?t=${token}`);
    assert.equal(res.status,200);assert.match(res.headers.get('content-type'),/text\/calendar/);assert.match(res.headers.get('cache-control'),/no-store/);
    const ics=await res.text();
    assert.match(ics,/^BEGIN:VCALENDAR/);assert.match(ics,/END:VCALENDAR\r\n$/);
    assert.match(ics,/SUMMARY:Reunião PNIP/);assert.match(ics,/DTSTART:20261007T120000Z/,'09:00 in São Paulo is 12:00 UTC');
    assert.match(ics,/SUMMARY:Varrer\\, passar & limpar/,'commas are escaped, the rest is literal');
    assert.match(ics,/RRULE:FREQ=WEEKLY;BYDAY=MO,WE/);
    assert.doesNotMatch(ics,/Salário|500000/,'money never reaches a feed that lives in a URL');
    assert.equal(ics.split('\r\n').every(l=>Buffer.byteLength(l)<=75),true,'every line is folded to 75 octets');
    await api('link',{name:'calendar',enable:false});
    assert.equal((await fetch(`${base}/calendar.php?t=${token}`)).status,404,'revoking kills the link');
    for(const r of [evt,chore,secret])await api('archive',{id:r.id,revision:r.revision,archived:true});
  });
  await t.test('widget feed serves the day and carries its own Scriptable script',async()=>{
    assert.equal((await fetch(`${base}/widget.php`)).status,404,'no payload without a token');
    const chore=(await(await api('save',{kind:'task',title:'Limpar cozinha',day:'',details:{recurrence:'daily',area:'casa'}})).json()).data;
    const run=(await(await api('save',{kind:'workout',title:'Corrida do widget',day:'',details:{activity:'corrida',recurrence:'daily',duration_min:20}})).json()).data;
    const money=(await(await api('save',{kind:'transaction',title:'Aluguel',day:'2026-10-01',details:{direction:'expense',amount_cents:120000,category:'Casa'}})).json()).data;
    const token=(await(await api('link',{name:'widget',enable:true})).json()).data.widget_token;
    let res=await fetch(`${base}/widget.php?t=${token}`);
    assert.equal(res.status,200);assert.ok(res.headers.get('cache-control').includes('no-store'));
    const body=await res.text();
    assert.doesNotMatch(body,/Aluguel|120000/,'money stays out of a link that lives in a URL');
    const data=JSON.parse(body);
    assert.equal(data.hoje.total,data.hoje.itens.length);
    for(const titulo of ['Corrida do widget','Limpar cozinha'])assert.ok(data.hoje.itens.some(i=>i.titulo===titulo),titulo);
    assert.ok(data.semana.total>=7,'a daily workout is due every day of the week');
    const marcado=(await(await api('mark',{id:run.id,revision:run.revision,done:true})).json()).data;
    const depois=await(await fetch(`${base}/widget.php?t=${token}`)).json();
    assert.equal(depois.hoje.feitos,data.hoje.feitos+1);
    assert.equal(depois.hoje.itens[0].feito,false,'what is still missing comes first');
    res=await fetch(`${base}/widget.php?t=${token}&script=1`);
    assert.ok(res.headers.get('content-type').includes('text/plain'));
    const script=await res.text();
    assert.ok(script.includes(token),'the script carries its own address');
    const file=path.join(temp,'widget.mjs');await writeFile(file,script);
    assert.equal(spawnSync(process.execPath,['--check',file],{encoding:'utf8'}).status,0,'the generated script parses');
    await api('link',{name:'widget',enable:false});
    assert.equal((await fetch(`${base}/widget.php?t=${token}`)).status,404,'revoking kills the link');
    for(const r of [chore,marcado,money])assert.ok((await(await api('archive',{id:r.id,revision:r.revision,archived:true})).json()).ok);
  });
  await t.test('progress photos stay behind the session and out of every link',async()=>{
    const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==','base64');
    const envia=(bytes,nome,tipo,token=csrf)=>{
      const form=new FormData();
      form.append('foto',new Blob([bytes],{type:tipo}),nome);
      form.append('day','2026-10-09');
      return request(`${base}/photos.php?action=upload`,{method:'POST',headers:{'X-CSRF-Token':token},body:form});
    };
    assert.equal((await fetch(`${base}/photos.php?action=list`)).status,401,'no session, no list');
    assert.equal((await envia(png,'f.png','image/png','errado')).status,403,'the upload needs the form token');
    assert.equal((await envia(Buffer.from('nao sou uma imagem'),'f.txt','text/plain')).status,400,'only real images');
    let res=await envia(png,'foto.png','image/png');
    assert.equal(res.status,200);
    const fotos=(await res.json()).data;
    assert.equal(fotos.length,1);assert.equal(fotos[0].day,'2026-10-09');
    res=await request(`${base}/photos.php?action=file&id=${fotos[0].id}`);
    assert.equal(res.status,200);
    assert.ok(res.headers.get('content-type').startsWith('image/'));
    assert.ok((await res.arrayBuffer()).byteLength>0,'the bytes come back');
    assert.equal((await fetch(`${base}/photos.php?action=file&id=${fotos[0].id}`)).status,401,'and only to whoever is signed in');
    assert.equal((await request(`${base}/photos.php?action=file&id=naoexiste`)).status,404);
    const guardados=(await(await api('bootstrap')).json()).data.records;
    assert.ok(!guardados.some(r=>r.id===fotos[0].id),'a photo is not a record, so no feed or tool can reach it');
    res=await request(`${base}/photos.php?action=delete`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({id:fotos[0].id})});
    assert.equal((await res.json()).data.length,0);
    assert.equal((await request(`${base}/photos.php?action=file&id=${fotos[0].id}`)).status,404,'and the file goes with it');
  });
  await t.test('Strava import ticks the plan, keeps the secret and never repeats itself',async()=>{
    // The credentials go in through the site, so the browser must never get the secret back.
    let res=await request(`${base}/strava.php?route=credentials`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({client_id:'  12345 ',client_secret:'s'.repeat(40)})});
    assert.equal(res.status,200);
    const settings=(await(await api('bootstrap')).json()).data.settings;
    assert.equal(settings.strava.client_id,'12345');
    assert.equal(settings.strava.configurado,true);
    assert.equal(settings.strava.conectado,false);
    assert.equal(settings.strava.client_secret,undefined,'the secret stays on the server');
    assert.doesNotMatch(JSON.stringify(settings),/ssssss/,'and does not leak anywhere else in the payload');
    assert.equal((await request(`${base}/strava.php?route=credentials`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({client_id:'12345',client_secret:'curto'})})).status,400);
    assert.equal((await request(`${base}/strava.php?route=sync`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:'{}'})).status,400,'syncing before connecting is refused');
    // Connecting is asked for by fetch and answers with the address, because a form POST reports
    // an origin the server does not recognise and was refused.
    res=await request(`${base}/strava.php?route=connect`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:'{}'});
    const destino=new URL((await res.json()).data.url);
    assert.equal(destino.searchParams.get('client_id'),'12345');
    assert.equal(destino.searchParams.get('scope'),'activity:read_all','reading past activities needs this scope');
    assert.equal(destino.searchParams.get('redirect_uri'),`${base}/strava.php?route=callback`);
    assert.ok(destino.searchParams.get('state').length>20,'and carries a state against forged callbacks');
    assert.equal((await request(`${base}/strava.php?route=connect`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':'errado'},body:'{}'})).status,403);

    // A planned Monday run, and the activity the watch pushed for that same Monday.
    const plano=(await(await api('save',{kind:'workout',title:'Corrida planejada',day:'',details:{activity:'corrida',recurrence:'weekly',weekdays:[1],duration_min:20}})).json()).data;
    const atividades=[
      {id:9001,name:'Corrida da manhã',sport_type:'Run',start_date_local:'2026-10-05T07:10:00Z',distance:3200,moving_time:965,average_heartrate:158.4},
      {id:9002,name:'Pedal leve',sport_type:'Ride',start_date_local:'2026-10-07T18:00:00Z',distance:12000,moving_time:1800},
      {id:9003,name:'Sem data',sport_type:'Run',start_date_local:'',distance:1000,moving_time:300},
    ];
    const arquivo=path.join(temp,'strava.json');
    await writeFile(arquivo,JSON.stringify(atividades));
    const roda=()=>{const r=spawnSync(php,['scripts/strava-import.php',arquivo],{env,encoding:'utf8'});assert.equal(r.status,0,r.stderr||r.stdout);return JSON.parse(r.stdout);};
    const primeira=roda();
    assert.deepEqual(primeira,{novos:2,repetidos:0,marcados:1,ignorados:1},'two imported, one ticked off, one without a date');
    const segunda=roda();
    assert.deepEqual(segunda,{novos:0,repetidos:2,marcados:0,ignorados:1},'a second run changes nothing');

    const guardados=(await(await api('bootstrap')).json()).data.records;
    const corrida=guardados.find(r=>r.title==='Corrida da manhã');
    assert.equal(corrida.day,'2026-10-05');assert.equal(corrida.status,'done');
    assert.equal(corrida.details.activity,'corrida');assert.equal(corrida.details.duration_min,16);
    assert.match(corrida.details.notes,/3,20 km/);assert.match(corrida.details.notes,/5:02\/km/);assert.match(corrida.details.notes,/158 bpm/);
    assert.equal(guardados.find(r=>r.title==='Pedal leve').details.activity,'outro');
    const marcado=guardados.find(r=>r.id===plano.id);
    assert.deepEqual(marcado.details.completed_dates,['2026-10-05'],'the planned session is the one that counts as done');

    await request(`${base}/strava.php?route=disconnect`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:'{}'});
    assert.equal((await(await api('bootstrap')).json()).data.settings.strava,undefined,'disconnecting wipes it');
    for(const r of [marcado,corrida,guardados.find(x=>x.title==='Pedal leve')])await api('archive',{id:r.id,revision:r.revision,archived:true});
  });
  await t.test('finance import is atomic, exact and idempotent',async()=>{
    const snapshot={format:'controlevida-legacy-finance-v1',from:'2026-01-01',to:'2026-12-31',initial_balance_cents:10000,categories:[{name:'Casa'}],transactions:[{id:1,amount:'19.99',type:'expense',description:'House',cat_name:'Casa',transaction_date:'2026-10-05'},{id:2,amount:'50.00',type:'income',description:'Income',cat_name:'Trabalho',transaction_date:'2026-10-05'}]};
    let data=(await(await api('import',snapshot)).json()).data;assert.equal(data.new_records,2);
    data=(await(await api('import',snapshot)).json()).data;assert.equal(data.new_records,0);assert.equal(data.already_present,2);
    assert.equal((await api('import',{...snapshot,transactions:[{...snapshot.transactions[0],id:3},{...snapshot.transactions[1],id:4,amount:'oops'}]})).status,400);
    data=(await(await api('bootstrap')).json()).data;
    assert.equal(data.records.filter(r=>r.kind==='transaction').length,2);assert.equal(data.settings.initial_balance_cents,10000);
  });
  let clientId,access,refreshToken;
  const verifier=randomBytes(32).toString('base64url'),challenge=createHash('sha256').update(verifier).digest('base64url');
  const redirect=origin+'/callback';
  async function authorize(scope='read write',omitResource=false){
    const params={route:'authorize',response_type:'code',client_id:clientId,redirect_uri:redirect,code_challenge:challenge,code_challenge_method:'S256',scope,state:'test-state'};
    if(!omitResource)params.resource=base+'/mcp.php';
    const url=new URL(base+'/oauth.php');url.search=new URLSearchParams(params);
    res=await request(url);assert.equal(res.status,200);const consent=await res.text();assert.match(consent,/Conectar assistente/);
    assert.doesNotMatch(res.headers.get('content-security-policy'),/form-action/,'the consent form must be free to redirect to the assistant callback');const requestId=consent.match(/name="request_id" value="([^"]+)"/)[1];
    assert.equal((await request(base+'/oauth.php?route=authorize',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded',Origin:'https://claude.ai'},body:new URLSearchParams({csrf:'wrong',request_id:requestId,decision:'allow'})})).status,403,'the consent form still needs its own token');
    // The assistant's browser may report any origin on this form; the token and request id are the guard.
    res=await request(base+'/oauth.php?route=authorize',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded',Origin:'https://claude.ai'},body:new URLSearchParams({csrf,request_id:requestId,decision:'allow',...(scope.includes('write')?{write:'1'}:{})})});
    assert.equal(res.status,302);return new URL(res.headers.get('location')).searchParams.get('code');
  }
  async function exchange(args){return request(base+'/oauth.php?route=token',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({client_id:clientId,resource:base+'/mcp.php',...args})});}
  await t.test('OAuth discovery, consent, PKCE and replay protection',async()=>{
    res=await fetch(base+'/mcp.php');assert.equal(res.status,401);
    const metadataUrl=res.headers.get('www-authenticate').match(/resource_metadata="([^"]+)"/)[1];
    const metadata=await(await fetch(metadataUrl)).json();assert.equal(metadata.resource,base+'/mcp.php');
    const serverMetadata=await(await fetch(origin+'/.well-known/oauth-authorization-server/controlevida')).json();assert.ok(serverMetadata.code_challenge_methods_supported.includes('S256'));
    res=await request(base+'/oauth.php?route=register',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({client_name:'Test MCP',redirect_uris:[redirect],token_endpoint_auth_method:'none'})});assert.equal(res.status,201);clientId=(await res.json()).client_id;
    const code=await authorize();
    assert.equal((await exchange({grant_type:'authorization_code',code,code_verifier:'a'.repeat(43),redirect_uri:redirect})).status,400);
    const token=await(await exchange({grant_type:'authorization_code',code,code_verifier:verifier,redirect_uri:redirect})).json();access=token.access_token;refreshToken=token.refresh_token;assert.ok(access);
    assert.equal((await exchange({grant_type:'authorization_code',code,code_verifier:verifier,redirect_uri:redirect})).status,400);
  });
  await t.test('any assistant origin and protocol revision reaches the hub',async()=>{
    const call=(headers)=>fetch(base+'/mcp.php',{method:'POST',headers:{Authorization:`Bearer ${access}`,'Content-Type':'application/json',...headers},body:JSON.stringify({jsonrpc:'2.0',id:9,method:'tools/list'})});
    for(const origin of ['https://claude.ai','https://chatgpt.com'])assert.equal((await call({Origin:origin})).status,200,origin);
    assert.equal((await call({'MCP-Protocol-Version':'2025-03-26'})).status,200,'a newer protocol revision is accepted');
    assert.equal((await call({'MCP-Protocol-Version':'whenever'})).status,400,'a malformed revision still fails');
    assert.equal((await fetch(base+'/mcp.php',{method:'POST',headers:{'Content-Type':'application/json',Origin:'https://claude.ai'},body:'{}'})).status,401,'but a token is still required');
  });
  await t.test('official MCP SDK can initialize, list and update the hub',async()=>{
    const client=new Client({name:'controlevida-test',version:'1.0.0'});
    const transport=new StreamableHTTPClientTransport(new URL(base+'/mcp.php'),{requestInit:{headers:{Authorization:`Bearer ${access}`}}});
    await client.connect(transport);
    const tools=await client.listTools();assert.equal(tools.tools.length,5);
    const result=await client.callTool({name:'consultar_painel',arguments:{month:'2026-10'}});assert.equal(result.isError,undefined);
    const dashboard=JSON.parse(result.content[0].text);assert.equal(dashboard.finance.balance_cents,13001);
    const written=await client.callTool({name:'salvar_registro',arguments:{kind:'meal',title:'Almoço',day:'2026-10-05',details:{meal:'almoco',notes:'Arroz, feijão e legumes',reflection:'Registro informado pelo usuário.'}}});assert.equal(written.isError,undefined);
    assert.ok((await(await api('bootstrap')).json()).data.records.some(r=>r.kind==='meal'));
    await client.close();
  });
  await t.test('refresh rotation, restricted scopes and revocation',async()=>{
    const rotated=await(await exchange({grant_type:'refresh_token',refresh_token:refreshToken})).json();assert.ok(rotated.access_token);
    assert.equal((await exchange({grant_type:'refresh_token',refresh_token:refreshToken})).status,400);
    assert.equal((await fetch(base+'/mcp.php',{headers:{Authorization:`Bearer ${access}`}})).status,401);
    const code=await authorize('read',true);
    const ro=await(await exchange({grant_type:'authorization_code',code,code_verifier:verifier,redirect_uri:redirect})).json();
    assert.ok(ro.access_token,'a client that omits the resource indicator still connects');
    res=await fetch(base+'/mcp.php',{method:'POST',headers:{Authorization:`Bearer ${ro.access_token}`,'Content-Type':'application/json'},body:JSON.stringify({jsonrpc:'2.0',id:1,method:'tools/call',params:{name:'salvar_registro',arguments:{kind:'note',title:'Denied',day:'2026-10-05',details:{}}}})});assert.equal(res.status,403);
    const connections=(await(await api('connections')).json()).data;
    for(const c of connections)await api('revoke',{id:c.id});
    assert.equal((await fetch(base+'/mcp.php',{headers:{Authorization:`Bearer ${ro.access_token}`}})).status,401);
    await api('logout',{});assert.equal((await api('bootstrap')).status,401);
  });
  await t.test('direct database migration preserves legacy relationships and can be repeated',async()=>{
    const fixture=spawnSync(php,['tests/legacy-fixture.php'],{env,encoding:'utf8'});assert.equal(fixture.status,0,fixture.stderr);
    const first=spawnSync(php,['scripts/migrate-legacy.php'],{env,encoding:'utf8'});assert.equal(first.status,0,first.stderr);
    const report=JSON.parse(first.stdout);assert.equal(report.finance.new_records,2);assert.equal(report.created.tasks,2);assert.equal(report.created.habits,1);assert.equal(report.created.events,1);
    assert.deepEqual(report.invalid.finances.map(x=>x.id),['legacy-11'],'a zeroed legacy row is reported, not fatal');
    const second=spawnSync(php,['scripts/migrate-legacy.php'],{env,encoding:'utf8'});assert.equal(second.status,0,second.stderr);
    const repeated=JSON.parse(second.stdout);assert.equal(repeated.finance.new_records,0);assert.equal(repeated.skipped.tasks,2);
    const backup=JSON.parse(await readFile(report.backup,'utf8'));assert.equal(backup.tables.finances.length,3);
  });
  await t.test('OAuth authorization survives the sign-in redirect',async()=>{
    const fresh=await(await api('session')).json();csrf=fresh.data.csrf;
    const url=new URL(base+'/oauth.php');url.search=new URLSearchParams({route:'authorize',response_type:'code',client_id:clientId,redirect_uri:redirect,code_challenge:challenge,code_challenge_method:'S256',resource:base+'/mcp.php',scope:'read',state:'after-login'});
    res=await request(url);assert.equal(res.status,302);assert.equal(res.headers.get('location'),'/controlevida/');
    const login=await(await api('login',{email:'test@example.com',password,remember:true})).json();assert.ok(login.ok);csrf=login.data.csrf;
    assert.match(lastSetCookie,/expires=/i,'"manter conectado" issues a persistent cookie');assert.equal(lastSetCookie.match(/CONTROLEVIDA=/g).length,1,'only one session cookie is sent');
    res=await request(base+'/');assert.equal(res.status,302);assert.match(res.headers.get('location'),/route=authorize/);
    res=await request(origin+res.headers.get('location'));assert.equal(res.status,200);assert.match(await res.text(),/Conectar assistente/);
    const loose=(await(await api('bootstrap')).json()).data.records.find(r=>r.title==='No weekday');
    assert.equal(loose.details.recurrence,'once');assert.equal(loose.details.area,'pessoal');
    assert.equal((await(await api('migrate',{})).json()).data.finance.new_records,0,'the settings button can repeat the import safely');
  });
  await t.test('web installer is key-protected, one-time, and leaves nothing behind on failure',async()=>{
    const temp2=await mkdtemp(path.join(tmpdir(),'controlevida-install-'));
    const l2=createServer();await new Promise(resolve=>l2.listen(0,'127.0.0.1',resolve));const port2=l2.address().port;await new Promise(resolve=>l2.close(resolve));
    const base2=`http://127.0.0.1:${port2}/controlevida`,config2=path.join(temp2,'config.php'),key='test-install-key';
    const env2={...process.env,CV_CONFIG:config2,CV_DATA_DIR:path.join(temp2,'data'),CV_INSTALL_KEY_SHA256:createHash('sha256').update(key).digest('hex'),CV_INSTALL_DSN:'sqlite:'+path.join(temp2,'install.sqlite')};
    const server2=spawn(php,['-S',`127.0.0.1:${port2}`,'-t','..','scripts/router.php'],{env:env2,stdio:['ignore','ignore','pipe'],windowsHide:true});
    let log2='';server2.stderr.on('data',d=>log2+=d);
    try{
      let up=false;for(let i=0;i<60;i++){try{const r=await fetch(base2+'/install.php');if(r.ok){up=true;break;}}catch{}await new Promise(r=>setTimeout(r,100));}
      assert.ok(up,log2);
      let r=await fetch(base2+'/',{redirect:'manual'});assert.equal(r.status,302);assert.equal(r.headers.get('location'),'/controlevida/install.php');
      assert.equal((await fetch(base2+'/api.php?action=session')).status,503,'nothing answers before setup');
      const form={install_key:key,host:'localhost',database:'testdb',db_user:'tester',db_password:'',name:'Tester',email:'Owner@Example.com',password:'correct horse 42',password_confirm:'correct horse 42',import:'1'};
      const submit=fields=>fetch(base2+'/install.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(fields)});
      const missing=file=>readFile(file).then(()=>false,()=>true);
      r=await submit({...form,install_key:'guess'});assert.equal(r.status,403);assert.ok(await missing(config2));
      r=await submit({...form,password_confirm:'different'});assert.equal(r.status,400);assert.ok(await missing(config2));
      r=await submit({...form,password:'short',password_confirm:'short'});assert.equal(r.status,400);assert.ok(await missing(config2),'a rejected account leaves no configuration');
      r=await submit(form);assert.equal(r.status,200);
      const page=await r.text();assert.match(page,/Tudo pronto/);assert.match(page,/owner@example\.com/);assert.match(page,/Nenhum dado do app anterior/);
      assert.doesNotMatch(await readFile(config2,'utf8'),/correct horse/,'the account password never reaches the config file');
      assert.equal((await fetch(base2+'/install.php')).status,404);assert.equal((await submit(form)).status,404);
      const s=await fetch(base2+'/api.php?action=session');const jar=s.headers.get('set-cookie').split(';')[0];const token=(await s.json()).data.csrf;
      r=await fetch(base2+'/api.php?action=login',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token,Cookie:jar},body:JSON.stringify({email:'owner@example.com',password:'correct horse 42'})});assert.equal(r.status,200);
      assert.doesNotMatch(log2,/Fatal error|Warning|Parse error/);
    }finally{server2.kill();await new Promise(resolve=>server2.once('exit',resolve));await rm(temp2,{recursive:true,force:true});}
  });
  assert.doesNotMatch(serverLog,/Fatal error|Warning|Parse error/);
});
