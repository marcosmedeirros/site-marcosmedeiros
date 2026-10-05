import {test} from 'node:test';
import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,rm,readFile} from 'node:fs/promises';
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
  const server=spawn(php,['-S',`127.0.0.1:${port}`,'-t','.', 'scripts/router.php'],{env,stdio:['ignore','ignore','pipe'],windowsHide:true});
  let serverLog='';server.stderr.on('data',d=>serverLog+=d);
  t.after(async()=>{server.kill();await new Promise(resolve=>server.once('exit',resolve));await rm(temp,{recursive:true,force:true});});
  let ready=false;for(let i=0;i<60;i++){try{const r=await fetch(base+'/api.php?action=session');if(r.ok){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,100));}
  assert.ok(ready,serverLog);
  let cookie='',csrf='';
  async function request(url,options={}){
    const res=await fetch(url,{redirect:'manual',...options,headers:{...(cookie?{Cookie:cookie}:{}),...options.headers}});
    const set=res.headers.get('set-cookie');if(set)cookie=set.split(';')[0];return res;
  }
  async function api(action,body,headers={}){return request(`${base}/api.php?action=${action}`,{method:body===undefined?'GET':'POST',headers:{...(body===undefined?{}:{'Content-Type':'application/json','X-CSRF-Token':csrf}),...headers},body:body===undefined?undefined:JSON.stringify(body)});}
  let res=await api('bootstrap');assert.equal(res.status,401);
  const session=await(await api('session')).json();csrf=session.data.csrf;
  assert.equal((await api('login',{email:'test@example.com',password},{'X-CSRF-Token':'invalid'})).status,403);
  const login=await(await api('login',{email:'test@example.com',password})).json();assert.ok(login.ok);csrf=login.data.csrf;
  await t.test('cookie and private response policy',async()=>{
    res=await api('bootstrap');assert.match(res.headers.get('cache-control'),/no-store/);assert.equal((await res.json()).data.records.length,0);
    assert.equal((await fetch(origin+'/.controlevida.local.php')).status,404);
    assert.equal((await fetch(base+'/server/core.php')).status,404);
  });
  let task;
  await t.test('validated records, CSRF, ownership and concurrent edits',async()=>{
    const payload={kind:'task',title:'Task <script>unsafe</script>',day:'2026-10-05',details:{area:'casa',priority:'alta'}};
    assert.equal((await api('save',payload,{Origin:'https://untrusted.example'})).status,403);
    assert.equal((await api('save',{...payload,day:'2026-02-30'})).status,400);
    task=(await(await api('save',payload)).json()).data;assert.equal(task.revision,1);
    res=await api('save',{...payload,id:task.id,revision:task.revision,title:'Updated'});task=(await res.json()).data;assert.equal(task.revision,2);
    assert.equal((await api('save',{...payload,id:task.id,revision:1})).status,409);
    assert.equal((await api('save',{...payload,id:'someone-elses-id',revision:1})).status,404);
    res=await api('mark',{id:task.id,revision:task.revision,done:true,day:'2026-10-05'});task=(await res.json()).data;assert.equal(task.status,'done');
    res=await api('archive',{id:task.id,revision:task.revision,archived:true});task=(await res.json()).data;assert.equal(task.status,'archived');
    res=await api('archive',{id:task.id,revision:task.revision,archived:false});task=(await res.json()).data;assert.equal(task.status,'done');
    assert.equal((await api('save',{kind:'transaction',title:'Invalid',day:'2026-10-05',details:{amount_cents:10.5}})).status,400);
    const habit=(await(await api('save',{kind:'habit',title:'Small step',day:'',details:{recurrence:'weekly',weekdays:[1,3],size:'mini'}})).json()).data;
    assert.equal(habit.details.size,'mini');
    res=await api('mark',{id:habit.id,revision:1,day:'2026-10-05',done:true});assert.deepEqual((await res.json()).data.details.completed_dates,['2026-10-05']);
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
  async function authorize(scope='read write'){
    const url=new URL(base+'/oauth.php');url.search=new URLSearchParams({route:'authorize',response_type:'code',client_id:clientId,redirect_uri:redirect,code_challenge:challenge,code_challenge_method:'S256',resource:base+'/mcp.php',scope,state:'test-state'});
    res=await request(url);assert.equal(res.status,200);const consent=await res.text();assert.match(consent,/Conectar assistente/);const requestId=consent.match(/name="request_id" value="([^"]+)"/)[1];
    res=await request(base+'/oauth.php?route=authorize',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf,request_id:requestId,decision:'allow',...(scope.includes('write')?{write:'1'}:{})})});
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
    const code=await authorize('read');
    const ro=await(await exchange({grant_type:'authorization_code',code,code_verifier:verifier,redirect_uri:redirect})).json();
    res=await fetch(base+'/mcp.php',{method:'POST',headers:{Authorization:`Bearer ${ro.access_token}`,'Content-Type':'application/json'},body:JSON.stringify({jsonrpc:'2.0',id:1,method:'tools/call',params:{name:'salvar_registro',arguments:{kind:'note',title:'Denied',day:'2026-10-05',details:{}}}})});assert.equal(res.status,403);
    const connections=(await(await api('connections')).json()).data;
    for(const c of connections)await api('revoke',{id:c.id});
    assert.equal((await fetch(base+'/mcp.php',{headers:{Authorization:`Bearer ${ro.access_token}`}})).status,401);
    await api('logout',{});assert.equal((await api('bootstrap')).status,401);
  });
  await t.test('direct database migration preserves legacy relationships and can be repeated',async()=>{
    const fixture=spawnSync(php,['tests/legacy-fixture.php'],{env,encoding:'utf8'});assert.equal(fixture.status,0,fixture.stderr);
    const first=spawnSync(php,['scripts/migrate-legacy.php'],{env,encoding:'utf8'});assert.equal(first.status,0,first.stderr);
    const report=JSON.parse(first.stdout);assert.equal(report.finance.new_records,2);assert.equal(report.created.tasks,1);assert.equal(report.created.habits,1);assert.equal(report.created.events,1);
    const second=spawnSync(php,['scripts/migrate-legacy.php'],{env,encoding:'utf8'});assert.equal(second.status,0,second.stderr);
    const repeated=JSON.parse(second.stdout);assert.equal(repeated.finance.new_records,0);assert.equal(repeated.skipped.tasks,1);
    const backup=JSON.parse(await readFile(report.backup,'utf8'));assert.equal(backup.tables.finances.length,2);
  });
  await t.test('OAuth authorization survives the sign-in redirect',async()=>{
    const fresh=await(await api('session')).json();csrf=fresh.data.csrf;
    const url=new URL(base+'/oauth.php');url.search=new URLSearchParams({route:'authorize',response_type:'code',client_id:clientId,redirect_uri:redirect,code_challenge:challenge,code_challenge_method:'S256',resource:base+'/mcp.php',scope:'read',state:'after-login'});
    res=await request(url);assert.equal(res.status,302);assert.equal(res.headers.get('location'),'/controlevida/');
    const login=await(await api('login',{email:'test@example.com',password})).json();assert.ok(login.ok);csrf=login.data.csrf;
    res=await request(base+'/');assert.equal(res.status,302);assert.match(res.headers.get('location'),/route=authorize/);
    res=await request(origin+res.headers.get('location'));assert.equal(res.status,200);assert.match(await res.text(),/Conectar assistente/);
  });
  assert.doesNotMatch(serverLog,/Fatal error|Warning|Parse error/);
});
