import {mkdir,writeFile} from 'node:fs/promises';
import path from 'node:path';
const args=Object.fromEntries(process.argv.slice(2).map(a=>a.replace(/^--/,'').split('=')));
const from=args.from||'2025-01',to=args.to||'2026-12';
if(!/^\d{4}-(0[1-9]|1[0-2])$/.test(from)||!/^\d{4}-(0[1-9]|1[0-2])$/.test(to)||from>to)throw Error('Intervalo invalido.');
const output=path.resolve(args.output||'.private/legacy-snapshot.json');
async function get(action,params={}) {
  const url=new URL('https://marcosmedeiros.page/api_lifeos.php');url.searchParams.set('api',action);
  Object.entries(params).forEach(([k,v])=>url.searchParams.set(k,v));
  for(let attempt=0;attempt<3;attempt++) {
    const res=await fetch(url,{signal:AbortSignal.timeout(25000)});
    const data=await res.json().catch(()=>null);
    if(res.ok&&data?.ok)return data.data;
    if(attempt===2)throw Error(`Servidor antigo indisponivel durante ${action}; HTTP ${res.status}.`);
    await new Promise(resolve=>setTimeout(resolve,1000));
  }
}
const bootstrap=await get('bootstrap');const settings=await get('fin_settings_get');
const tx=[];
for(let month=from;month<=to;){
  tx.push(...await get('fin_transactions',{month}));
  const d=new Date(`${month}-01T12:00:00Z`);d.setUTCMonth(d.getUTCMonth()+1);month=d.toISOString().slice(0,7);
}
const initial=Math.round(Number(settings.initial_balance)*100);
const last=new Date(`${to}-01T12:00:00Z`);last.setUTCMonth(last.getUTCMonth()+1);last.setUTCDate(0);
const snapshot={format:'controlevida-legacy-finance-v1',exported_at:new Date().toISOString(),source:'https://marcosmedeiros.page',from:`${from}-01`,to:last.toISOString().slice(0,10),coverage:'API monthly range; full database migration is still required',initial_balance_cents:initial,categories:bootstrap.finance.categories,transactions:tx,state:bootstrap};
await mkdir(path.dirname(output),{recursive:true,mode:0o700});await writeFile(output,JSON.stringify(snapshot,null,2),{mode:0o600});
console.log(JSON.stringify({saved:output,from,to,transactions:tx.length,habits:bootstrap.habits.length,tasks:bootstrap.tasks.length}));
