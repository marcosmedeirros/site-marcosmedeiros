/* Personal data stays in memory; every change is confirmed by the server. */
const $ = (q, root = document) => root.querySelector(q);
const $$ = (q, root = document) => [...root.querySelectorAll(q)];
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const icon = name => `<i data-lucide="${name}" aria-hidden="true"></i>`;
const icons = () => window.lucide?.createIcons();
const currency = cents => new Intl.NumberFormat('pt-BR', {style:'currency',currency:'BRL'}).format(cents / 100);
const localDate = () => new Intl.DateTimeFormat('en-CA',{timeZone:'America/Sao_Paulo',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
const dateObject = day => new Date(`${day}T12:00:00`);
const dayLabel = day => day ? new Intl.DateTimeFormat('pt-BR',{day:'2-digit',month:'short'}).format(dateObject(day)) : 'Sem data';
const longDate = day => new Intl.DateTimeFormat('pt-BR',{weekday:'long',day:'numeric',month:'long'}).format(dateObject(day));
const shift = (day,n) => { const d = new Date(`${day}T12:00:00Z`); d.setUTCDate(d.getUTCDate()+n); return d.toISOString().slice(0,10); };
const weekday = day => dateObject(day).getDay() || 7;
const weekStart = day => shift(day,-(weekday(day)-1));
const shiftMonth = (month,n) => { const d=new Date(`${month}-01T12:00:00Z`); d.setUTCMonth(d.getUTCMonth()+n); return d.toISOString().slice(0,7); };
const views = {today:['Hoje','layout-dashboard'],tasks:['Tarefas','list-checks'],calendar:['Agenda','calendar-days'],habits:['Hábitos','circle-check'],finance:['Finanças','wallet'],workouts:['Treinos','activity'],meals:['Alimentação','utensils'],notes:['Notas e metas','notebook-pen'],history:['Histórico','history'],settings:['Ajustes e conexões','settings-2']};
const kinds = {task:['Tarefa','list-checks'],event:['Evento','calendar-days'],habit:['Hábito','circle-check'],transaction:['Lançamento','wallet'],workout:['Treino','activity'],meal:['Refeição','utensils'],note:['Nota','notebook-pen'],goal:['Meta','flag']};
const mealNames = {cafe:'Café da manhã',almoco:'Almoço',lanche:'Lanche',jantar:'Jantar',outro:'Outra refeição'};
const activities = {caminhada:'Caminhada',corrida:'Corrida',forca:'Força',futebol:'Futebol',mobilidade:'Mobilidade',descanso:'Descanso',outro:'Outra atividade'};
const days = ['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'];
const dayNames = ['Segunda','Terça','Quarta','Quinta','Sexta','Sábado','Domingo'];
const areas = {pessoal:'Pessoal',casa:'Casa',trabalho:'Trabalho'};
const S = {records:[],settings:{categories:[]},today:localDate(),day:localDate(),month:localDate().slice(0,7),week:weekStart(localDate()),view:'today',csrf:'',search:'',filter:'all',user:null,editing:null,archived:[],busy:false};
let toastTimer;
function toast(message) { $('#toast').textContent=message; $('#toast').hidden=false; clearTimeout(toastTimer); toastTimer=setTimeout(()=>$('#toast').hidden=true,4200); }
// Only visible while something is wrong or in flight; a click retries.
function status(state) { const el=$('#connection-status'); el.className=`connection-status ${state}`; el.hidden=!state; el.innerHTML=`<span></span>${({saving:'Salvando...',offline:'Sem conexão',error:'Falha na sincronização · tentar de novo'})[state] || 'Sincronizado'}`; }
function setMenu(open) { $('#shell').classList.toggle('menu-open',open); const more=$('[data-menu]'); if(more)more.setAttribute('aria-expanded',String(open)); }
async function api(action, body, params={}) {
  const url = new URL('api.php',location.href); url.searchParams.set('action',action);
  for (const [k,v] of Object.entries(params)) url.searchParams.set(k,v);
  let res;
  try { res=await fetch(url,{credentials:'same-origin',cache:'no-store',method:body===undefined?'GET':'POST',headers:body===undefined?{}:{'Content-Type':'application/json','X-CSRF-Token':S.csrf},body:body===undefined?undefined:JSON.stringify(body)}); }
  catch { status('offline'); throw new Error('Sem conexão. A alteração ainda não foi salva.'); }
  const data=await res.json().catch(()=>({error:'Resposta inesperada do servidor.'}));
  if(!res.ok || !data.ok) {
    if(res.status===401 && action!=='login') showLogin();
    throw new Error(data.error || 'Não foi possível concluir.');
  }
  return data.data;
}
function showLogin() { S.records=[]; S.user=null; $('#shell').hidden=true; $('#boot').hidden=true; $('#login').hidden=false; $('#main').replaceChildren(); icons(); }
async function refresh() {
  status('saving');
  try { const data=await api('bootstrap'); Object.assign(S,{records:data.records,settings:data.settings,user:data.user,today:data.today,csrf:data.csrf,mcpUrl:data.mcp_url}); $('#boot').hidden=true; $('#login').hidden=true; $('#shell').hidden=false; status(''); render(); }
  catch(e) { status(navigator.onLine?'error':'offline'); throw e; }
}
const records = kind => S.records.filter(r=>r.kind===kind);
const done = (r,day=S.day) => r.details.recurrence && r.details.recurrence!=='once' ? (r.details.completed_dates||[]).includes(day) : r.status==='done';
const due = (r,day=S.day) => {
  if(r.day && r.day>day) return false;
  const d=r.details;
  if(d.recurrence==='daily') return true;
  if(d.recurrence==='weekly') return (d.weekdays||[]).includes(weekday(day));
  if(d.recurrence==='monthly') return Number(day.slice(-2))===d.month_day;
  return !r.day || r.day===day || (r.kind==='task' && r.day<day && !done(r,day));
};
const match = r => `${r.title} ${r.details.notes||''} ${r.details.category||''}`.toLocaleLowerCase('pt-BR').includes(S.search.toLocaleLowerCase('pt-BR'));
const empty = (text,kind,area='') => `<div class="empty"><span>${icon(kinds[kind]?.[1]||'inbox')}</span><p>${esc(text)}</p>${kind?`<button class="text-button" data-new="${kind}" data-area="${area}">${icon('plus')}Adicionar ${esc(kinds[kind][0].toLowerCase())}</button>`:''}</div>`;
const actionButton = (name,label,attrs='') => `<button class="icon-button" aria-label="${esc(label)}" title="${esc(label)}" ${attrs}>${icon(name)}</button>`;
function row(r,opts={}) {
  const d=r.details; const checked=done(r,opts.day||S.day);
  const check=['task','habit','workout','event','goal'].includes(r.kind);
  const tag=r.kind==='task' ? ({pessoal:'Pessoal',casa:'Casa',trabalho:'Trabalho'}[d.area]||'Pessoal') : kinds[r.kind][0];
  const meta=[d.time,r.day && (opts.date||r.kind==='event')?dayLabel(r.day):'',tag,d.priority==='alta'?'Prioridade alta':''].filter(Boolean);
  return `<article class="record-row ${checked?'is-done':''}">${check?`<button class="check ${checked?'checked':''}" aria-label="${checked?'Desmarcar':'Concluir'} ${esc(r.title)}" aria-pressed="${checked}" data-check="${r.id}" data-day="${opts.day||r.day||S.day}">${checked?icon('check'):''}</button>`:`<span class="record-icon tone-${r.kind}">${icon(kinds[r.kind][1])}</span>`}<div class="record-copy"><button class="record-title" data-edit="${r.id}">${esc(r.title)}</button><p class="record-meta">${meta.map(esc).join(' · ')}</p>${opts.notes && d.notes?`<p class="record-note">${esc(d.notes)}</p>`:''}</div>${r.kind==='transaction'?`<strong class="money ${d.direction==='income'?'positive':''}">${d.direction==='income'?'+':'−'} ${currency(d.amount_cents)}</strong>`:''}${actionButton('ellipsis','Editar registro',`data-edit="${r.id}"`)}</article>`;
}
function section(title,content,view,kind) { return `<section class="section"><div class="section-heading"><h2>${title}</h2>${kind?`<button class="text-button" data-new="${kind}">${icon('plus')}Adicionar</button>`:view?`<button class="text-button" data-view="${view}">Ver tudo ${icon('arrow-up-right')}</button>`:''}</div>${content}</section>`; }
function header(title,sub,kind) { return `<div class="page-heading"><div><p class="eyebrow">${esc(sub)}</p><h1>${title}</h1></div>${kind?`<button class="primary" data-new="${kind}">${icon('plus')}Adicionar ${kinds[kind][0].toLowerCase()}</button>`:''}</div>`; }
function financial(month=S.month) {
  const tx=records('transaction'); let balance=S.settings.initial_balance_cents||0,income=0,expense=0;
  tx.forEach(r=>{const d=r.details,sign=d.direction==='income'?1:-1;if(r.day.slice(0,7)<=month)balance+=sign*d.amount_cents;if(r.day.startsWith(month)){if(sign===1)income+=d.amount_cents;else expense+=d.amount_cents;}});
  return {balance,income,expense,tx:tx.filter(r=>r.day.startsWith(month))};
}
function dayPicker() { return `<div class="date-control">${actionButton('chevron-left','Dia anterior','data-shift="-1"')}<input type="date" id="day-select" aria-label="Dia selecionado" value="${S.day}">${actionButton('chevron-right','Próximo dia','data-shift="1"')}<button class="text-button" data-today>Hoje</button></div>`; }
function dashboard() {
  const tasks=records('task').filter(r=>due(r,S.today));
  const habits=records('habit').filter(r=>due(r,S.today));
  const workouts=records('workout').filter(r=>due(r,S.today));
  const daily=[...tasks,...habits,...workouts];
  const completed=daily.filter(r=>done(r,S.today)).length;
  const events=records('event').filter(r=>r.day>=S.today && r.status!=='done').sort((a,b)=>(a.day+(a.details.time||'')).localeCompare(b.day+(b.details.time||''))).slice(0,5);
  const finance=financial(S.today.slice(0,7));
  const house=tasks.filter(r=>r.details.area==='casa');
  const focus=tasks.filter(r=>r.details.area!=='casa').sort((a,b)=>Number(done(a,S.today))-Number(done(b,S.today)) || Number(b.details.priority==='alta')-Number(a.details.priority==='alta'));
  const meals=records('meal').filter(r=>r.day===S.today);
  const pct=daily.length?Math.round(completed/daily.length*100):0;
  return `${header('Hoje, Marcos',longDate(S.today))}<div class="overview-band"><div><span class="stat-label">Hoje</span><strong>${daily.length-completed}<small> pendências</small></strong></div><div><span class="stat-label">Compromissos</span><strong>${events.filter(r=>r.day===S.today).length}<small> hoje</small></strong></div><div class="daily-progress"><span class="stat-label">Seu ritmo</span><div><strong>${completed}<small> / ${daily.length} concluídos</small></strong><span>${pct}%</span></div><progress value="${completed}" max="${Math.max(1,daily.length)}"></progress></div></div><div class="dashboard-grid"><div>${section('Prioridades do dia',focus.length?focus.map(r=>row(r,{day:S.today})).join(''):empty('Tudo em dia por aqui.','task'),'tasks','task')}${section('Próximos compromissos',events.length?events.map(r=>row(r,{day:S.today,date:true})).join(''):empty('Nenhum compromisso agendado.','event'),'calendar')}${section('Casa em ordem',house.length?house.map(r=>row(r,{day:S.today})).join(''):empty('Nenhuma tarefa da casa para hoje.','task','casa'),'tasks')}${section('Hábitos',habits.length?habits.map(r=>row(r,{day:S.today})).join(''):empty('Comece com um hábito que cabe no seu dia.','habit'),'habits')}</div><aside class="day-rail">${section('Finanças do mês',`<div class="balance"><span class="stat-label">Saldo acumulado</span><strong>${currency(finance.balance)}</strong><dl><div><dt>${icon('arrow-down-left')}Entradas</dt><dd class="positive">${currency(finance.income)}</dd></div><div><dt>${icon('arrow-up-right')}Saídas</dt><dd>${currency(finance.expense)}</dd></div></dl><button class="secondary full" data-new="transaction">${icon('plus')}Registrar lançamento</button></div>`,'finance')}${section('Movimento e recuperação',workouts.length?workouts.map(r=>row(r,{day:S.today})).join(''):empty('Nenhuma atividade planejada para hoje.','workout'),'workouts')}${section('Alimentação de hoje',meals.length?meals.map(r=>`<button class="meal-summary" data-edit="${r.id}"><span>${mealNames[r.details.meal]}</span><strong>${esc(r.title)}</strong></button>`).join(''):empty('O que você comeu hoje?','meal'),'meals')}${section('Uma nota para hoje',`<form id="quick-note"><textarea name="note" rows="3" maxlength="12000" aria-label="Nota do dia" placeholder="O que merece ficar registrado?"></textarea><button class="text-button" type="submit">${icon('plus')}Guardar nota</button></form>`)}</aside></div>`;
}
function toolbar(filters,search=true) { return `<div class="list-toolbar">${filters.length?`<div class="segments">${filters.map(([v,l])=>`<button class="${S.filter===v?'active':''}" data-filter="${v}">${l}</button>`).join('')}</div>`:''}${search?`<label class="search">${icon('search')}<input id="search" type="search" placeholder="Buscar registros" value="${esc(S.search)}" aria-label="Buscar registros"></label>`:''}</div>`; }
// Each task sits in exactly one weekday column; undated and overdue ones wait in "Sem dia".
function onBoardDay(r,day) {
  const d=r.details;
  if(d.recurrence==='daily') return true;
  if(d.recurrence==='weekly') return (d.weekdays||[]).includes(weekday(day));
  if(d.recurrence==='monthly') return Number(day.slice(-2))===d.month_day;
  return r.day===day;
}
const unplaced = r => (r.details.recurrence||'once')==='once' && (!r.day || (r.day<S.week && !done(r,r.day)));
function boardTask(r,day) {
  const d=r.details,rec=d.recurrence||'once',when=day||r.day||S.today,checked=done(r,when);
  const tags=[d.time,areas[d.area]||'Pessoal'];
  if(rec==='daily')tags.push('Todo dia');
  if(rec==='weekly')tags.push('Toda semana');
  if(rec==='monthly')tags.push(`Todo dia ${d.month_day}`);
  if(d.priority==='alta')tags.push('Prioridade alta');
  if(!day&&r.day)tags.push(`Atrasada · ${dayLabel(r.day)}`);
  return `<article class="board-task ${checked?'is-done':''}"><button class="check ${checked?'checked':''}" aria-label="${checked?'Desmarcar':'Concluir'} ${esc(r.title)}" aria-pressed="${checked}" data-check="${r.id}" data-day="${when}">${checked?icon('check'):''}</button><div class="board-copy"><button class="record-title" data-edit="${r.id}">${esc(r.title)}</button><p class="record-meta">${tags.filter(Boolean).map(esc).join(' · ')}</p></div>${rec==='daily'?'':`<button class="grip" data-move="${r.id}" aria-label="Mover ${esc(r.title)} de dia" title="Mover de dia">${icon('grip-vertical')}</button>`}</article>`;
}
function dayColumn(title,sub,day,items,extra='') {
  return `<section class="day-card ${day===S.today?'is-today':''} ${extra}" data-drop="${day}"><header><h2>${esc(title)}</h2><small>${esc(sub)}</small></header><div class="day-tasks">${items.map(r=>boardTask(r,day)).join('')}</div><button class="text-button" data-new="task" data-day="${day}" data-area="${S.filter==='all'?'':S.filter}">${icon('plus')}Adicionar</button></section>`;
}
function tasksView() {
  const start=S.week,cols=Array.from({length:7},(_,i)=>shift(start,i));
  const all=records('task').filter(match).filter(r=>S.filter==='all'||r.details.area===S.filter);
  const sort=(list,day)=>list.sort((a,b)=>Number(done(a,day||a.day||S.today))-Number(done(b,day||b.day||S.today))||Number(b.details.priority==='alta')-Number(a.details.priority==='alta')||(a.details.time||'~').localeCompare(b.details.time||'~'));
  const board=cols.map(day=>dayColumn(dayNames[weekday(day)-1],dayLabel(day),day,sort(all.filter(r=>onBoardDay(r,day)),day))).join('');
  return `${header('Tarefas','SUA SEMANA, DIA A DIA','task')}<div class="week-control">${actionButton('chevron-left','Semana anterior','data-week-shift="-1"')}<span>${dayLabel(start)} a ${dayLabel(shift(start,6))}</span>${actionButton('chevron-right','Próxima semana','data-week-shift="1"')}<button class="text-button" data-this-week>Esta semana</button></div>${toolbar([['all','Todas'],['pessoal','Pessoal'],['casa','Casa'],['trabalho','Trabalho']])}<div class="board" id="board">${board}${dayColumn('Sem dia','para encaixar','',sort(all.filter(unplaced)),'loose-card')}</div>`;
}
function habitsView() {
  const week=Array.from({length:7},(_,i)=>shift(S.day,i-6));
  const items=records('habit').filter(match);
  return `${header('Hábitos','CONSISTÊNCIA NO SEU RITMO','habit')}${dayPicker()}${toolbar([])}<div class="habit-table"><div class="habit-table-head"><span>Hábito</span><div class="habit-days">${week.map(d=>`<span>${days[weekday(d)-1]}<small>${d.slice(-2)}</small></span>`).join('')}</div><span></span></div>${items.length?items.map(r=>`<article class="habit-table-row"><div><button class="record-title" data-edit="${r.id}">${esc(r.title)}</button><p class="record-meta">${r.details.recurrence==='daily'?'Diário':r.details.recurrence==='weekly'?(r.details.weekdays||[]).map(i=>days[i-1]).join(', '):dayLabel(r.day)}</p></div><div class="habit-days">${week.map(d=>`<button class="habit-cell ${done(r,d)?'checked':''}" data-check="${r.id}" data-day="${d}" aria-label="${esc(r.title)} em ${dayLabel(d)}" aria-pressed="${done(r,d)}" ${!due(r,d)?'disabled':''}>${done(r,d)?icon('check'):'<span></span>'}</button>`).join('')}</div>${actionButton('pencil','Editar hábito',`data-edit="${r.id}"`)}</article>`).join(''):empty('Comece por um hábito que cabe no seu dia.','habit')}</div>`;
}
function calendarView() {
  const first=`${S.month}-01`,start=shift(first,-(weekday(first)-1));
  const cells=Array.from({length:42},(_,i)=>shift(start,i));
  const events=records('event'); const todayEvents=events.filter(r=>r.day===S.day).sort((a,b)=>(a.details.time||'').localeCompare(b.details.time||''));
  return `${header('Agenda','ESPAÇO PARA O QUE IMPORTA','event')}<div class="calendar-layout"><section><div class="calendar-toolbar">${actionButton('chevron-left','Mês anterior','data-month-shift="-1"')}<h2>${new Intl.DateTimeFormat('pt-BR',{month:'long',year:'numeric'}).format(dateObject(first))}</h2>${actionButton('chevron-right','Próximo mês','data-month-shift="1"')}</div><div class="calendar-weekdays">${days.map(d=>`<span>${d}</span>`).join('')}</div><div class="calendar-grid">${cells.map(d=>`<button class="calendar-cell ${d===S.day?'selected':''} ${d===S.today?'is-today':''} ${!d.startsWith(S.month)?'other-month':''}" data-select-day="${d}" aria-label="${longDate(d)}"><span>${Number(d.slice(-2))}</span>${events.filter(r=>r.day===d).length?`<small>${events.filter(r=>r.day===d).length} evento${events.filter(r=>r.day===d).length>1?'s':''}</small>`:''}</button>`).join('')}</div><button class="text-button export-calendar" data-export-calendar>${icon('download')}Exportar agenda</button></section><section class="agenda-day"><div class="section-heading"><h2>${longDate(S.day)}</h2></div>${todayEvents.length?todayEvents.map(r=>row(r,{notes:true})).join(''):empty('Dia livre na agenda.','event')}</section></div>`;
}
function financeView() {
  const f=financial(); let items=f.tx.filter(match);
  if(S.filter!=='all')items=items.filter(r=>r.details.direction===S.filter);
  const categories={}; f.tx.filter(r=>r.details.direction==='expense').forEach(r=>categories[r.details.category]=(categories[r.details.category]||0)+r.details.amount_cents);
  return `${header('Finanças','SEU DINHEIRO, COM CLAREZA','transaction')}<div class="month-control">${actionButton('chevron-left','Mês anterior','data-month-shift="-1"')}<input type="month" id="month-select" value="${S.month}" aria-label="Mês dos lançamentos">${actionButton('chevron-right','Próximo mês','data-month-shift="1"')}</div><div class="overview-band finance-stats"><div><span class="stat-label">Saldo acumulado</span><strong>${currency(f.balance)}</strong></div><div><span class="stat-label">Entradas no mês</span><strong class="positive">${currency(f.income)}</strong></div><div><span class="stat-label">Saídas no mês</span><strong>${currency(f.expense)}</strong></div></div><div class="finance-layout"><section>${toolbar([['all','Todos'],['income','Entradas'],['expense','Saídas']])}<div class="table-scroll"><table class="transactions"><thead><tr><th>Descrição</th><th>Categoria</th><th class="align-right">Valor</th><th></th></tr></thead><tbody>${items.map(r=>`<tr><td><button class="record-title" data-edit="${r.id}">${esc(r.title)}</button></td><td><span class="tag">${esc(r.details.category)}</span></td><td class="align-right money ${r.details.direction==='income'?'positive':''}">${r.details.direction==='income'?'+':'−'} ${currency(r.details.amount_cents)}</td><td>${actionButton('pencil','Editar lançamento',`data-edit="${r.id}"`)}</td></tr>`).join('')}</tbody></table></div>${!items.length?empty('Nenhum lançamento neste período.','transaction'):''}<button class="text-button" data-export-csv>${icon('download')}Exportar CSV</button></section><aside class="category-summary"><h2>Saídas por categoria</h2>${Object.entries(categories).sort((a,b)=>b[1]-a[1]).map(([c,v])=>`<div class="category-line"><div><span>${esc(c)}</span><strong>${currency(v)}</strong></div><progress value="${v}" max="${Math.max(1,f.expense)}"></progress></div>`).join('')||'<p class="muted">Nenhuma saída neste mês.</p>'}</aside></div>`;
}
function workoutsView() {
  const list=records('workout').filter(match).filter(r=>S.filter==='all'||due(r));
  return `${header('Treinos e movimento','ATIVIDADE, RECUPERAÇÃO E CONTINUIDADE','workout')}${dayPicker()}${toolbar([['today','No dia'],['all','Todos']])}<div class="record-list">${list.length?list.map(r=>`<div class="workout-line">${row(r,{date:true,notes:true})}<div class="workout-meta"><span>${activities[r.details.activity]}</span>${r.details.duration_min?`<span>${r.details.duration_min} min</span>`:''}<span>${r.details.recurrence==='weekly'?(r.details.weekdays||[]).map(i=>days[i-1]).join(', '):r.details.recurrence==='daily'?'Diário':'Sessão única'}</span></div></div>`).join(''):empty('Nenhum treino neste dia.','workout')}</div>`;
}
function mealsView() {
  const meals=records('meal').filter(r=>r.day===S.day);
  return `${header('Alimentação','REGISTROS E OBSERVAÇÕES DO DIA','meal')}${dayPicker()}<div class="meal-list">${Object.entries(mealNames).map(([key,label])=>{const items=meals.filter(r=>r.details.meal===key);return key==='outro'&&!items.length?'':`<section class="meal-section"><div class="section-heading"><h2>${label}</h2><button class="text-button" data-new="meal" data-meal="${key}">${icon('plus')}Registrar</button></div>${items.map(r=>`<article class="meal-record"><div><button class="record-title" data-edit="${r.id}">${esc(r.title)}</button><span class="record-meta">${esc(r.details.time||'')}</span></div>${r.details.notes?`<p class="preserve">${esc(r.details.notes)}</p>`:''}${r.details.reflection?`<div class="reflection"><span>${icon('message-circle')}Observações</span><p class="preserve">${esc(r.details.reflection)}</p></div>`:''}${actionButton('pencil','Editar refeição',`data-edit="${r.id}"`)}</article>`).join('')||'<p class="muted meal-empty">Ainda sem registro.</p>'}</section>`;}).join('')}</div>`;
}
function notesView() {
  const notes=records('note').filter(match),goals=records('goal').filter(match);
  return `${header('Notas e metas','O QUE VOCÊ QUER LEMBRAR E CONSTRUIR','note')}${toolbar([['all','Tudo'],['notes','Notas'],['goals','Metas']])}${S.filter!=='notes'?section('Metas',`<div class="goal-grid">${goals.map(r=>{const ok=done(r,r.day||S.today);return `<article class="goal-item ${ok?'is-done':''}"><div class="goal-head"><button class="check ${ok?'checked':''}" aria-label="${ok?'Reabrir':'Concluir'} ${esc(r.title)}" aria-pressed="${ok}" data-check="${r.id}" data-day="${r.day||S.today}">${ok?icon('check'):''}</button><button class="record-title" data-edit="${r.id}">${esc(r.title)}</button></div>${r.details.notes?`<p>${esc(r.details.notes)}</p>`:''}<p class="record-meta">${r.day?`Até ${dayLabel(r.day)}`:'Sem prazo'}${ok?' · Concluída':''}</p></article>`}).join('')||empty('Uma meta por vez.','goal')}</div>`,null,'goal'):''}${S.filter!=='goals'?section('Notas',notes.map(r=>`<article class="note-item"><div><button class="record-title" data-edit="${r.id}">${esc(r.title)}</button><span class="record-meta">${dayLabel(r.day)}</span></div><p class="preserve">${esc(r.details.notes)}</p></article>`).join('')||empty('Nenhuma nota registrada.','note')):''}`;
}
function historyView() {
  const items=S.records.filter(r=>r.day===S.day || (r.details.completed_dates||[]).includes(S.day)).filter(match);
  return `${header('Seu histórico','O QUE FICOU REGISTRADO')}${dayPicker()}${toolbar([])}<div class="record-list">${items.length?items.map(r=>row(r,{notes:true})).join(''):empty('Nenhum registro para este dia.')}</div>`;
}
function settingsView() {
  const m=S.settings.migration;
  return `${header('Ajustes e conexões','SEU ESPAÇO, DO SEU JEITO')}<div class="settings-layout"><section class="section"><h2>Conta</h2><dl class="settings-dl"><div><dt>Nome</dt><dd>${esc(S.user.name)}</dd></div><div><dt>E-mail</dt><dd>${esc(S.user.email)}</dd></div><div><dt>Fuso horário</dt><dd>America/Sao_Paulo</dd></div></dl><h2>Finanças</h2><form id="settings-form"><label>Saldo inicial (R$)<input name="balance" inputmode="decimal" value="${((S.settings.initial_balance_cents||0)/100).toFixed(2).replace('.',',')}" required></label><label>Categorias, uma por linha<textarea name="categories" rows="6" required>${esc(S.settings.categories.join('\n'))}</textarea></label><button class="primary" type="submit">${icon('check')}Salvar ajustes</button></form></section><section class="section"><h2>Conexão com o assistente</h2><label>Endereço MCP<input readonly value="${esc(S.mcpUrl)}" aria-label="Endereço MCP"></label><button class="text-button" data-copy-mcp>${icon('copy')}Copiar endereço</button><div id="connections"><p class="muted">Carregando conexões...</p></div><h2 class="spaced">Seus dados</h2>${m?`<p class="migration-status">${icon('check-circle-2')}Importação registrada em ${dayLabel(m.imported_at.slice(0,10))}</p><p class="muted">Período: ${dayLabel(m.from)} a ${dayLabel(m.to)} · ${m.new_records} novos lançamentos na última importação.</p>`:'<p class="muted">Nenhuma importação do app anterior registrada.</p>'}<div class="settings-actions"><button class="secondary" data-backup>${icon('download')}Exportar meus dados</button><button class="secondary" data-migrate>${icon('database')}Importar do app anterior</button><label class="secondary file-label">${icon('upload')}Importar arquivo<input type="file" id="import-file" accept="application/json,.json"></label><button class="text-button" data-archives>${icon('archive')}Registros arquivados</button></div><div id="archives"></div><h2 class="spaced">Integrações</h2><div class="integration-row">${icon('calendar-days')}<span>Google Agenda</span><span class="tag">Não conectado</span></div><div class="integration-row">${icon('smartphone')}<span>App no celular</span><span class="tag">PWA</span></div></section></div>`;
}
const renderers={today:dashboard,tasks:tasksView,calendar:calendarView,habits:habitsView,finance:financeView,workouts:workoutsView,meals:mealsView,notes:notesView,history:historyView,settings:settingsView};
function render() {
  if(!S.user)return;
  document.title=`${views[S.view][0]} · Controle Vida`;
  $('#navigation').innerHTML=Object.entries(views).filter(([k])=>k!=='settings').map(([key,[name,i]])=>`<button class="nav-item ${key===S.view?'active':''}" data-view="${key}" ${key===S.view?'aria-current="page"':''}>${icon(i)}<span>${name}</span></button>`).join('');
  $('#bottom-nav').innerHTML=['today','tasks','finance','habits'].map(key=>`<button class="bottom-item ${key===S.view?'active':''}" data-view="${key}" ${key===S.view?'aria-current="page"':''}>${icon(views[key][1])}<span>${views[key][0]}</span></button>`).join('')+`<button class="bottom-item ${['today','tasks','finance','habits'].includes(S.view)?'':'active'}" data-menu aria-label="Mais áreas">${icon('menu')}<span>Mais</span></button>`;
  $('#main').innerHTML=renderers[S.view]();
  $('#footer-date').textContent=longDate(S.today);
  icons();
  if(S.scrollBoard)showToday();
  if(S.view==='settings')loadConnections();
}
function navigate(view) { if(!views[view])view='today'; S.view=view; S.search='';S.filter=view==='workouts'?'today':'all';if(view!=='calendar')S.day=S.today; if(!['finance','calendar'].includes(view))S.month=S.today.slice(0,7); S.week=weekStart(S.today); S.scrollBoard=true; location.hash=view; setMenu(false);render(); }
const input = (name,label,type,value='',extra='') => `<label>${label}<input name="${name}" type="${type}" value="${esc(value)}" ${extra}></label>`;
const select = (name,label,options,value) => `<label>${label}<select name="${name}">${Object.entries(options).map(([v,l])=>`<option value="${v}" ${v===value?'selected':''}>${l}</option>`).join('')}</select></label>`;
function openEditor(kind,id,defaults={}) {
  const r=id?S.records.find(x=>x.id===id):null,d=r?.details||{};
  S.editing={kind,id,revision:r?.revision};
  $('#editor-title').textContent=`${r?'Editar':['task','meal','note','goal'].includes(kind)?'Nova':'Novo'} ${kinds[kind][0].toLowerCase()}`;
  let fields=input('title',kind==='meal'?'O que você comeu?':kind==='transaction'?'Descrição':'Título','text',r?.title||'',kind==='transaction'?'maxlength="200" autofocus placeholder="Opcional: sem descrição, usamos a categoria"':'required maxlength="200" autofocus');
  if(kind==='transaction') fields+=`<label>Mês<span class="month-field"><input name="month" type="month" value="${esc(r?r.day.slice(0,7):(defaults.month||S.month))}" required><button type="button" class="secondary compact" data-previous-month>${icon('chevron-left')}Mês anterior</button></span></label>`;
  else {
    fields+=`<div class="form-grid">${input('day',kind==='goal'?'Prazo':'Data','date',r?.day??defaults.day??S.day,['task','note','goal','habit','workout'].includes(kind)?'':'required')}`;
    if(['meal','event','workout','habit','task'].includes(kind))fields+=input('time','Horário','time',d.time||'');
    fields+='</div>';
  }
  if(kind==='transaction') fields+=`<div class="form-grid">${select('direction','Tipo',{expense:'Saída',income:'Entrada'},d.direction||'expense')}${input('amount','Valor (R$)','text',d.amount_cents?(d.amount_cents/100).toFixed(2).replace('.',','):'','required inputmode="decimal"')}</div><label>Categoria<input name="category" list="categories" value="${esc(d.category||'Outros')}" maxlength="80" required><datalist id="categories">${S.settings.categories.map(c=>`<option value="${esc(c)}">`).join('')}</datalist></label>`;
  if(kind==='task')fields+=`<div class="form-grid">${select('area','Área',{pessoal:'Pessoal',casa:'Casa',trabalho:'Trabalho'},d.area||defaults.area||'pessoal')}${select('priority','Prioridade',{normal:'Normal',alta:'Alta',baixa:'Baixa'},d.priority||'normal')}</div>`;
  if(['task','habit','workout'].includes(kind)) fields+=`${select('recurrence','Repetição',{once:'Uma vez',daily:'Todos os dias',weekly:'Dias da semana',monthly:'Mensal'},d.recurrence||(kind==='habit'?'daily':'once'))}<fieldset class="weekday-options" ${d.recurrence==='weekly'?'':'hidden'}><legend>Dias da semana</legend>${days.map((name,i)=>`<label><input type="checkbox" name="weekdays" value="${i+1}" ${(d.weekdays||[]).includes(i+1)?'checked':''}><span>${name}</span></label>`).join('')}</fieldset><div id="monthly-option" ${d.recurrence==='monthly'?'':'hidden'}>${input('month_day','Dia do mês','number',d.month_day||1,'min="1" max="31"')}</div>`;
  if(kind==='event')fields+=`<div class="form-grid">${input('end_time','Termina às','time',d.end_time||'')}${input('location','Local','text',d.location||'','maxlength="200"')}</div>`;
  if(kind==='workout')fields+=`<div class="form-grid">${select('activity','Atividade',activities,d.activity||'caminhada')}${input('duration_min','Duração (min)','number',d.duration_min||'','min="0" max="1440"')}</div>`;
  if(kind==='meal')fields+=select('meal','Refeição',mealNames,d.meal||defaults.meal||'almoco');
  fields+=`<label>${kind==='note'?'Anotação':'Observações'}<textarea name="notes" rows="4" maxlength="12000">${esc(d.notes||'')}</textarea></label>`;
  if(kind==='meal')fields+=`<label>Reflexão sobre a refeição<textarea name="reflection" rows="3" maxlength="8000">${esc(d.reflection||'')}</textarea></label>`;
  if(r)fields+=`<button type="button" class="text-button danger" data-archive="${r.id}">${icon('archive')}Arquivar registro</button>`;
  $('#editor-fields').innerHTML=fields;$('#form-error').textContent='';$('#quick-menu').close();$('#editor').showModal();icons();
}
function cents(value) {
  const normalized=String(value).trim().replace(/\s/g,'').replace(',','.');
  if(!/^-?\d+(\.\d{1,2})?$/.test(normalized))throw new Error('Use um valor como 25,90, sem separador de milhar.');
  const negative=normalized.startsWith('-'),[whole,fraction='']=normalized.replace('-','').split('.');
  const amount=Number(whole)*100+Number(fraction.padEnd(2,'0'));
  if(!Number.isSafeInteger(amount))throw new Error('Valor muito alto.');
  return negative?-amount:amount;
}
async function saveEditor(event) {
  event.preventDefault();const form=event.target,button=$('[type=submit]',form);button.disabled=true;$('#form-error').textContent='';
  try {
    const fd=new FormData(form),details=Object.fromEntries(fd);delete details.title;delete details.day;delete details.month;delete details.amount;
    if(S.editing.kind==='transaction')details.amount_cents=cents(fd.get('amount'));
    if(['task','habit','workout'].includes(S.editing.kind))details.weekdays=fd.getAll('weekdays').map(Number);
    if('duration_min' in details)details.duration_min=Number(details.duration_min);
    if('month_day' in details)details.month_day=Number(details.month_day);
    const payload={kind:S.editing.kind,title:fd.get('title'),day:S.editing.kind==='transaction'?`${fd.get('month')}-01`:fd.get('day'),details};
    if(S.editing.id){payload.id=S.editing.id;payload.revision=S.editing.revision;}
    status('saving');const saved=await api('save',payload);S.records=S.records.filter(r=>r.id!==saved.id);S.records.push(saved);$('#editor').close();status('');render();toast('Registro salvo.');
  } catch(e){$('#form-error').textContent=e.message;status('error');}finally{button.disabled=false;}
}
async function mark(id,day,button) {
  const r=S.records.find(r=>r.id===id);if(!r)return;button.disabled=true;status('saving');
  try { const saved=await api('mark',{id,revision:r.revision,day,done:!done(r,day)});S.records=S.records.map(x=>x.id===id?saved:x);status('');render(); }
  catch(e){toast(e.message);status('error');button.disabled=false;}
}
function importSummary(finance,report) { const sum=o=>Object.values(o||{}).reduce((a,b)=>a+(Array.isArray(b)?b.length:b),0); const created=sum(report?.created),invalid=sum(report?.invalid); return finance+created===0?'Nada novo para importar: tudo já estava aqui.':`Importação concluída: ${finance} lançamentos e ${created} outros registros.${invalid?` ${invalid} com dados inválidos ficaram de fora.`:''}`; }
function showToday() {
  S.scrollBoard=false;
  const board=$('#board'),col=$(`[data-drop="${S.today}"]`);
  if(board&&col&&board.scrollWidth>board.clientWidth+4)board.scrollLeft+=col.getBoundingClientRect().left-board.getBoundingClientRect().left-10;
}
// Dropping on a column moves a one-off task's date, or the weekday/month day of a repeating one.
async function moveTask(id,day) {
  const r=S.records.find(x=>x.id===id); if(!r)return;
  const rec=r.details.recurrence||'once',details={...r.details};
  let target=r.day;
  if(day===''){ if(rec!=='once')return toast('Tarefas que se repetem ficam nos dias da semana.'); if(!r.day)return; target=''; }
  else if(rec==='weekly'){ if((details.weekdays||[]).join()===String(weekday(day)))return; details.weekdays=[weekday(day)]; }
  else if(rec==='monthly'){ if(details.month_day===Number(day.slice(-2)))return; details.month_day=Number(day.slice(-2)); }
  else { if(r.day===day)return; target=day; }
  status('saving');
  try { const saved=await api('save',{id:r.id,revision:r.revision,kind:'task',title:r.title,day:target,details});
    S.records=S.records.map(x=>x.id===id?saved:x); status(''); render(); toast('Tarefa movida.'); }
  catch(e){ status('error'); toast(e.message); }
}
function openMoveMenu(id) {
  const r=S.records.find(x=>x.id===id); if(!r)return;
  const cols=Array.from({length:7},(_,i)=>shift(S.week,i));
  $('#quick-menu h2').textContent='Mover para';
  $('#quick-options').innerHTML=cols.map(d=>`<button data-move-to="${d}" data-move-id="${id}">${icon('calendar-days')}${dayNames[weekday(d)-1]} · ${dayLabel(d)}${icon('chevron-right')}</button>`).join('')+((r.details.recurrence||'once')==='once'?`<button data-move-to="" data-move-id="${id}">${icon('inbox')}Sem dia${icon('chevron-right')}</button>`:'');
  $('#quick-menu').showModal(); icons();
}
// Dragging by the grip works with mouse and touch; a tap on it opens the same choice as a list.
let drag=null;
function dragStart(event) {
  const grip=event.target.closest('[data-move]');
  if(!grip||event.button)return;
  drag={id:grip.dataset.move,article:grip.closest('.board-task'),x:event.clientX,y:event.clientY,moved:false};
  try{ grip.setPointerCapture(event.pointerId); }catch{}
}
function dragMove(event) {
  if(!drag)return;
  if(!drag.moved) {
    if(Math.hypot(event.clientX-drag.x,event.clientY-drag.y)<6)return;
    const box=drag.article.getBoundingClientRect();
    drag.moved=true; drag.offsetX=drag.x-box.left; drag.offsetY=drag.y-box.top;
    drag.article.classList.add('dragging');
    drag.ghost=document.createElement('div');
    drag.ghost.className='drag-ghost'; drag.ghost.textContent=drag.article.querySelector('.record-title').textContent;
    document.body.append(drag.ghost);
  }
  drag.ghost.style.transform=`translate(${event.clientX-drag.offsetX}px,${event.clientY-drag.offsetY}px)`;
  const board=$('#board');
  if(board&&board.scrollWidth>board.clientWidth+4){ const b=board.getBoundingClientRect();
    if(event.clientX>b.right-56)board.scrollLeft+=16; else if(event.clientX<b.left+56)board.scrollLeft-=16; }
  if(event.clientY>innerHeight-90)scrollBy(0,18); else if(event.clientY<90)scrollBy(0,-18);
  const over=document.elementFromPoint(event.clientX,event.clientY)?.closest('[data-drop]');
  $$('.day-card.over').forEach(c=>c.classList.remove('over'));
  if(over)over.classList.add('over');
  drag.target=over;
}
function dragEnd(cancel) {
  if(!drag)return;
  const d=drag; drag=null;
  d.ghost?.remove(); d.article.classList.remove('dragging');
  $$('.day-card.over').forEach(c=>c.classList.remove('over'));
  if(cancel)return;
  if(!d.moved)openMoveMenu(d.id);
  else if(d.target)moveTask(d.id,d.target.dataset.drop);
}
document.addEventListener('pointerdown',dragStart);
document.addEventListener('pointermove',dragMove);
document.addEventListener('pointerup',()=>dragEnd(false));
document.addEventListener('pointercancel',()=>dragEnd(true));
function download(content,name,type) { const url=URL.createObjectURL(new Blob([content],{type}));const a=document.createElement('a');a.href=url;a.download=name;a.click();setTimeout(()=>URL.revokeObjectURL(url),2000); }
async function loadConnections() {
  try { const conns=await api('connections');if(!$('#connections'))return;$('#connections').innerHTML=conns.length?conns.map(c=>`<div class="integration-row">${icon('plug')}<span>${esc(c.name)}<small>${c.scope.includes('write')?'Leitura e escrita':'Somente leitura'}</small></span><button class="text-button danger" data-revoke="${c.id}">Revogar</button></div>`).join(''):'<p class="muted">Nenhum assistente conectado.</p>';icons(); } catch(e){if($('#connections'))$('#connections').textContent=e.message;}
}
document.addEventListener('click',async event=>{
 const b=event.target.closest('button,a'); if(!b)return;
 try {
  if(b.dataset.view)navigate(b.dataset.view);
  if(b.hasAttribute('data-menu'))setMenu(!$('#shell').classList.contains('menu-open'));
  if(b.dataset.new)openEditor(b.dataset.new,null,{meal:b.dataset.meal,area:b.dataset.area,day:b.dataset.day});
  if(b.dataset.edit){const r=S.records.find(r=>r.id===b.dataset.edit);if(r)openEditor(r.kind,r.id);}
  if(b.dataset.check)await mark(b.dataset.check,b.dataset.day||S.day,b);
  if(b.classList.contains('close-dialog'))b.closest('dialog').close();
  if(b.dataset.filter){S.filter=b.dataset.filter;render();}
  if(b.hasAttribute('data-shift')){S.day=shift(S.day,Number(b.dataset.shift));render();}
  if(b.hasAttribute('data-today')){S.day=S.today;render();}
  if(b.hasAttribute('data-week-shift')){S.week=shift(S.week,7*Number(b.dataset.weekShift));render();}
  if(b.hasAttribute('data-this-week')){S.week=weekStart(S.today);S.scrollBoard=true;render();}
  if(b.dataset.moveId){$('#quick-menu').close();await moveTask(b.dataset.moveId,b.dataset.moveTo);}
  if(b.hasAttribute('data-previous-month')){const m=$('[name=month]',$('#editor'));m.value=shiftMonth(m.value||S.month,-1);}
  if(b.dataset.selectDay){S.day=b.dataset.selectDay;render();}
  if(b.hasAttribute('data-month-shift')){const d=dateObject(`${S.month}-01`);d.setMonth(d.getMonth()+Number(b.dataset.monthShift));S.month=`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;render();}
  if(b.dataset.archive){const r=S.records.find(r=>r.id===b.dataset.archive);await api('archive',{id:r.id,revision:r.revision,archived:true});S.records=S.records.filter(x=>x.id!==r.id);$('#editor').close();render();toast('Registro arquivado. Você pode restaurá-lo nos ajustes.');}
  if(b.hasAttribute('data-copy-mcp')){await navigator.clipboard.writeText(S.mcpUrl);toast('Endereço copiado.');}
  if(b.hasAttribute('data-backup')){download(JSON.stringify(await api('export'),null,2),`controlevida-${S.today}.json`,'application/json');}
  if(b.hasAttribute('data-migrate')){b.disabled=true;try{const r=await api('migrate',{});await refresh();toast(importSummary(r.finance.new_records,r));}finally{b.disabled=false;}}
  if(b.dataset.revoke){await api('revoke',{id:b.dataset.revoke});await loadConnections();toast('Conexão revogada.');}
  if(b.hasAttribute('data-archives')){S.archived=(await api('list',undefined,{archived:1})).filter(r=>r.status==='archived');$('#archives').innerHTML=S.archived.length?S.archived.map(r=>`<div class="integration-row"><span>${esc(r.title)}</span><button class="text-button" data-restore="${r.id}">Restaurar</button></div>`).join(''):'<p class="muted">Nenhum registro arquivado.</p>';}
  if(b.dataset.restore){const r=S.archived.find(r=>r.id===b.dataset.restore);await api('archive',{id:r.id,revision:r.revision,archived:false});await refresh();toast('Registro restaurado.');}
  if(b.hasAttribute('data-export-csv')){const cell=v=>`"${String(v).replace(/^[=+@\-]/,"'$&").replace(/"/g,'""')}"`;const rows=[['Mês','Descrição','Tipo','Categoria','Valor'],...financial().tx.map(r=>[r.day.slice(0,7),r.title,r.details.direction==='income'?'Entrada':'Saída',r.details.category,(r.details.amount_cents/100).toFixed(2).replace('.',',')])];download('\ufeff'+rows.map(r=>r.map(cell).join(';')).join('\r\n'),`financas-${S.month}.csv`,'text/csv;charset=utf-8');}
  if(b.hasAttribute('data-export-calendar')){
    const safe=s=>String(s).replace(/\\/g,'\\\\').replace(/\n/g,'\\n').replace(/,/g,'\\,').replace(/;/g,'\\;');
    const lines=['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//Controle Vida//Agenda//PT-BR','CALSCALE:GREGORIAN'];
    records('event').forEach(r=>{const date=r.day.replaceAll('-',''),time=r.details.time;lines.push('BEGIN:VEVENT',`UID:${r.id}@controlevida`,`DTSTAMP:${new Date().toISOString().replace(/[-:]/g,'').replace(/\.\d{3}/,'')}`,time?`DTSTART;TZID=America/Sao_Paulo:${date}T${time.replace(':','')}00`:`DTSTART;VALUE=DATE:${date}`,`SUMMARY:${safe(r.title)}`,`DESCRIPTION:${safe(r.details.notes||'')}`,`LOCATION:${safe(r.details.location||'')}`);if(time&&r.details.end_time)lines.push(`DTEND;TZID=America/Sao_Paulo:${date}T${r.details.end_time.replace(':','')}00`);lines.push('END:VEVENT');});lines.push('END:VCALENDAR');download(lines.join('\r\n')+'\r\n','controlevida-agenda.ics','text/calendar;charset=utf-8');
  }
 }catch(e){toast(e.message);}
});
document.addEventListener('change',async event=>{
 const el=event.target;
 if(el.id==='day-select'){S.day=el.value||S.today;render();}
 if(el.id==='month-select'){S.month=el.value||S.today.slice(0,7);render();}
 if(el.name==='recurrence'){$('.weekday-options').hidden=el.value!=='weekly';$('#monthly-option').hidden=el.value!=='monthly';}
 if(el.id==='import-file' && el.files[0]){
   try{if(el.files[0].size>5000000)throw new Error('Arquivo muito grande.');const data=JSON.parse(await el.files[0].text());const r=await api('import',data);await refresh();toast(importSummary(r.new_records,r.state));}catch(e){toast(e.message);}
 }
});
document.addEventListener('input',event=>{
 if(event.target.id==='search'){const position=event.target.selectionStart;S.search=event.target.value;render();$('#search').focus();if($('#search').type==='search')$('#search').setSelectionRange(position,position);}
});
document.addEventListener('submit',async event=>{
 const form=event.target;
 if(form.id==='record-form')return saveEditor(event);
 if(form.id==='quick-note'){event.preventDefault();try{const text=new FormData(form).get('note').trim();if(!text)return;const r=await api('save',{kind:'note',title:`Nota de ${dayLabel(S.today)}`,day:S.today,details:{notes:text}});S.records.push(r);form.reset();toast('Nota guardada.');}catch(e){toast(e.message);}}
 if(form.id==='settings-form'){event.preventDefault();try{const fd=new FormData(form);await api('settings',{initial_balance_cents:cents(fd.get('balance')),categories:fd.get('categories').split('\n').map(s=>s.trim()).filter(Boolean)});await refresh();toast('Ajustes salvos.');}catch(e){toast(e.message);}}
});
if($('#login-form')){
 $('#login-form').addEventListener('submit',async event=>{event.preventDefault();const button=$('[type=submit]',event.target);button.disabled=true;$('#login-error').textContent='';try{const fd=new FormData(event.target);const data=await api('login',{email:fd.get('email'),password:fd.get('password'),remember:fd.get('remember')==='on'});S.csrf=data.csrf;$('#password').value='';location.reload();}catch(e){$('#login-error').textContent=e.message;}finally{button.disabled=false;}});
 $('#show-password').addEventListener('click',()=>{const password=$('#password');password.type=password.type==='password'?'text':'password';$('#show-password').setAttribute('aria-label',password.type==='password'?'Mostrar senha':'Ocultar senha');});
 $('#logout').addEventListener('click',async()=>{try{await api('logout',{});showLogin();location.reload();}catch(e){toast(e.message);}});
 $('#connection-status').addEventListener('click',()=>refresh().catch(e=>toast(e.message)));
 $('#quick-add').addEventListener('click',()=>{$('#quick-menu h2').textContent='O que vamos registrar?';$('#quick-options').innerHTML=Object.entries(kinds).map(([k,[l,i]])=>`<button data-new="${k}">${icon(i)}${l}${icon('chevron-right')}</button>`).join('');$('#quick-menu').showModal();icons();});
 $('#menu-backdrop').addEventListener('click',()=>setMenu(false));
 window.addEventListener('hashchange',()=>{const view=location.hash.slice(1);if(views[view]&&view!==S.view)navigate(view);});
 window.addEventListener('offline',()=>status('offline'));
 window.addEventListener('online',()=>{if(S.user)refresh().catch(e=>toast(e.message));});
 document.addEventListener('visibilitychange',()=>{if(!document.hidden&&S.user&&!$('dialog[open]'))refresh().catch(()=>{});});
 window.addEventListener('beforeinstallprompt',event=>{event.preventDefault();$('#install').hidden=false;$('#install').onclick=async()=>{await event.prompt();$('#install').hidden=true;};});
 if('serviceWorker' in navigator)navigator.serviceWorker.register('sw.js',{scope:'/controlevida/'}).catch(()=>{});
 (async()=>{try{const session=await api('session');S.csrf=session.csrf;if(session.user){S.view=views[location.hash.slice(1)]?location.hash.slice(1):'today';await refresh();}else showLogin();}catch(e){$('#boot').textContent=e.message;}})();
}
