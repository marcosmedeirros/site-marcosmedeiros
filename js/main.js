(() => {
  'use strict';

  const PHONE = '5555997164253';
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ------------------------------------------------------------
     Traduções (o português fica no próprio HTML)
     ------------------------------------------------------------ */
  const EN = {
    'skip': 'Skip to content',
    'nav.solutions': 'Solutions',
    'nav.process': 'Process',
    'nav.work': 'Work',
    'nav.about': 'About',
    'nav.faq': 'FAQ',
    'nav.cta': 'Get in touch',
    'lang.aria': 'Mudar para português',
    'wa.aria': 'Chat on WhatsApp',

    'hero.badge': 'Available for new projects',
    'hero.t1': 'You have the idea.',
    'hero.t2': 'I build the',
    'hero.sr': 'system.',
    'hero.sub': 'Developer based in Santa Maria, Brazil, working with clients nationwide. Management systems, WhatsApp bots, online stores, websites and automation built around how your business actually works.',
    'hero.cta1': 'Tell me your idea',
    'hero.cta2': 'What’s your problem?',
    'stats.1': 'systems in production, built and maintained by me',
    'stats.2': 'the year FBA Brasil went live — still running non-stop',
    'stats.3': 'you talk directly to the person writing the code',

    'sol.eyebrow': 'Solutions',
    'sol.title': 'What’s your problem? <em>I have the solution.</em>',
    'sol.lead': 'Pick the one that sounds most like your day. Every project starts from a concrete problem, not from a list of technologies.',
    'sol.tabs': 'Problems',
    'sol.other': 'Not on the list?',
    'sol.otherLink': 'Tell me how your day&nbsp;works&nbsp;→',
    'sol.kicker': 'The solution',
    'sol.cta': 'I want to fix this',

    'p1.q': 'I run everything on spreadsheets and it has already cost me money.',
    'p1.t': 'Management & inventory system',
    'p1.d': 'A dashboard built around how your operation works: know instantly what came in, what went out and what is available — no notebooks, no lost spreadsheets.',
    'p1.l1': 'Items, customers and suppliers in one place',
    'p1.l2': 'Check-in, check-out and live availability',
    'p1.l3': 'Full history and reports',
    'p1.l4': 'Individual logins for your team',
    'p1.case': 'See the Cavalheiro Locações case ↓',

    'p2.q': 'I take too long to reply on WhatsApp and lose customers.',
    'p2.t': 'WhatsApp bot',
    'p2.d': 'Your customers get an answer right away, at any hour. The bot handles routine questions, takes the order and alerts you when a human is needed.',
    'p2.l1': 'Automatic replies at any hour',
    'p2.l2': 'Answers to the usual questions',
    'p2.l3': 'Order and customer data capture',
    'p2.l4': 'Internal alerts for your team',
    'p2.case': 'See the FBA Brasil bot ↓',

    'p3.q': 'I want to sell my products online.',
    'p3.t': 'Online store or catalogue',
    'p3.d': 'A beautiful storefront that’s fast on mobile, with orders landing straight in your WhatsApp or dashboard. Prices, photos and stock under your control.',
    'p3.l1': 'Products or services with photos and prices',
    'p3.l2': 'Orders straight to WhatsApp or your dashboard',
    'p3.l3': 'Update it yourself, no one else needed',
    'p3.l4': 'Fast and mobile-first',
    'p3.case': 'See the Cavalheiro catalogue ↓',

    'p4.q': 'My business has no website — or nobody can find it.',
    'p4.t': 'Website or platform',
    'p4.d': 'From a company website that builds trust to a full platform with login, user area and your own business rules.',
    'p4.l1': 'Fast and optimized to show up on Google',
    'p4.l2': 'Modern look that works on any screen',
    'p4.l3': 'Login area and dashboard when needed',
    'p4.l4': 'Easy for you to update later',
    'p4.case': 'See the FBA Brasil platform ↓',

    'p5.q': 'I do the same manual task every single day.',
    'p5.t': 'Integrations & automation',
    'p5.d': 'I connect the tools you already use so they talk to each other. Less repeated work, fewer typos and more time for what matters.',
    'p5.l1': 'Payments, e-mail and spreadsheets connected',
    'p5.l2': 'Third-party API integrations',
    'p5.l3': 'Routines that run on their own',
    'p5.l4': 'Automatic alerts and reports',
    'p5.case': 'Integrations are my day-to-day ↓',

    'p6.q': 'My system broke and whoever built it disappeared.',
    'p6.t': 'Maintenance & rescue',
    'p6.d': 'A system someone else built and abandoned, a corrupted database, a site that went down. I take it over, fix it and keep it running.',
    'p6.l1': 'Diagnosis of what is broken',
    'p6.l2': 'Fixes and getting it back online',
    'p6.l3': 'Cleanup of code and database',
    'p6.l4': 'Ongoing maintenance afterwards',
    'p6.case': 'About my experience ↓',

    'proc.eyebrow': 'Process',
    'proc.title': 'From the first chat <em>to a live system.</em>',
    'proc.lead': 'You talk to the person who writes the code. No middlemen, nothing outsourced.',
    's1.t': 'Conversation',
    's1.d': 'You describe the problem. I ask how the operation runs today, where it gets stuck and what has to ship first.',
    's2.t': 'Scope and quote',
    's2.d': 'I write down what will be delivered, in which stages, with a fixed deadline and price. You approve before any code is written.',
    's3.t': 'Building in the open',
    's3.d': 'I deliver in usable pieces. You test each stage and change course early, not at the end.',
    's4.t': 'Live and trained',
    's4.d': 'I publish it, migrate your data and train the people who will use it, so the team knows how to run it from day one.',
    's5.t': 'Ongoing support',
    's5.d': 'After delivery I stay around for fixes, adjustments and the next module as the business grows.',

    'work.eyebrow': 'Work',
    'work.title': 'Real systems, <em>live right now.</em>',
    'work.lead': 'Two projects I built end to end, from data modelling to the screen — and still maintain.',
    'shots.site': 'Website',
    'fba.shot2': 'GM dashboard',
    'cav.shot2': 'Catalogue',
    'case.problem': 'The problem',
    'case.solution': 'What I built',
    'fba.meta': 'Fantasy basketball & NBA 2K Pro-Am league · since 2024',
    'fba.problem': 'A league with 122 players across 122 teams, four divisions with promotion and relegation, draft, trades and free agency — all handled by hand in spreadsheets and loose messages.',
    'fba.solution': 'The entire platform: sign-up and user management, the app, the general-manager area with draft and trades, daily minigames, the league newspaper and the WhatsApp support bot.',
    'fba.tag1': 'Web platform',
    'fba.tag2': 'App',
    'fba.tag3': 'Draft & trades',
    'fba.tag4': 'Minigames',
    'fba.tag5': 'WhatsApp bot',
    'cav.meta': 'Machinery & scaffolding rental · Santa Maria, Brazil',
    'cav.problem': 'Equipment tracking lived in notebooks and spreadsheets. Nobody could say straight away what was rented out, what had come back and what was available.',
    'cav.solution': 'The website with an equipment catalogue sending requests straight to the shop’s WhatsApp, plus the internal inventory system with check-out, return, availability and per-item history.',
    'cav.tag1': 'Company website',
    'cav.tag2': 'Catalogue',
    'cav.tag3': 'WhatsApp orders',
    'cav.tag4': 'Inventory control',

    'about.eyebrow': 'About',
    'about.title': 'Hi, <em>I’m Marcos.</em>',
    'about.p1': 'I’m a developer based in Santa Maria, in southern Brazil, and my academic background includes a Master’s program at the Federal University of Santa Maria (UFSM). Day to day I build websites, platforms and web systems: sign-ups and user areas, payment and e-mail integrations, databases and infrastructure.',
    'about.p2': 'I take on company work and my own projects end to end. FBA Brasil and Cavalheiro are examples: I built both entirely on my own, from data modelling to the screen, and I still maintain them.',
    'about.p3': 'What I sell isn’t the technology: it’s the problem solved and a system that keeps working after handover.',
    'about.f1l': 'Education',
    'about.f1': 'Master’s at the Federal University of Santa Maria (UFSM)',
    'about.f2l': 'Based in',
    'about.f2': 'Santa Maria, Brazil — working remotely',
    'about.f3l': 'Résumé',

    'faq.eyebrow': 'FAQ',
    'faq.title': 'Common <em>questions.</em>',
    'faq.lead': 'Still have a question?',
    'faq.leadLink': 'Message me on WhatsApp.',
    'q1.q': 'How much does a system cost?',
    'q1.a': 'It depends on the size of the problem. After our first conversation I send a scope with stages, deadline and a fixed price — you know exactly what you’ll pay before we start.',
    'q2.q': 'How long does it take?',
    'q2.a': 'The deadline comes with the scope. Since I deliver in usable pieces, you start using the first features before the whole project is done.',
    'q3.q': 'Do I need to understand technology?',
    'q3.a': 'No. You explain how your operation works; the technical side is on me. At the end I train whoever will use the system.',
    'q4.q': 'Do you work with clients outside Santa Maria?',
    'q4.a': 'Yes. I work remotely with clients anywhere — we talk over WhatsApp or video call.',
    'q5.q': 'What happens after the system goes live?',
    'q5.a': 'I stay around for adjustments, fixes and new modules. FBA Brasil has been live since 2024 and I still maintain it today.',

    'cta.badge': 'First conversation is free, no strings attached',
    'cta.title': 'Tell me what’s <em>holding you back.</em>',
    'cta.sub': 'You explain the problem and I tell you whether it can be solved, how, and what it costs.',
    'cta.wa': 'Message on WhatsApp',
    'cta.mail': 'Send an e-mail',

    'footer.made': 'Systems, websites and WhatsApp bots developer in Santa Maria, Brazil',
    'footer.top': 'Back to top'
  };

  const META = {
    pt: {
      title: 'Desenvolvedor de Sistemas e Sites em Santa Maria/RS | Marcos Medeiros',
      description: 'Desenvolvedor em Santa Maria/RS. Sistemas de gestão e estoque, bots de WhatsApp, lojas virtuais e sites sob medida. Atendo todo o Brasil — orçamento sem compromisso.',
      words: ['sistema.', 'site.', 'bot.', 'app.', 'painel.'],
      waDefault: 'Oi Marcos! Vi seu site e tenho uma ideia/problema que quero resolver.',
      waProblem: (q) => `Oi Marcos! Vi seu site. Meu problema: "${q}" Pode me ajudar?`
    },
    en: {
      title: 'Custom Software, Websites & WhatsApp Bots Developer | Marcos Medeiros',
      description: 'Developer based in Brazil. Management and inventory systems, WhatsApp bots, online stores, websites and integrations — from the database to the screen.',
      words: ['system.', 'website.', 'bot.', 'app.', 'dashboard.'],
      waDefault: 'Hi Marcos! I saw your website and I have an idea/problem I’d like to solve.',
      waProblem: (q) => `Hi Marcos! I saw your website. My problem: "${q}" Can you help?`
    }
  };

  const i18nEls = $$('[data-i18n]');
  const ariaEls = $$('[data-i18n-aria]');
  // guarda o português original
  i18nEls.forEach((el) => { el.dataset.pt = el.innerHTML; });
  ariaEls.forEach((el) => { el.dataset.ptAria = el.getAttribute('aria-label') || ''; });

  // ?lang=en na URL tem prioridade (útil para mandar o link em inglês)
  let lang = 'pt';
  try {
    const saved = new URLSearchParams(location.search).get('lang') || localStorage.getItem('lang');
    if (saved === 'pt' || saved === 'en') lang = saved;
  } catch (e) { /* storage indisponível */ }

  const waLink = (text) => `https://wa.me/${PHONE}?text=${encodeURIComponent(text)}`;

  function applyLang(next) {
    lang = next;
    const en = lang === 'en';
    document.documentElement.lang = en ? 'en' : 'pt-BR';

    i18nEls.forEach((el) => {
      const key = el.dataset.i18n;
      el.innerHTML = en && EN[key] != null ? EN[key] : el.dataset.pt;
    });
    ariaEls.forEach((el) => {
      const key = el.dataset.i18nAria;
      el.setAttribute('aria-label', en && EN[key] != null ? EN[key] : el.dataset.ptAria);
    });

    const m = META[lang];
    document.title = m.title;
    const desc = $('meta[name="description"]');
    if (desc) desc.setAttribute('content', m.description);

    $$('[data-wa="default"]').forEach((a) => { a.href = waLink(m.waDefault); });
    $$('[data-wa-problem]').forEach((a) => {
      const q = $(`#tab-${a.dataset.waProblem} .solver__q`);
      a.href = waLink(m.waProblem(q ? q.textContent.trim() : ''));
    });

    rotatorIndex = 0;
    if (rotator) rotator.textContent = m.words[0];

    try { localStorage.setItem('lang', lang); } catch (e) { /* ok */ }
  }

  $('#langToggle').addEventListener('click', () => applyLang(lang === 'pt' ? 'en' : 'pt'));

  /* ------------------------------------------------------------
     Palavra que troca no título
     ------------------------------------------------------------ */
  const rotator = $('#rotator');
  let rotatorIndex = 0;

  if (rotator && !reducedMotion) {
    setInterval(() => {
      if (document.hidden) return;
      rotator.classList.add('is-out');
      setTimeout(() => {
        const words = META[lang].words;
        rotatorIndex = (rotatorIndex + 1) % words.length;
        rotator.textContent = words[rotatorIndex];
        rotator.classList.remove('is-out');
      }, 450);
    }, 2600);
  }

  applyLang(lang);

  /* ------------------------------------------------------------
     Nav: fundo ao rolar + menu mobile
     ------------------------------------------------------------ */
  const nav = $('#nav');
  const menuBtn = $('#menuBtn');
  const onScroll = () => nav.classList.toggle('is-scrolled', window.scrollY > 12);
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  // botão flutuante do WhatsApp entra logo após o carregamento
  setTimeout(() => $('#waFloat').classList.add('is-visible'), 800);

  const setMenu = (open) => {
    nav.classList.toggle('is-open', open);
    menuBtn.setAttribute('aria-expanded', String(open));
  };
  menuBtn.addEventListener('click', () => setMenu(!nav.classList.contains('is-open')));
  $$('#mobileMenu a').forEach((a) => a.addEventListener('click', () => setMenu(false)));
  window.addEventListener('keydown', (e) => { if (e.key === 'Escape') setMenu(false); });
  window.matchMedia('(min-width: 921px)').addEventListener('change', (e) => { if (e.matches) setMenu(false); });

  /* ------------------------------------------------------------
     Link ativo na navegação
     ------------------------------------------------------------ */
  const navLinks = $$('.nav__links a');
  const sectionObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      navLinks.forEach((a) => a.classList.toggle('is-current', a.getAttribute('href') === `#${entry.target.id}`));
    });
  }, { rootMargin: '-45% 0px -50% 0px' });
  ['solucoes', 'processo', 'trabalhos', 'sobre', 'duvidas', 'topo', 'contato']
    .forEach((id) => { const s = document.getElementById(id); if (s) sectionObserver.observe(s); });

  /* ------------------------------------------------------------
     Problema → Solução (tabs)
     ------------------------------------------------------------ */
  const tabs = $$('.solver__tab');
  const panels = $$('.panel');

  const selectTab = (tab, focus = false) => {
    tabs.forEach((t) => {
      const active = t === tab;
      t.setAttribute('aria-selected', String(active));
      t.tabIndex = active ? 0 : -1;
    });
    panels.forEach((p) => p.classList.toggle('is-active', p.id === tab.getAttribute('aria-controls')));
    if (focus) tab.focus();
    if (window.matchMedia('(max-width: 920px)').matches) {
      tab.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'nearest', inline: 'start' });
    }
  };

  tabs.forEach((tab, i) => {
    tab.addEventListener('click', () => selectTab(tab));
    tab.addEventListener('keydown', (e) => {
      const keys = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 };
      if (e.key in keys) {
        e.preventDefault();
        selectTab(tabs[(i + keys[e.key] + tabs.length) % tabs.length], true);
      } else if (e.key === 'Home') {
        e.preventDefault(); selectTab(tabs[0], true);
      } else if (e.key === 'End') {
        e.preventDefault(); selectTab(tabs[tabs.length - 1], true);
      }
    });
  });

  /* ------------------------------------------------------------
     Telas dos trabalhos (troca automática enquanto visível)
     ------------------------------------------------------------ */
  $$('[data-shots]').forEach((root) => {
    const btns = $$('.shots__tab', root);
    const imgs = $$('.browser__screen img', root);
    const url = $('.browser__url', root);
    let current = 0;
    let inView = false;
    let manual = false;

    const play = () => {
      root.classList.remove('is-playing');
      void root.offsetWidth; // reinicia a barra de progresso
      if (inView && !manual && !reducedMotion) root.classList.add('is-playing');
    };

    const show = (n) => {
      current = n;
      btns.forEach((b, k) => {
        b.classList.toggle('is-active', k === n);
        b.setAttribute('aria-pressed', String(k === n));
      });
      imgs.forEach((img, k) => img.classList.toggle('is-active', k === n));
      if (url && imgs[n]) url.textContent = imgs[n].dataset.url;
      play();
    };

    btns.forEach((b, k) => b.addEventListener('click', () => {
      manual = true;
      show(k);
    }));

    root.addEventListener('animationend', (e) => {
      if (e.animationName === 'shotbar') show((current + 1) % imgs.length);
    });

    if ('IntersectionObserver' in window) {
      new IntersectionObserver(([entry]) => {
        inView = entry.isIntersecting;
        play();
      }, { threshold: 0.4 }).observe(root);
    }
  });

  /* ------------------------------------------------------------
     Animação de entrada
     ------------------------------------------------------------ */
  const revealEls = $$('.reveal');
  if ('IntersectionObserver' in window && !reducedMotion) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-in');
          io.unobserve(entry.target);
        }
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

    // escalonar itens irmãos (ex.: passos do processo)
    $$('.steps').forEach((list) => {
      $$('.reveal', list).forEach((el, i) => el.style.setProperty('--d', `${i * 0.06}s`));
    });
    revealEls.forEach((el) => io.observe(el));
  } else {
    revealEls.forEach((el) => el.classList.add('is-in'));
  }

  /* ------------------------------------------------------------
     Spotlight nos cards
     ------------------------------------------------------------ */
  if (window.matchMedia('(hover: hover)').matches) {
    $$('.spot').forEach((el) => {
      el.addEventListener('pointermove', (e) => {
        const r = el.getBoundingClientRect();
        el.style.setProperty('--mx', `${e.clientX - r.left}px`);
        el.style.setProperty('--my', `${e.clientY - r.top}px`);
      });
    });
  }

  /* ------------------------------------------------------------
     Ano no rodapé
     ------------------------------------------------------------ */
  const year = $('#year');
  if (year) year.textContent = new Date().getFullYear();
})();
