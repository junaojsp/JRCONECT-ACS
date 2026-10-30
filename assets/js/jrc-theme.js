/* Apply the common visual shell without replacing existing controls or requests. */
(() => {
  'use strict';
  function init() {
    document.body.classList.add('jrc-theme');
    document.documentElement.setAttribute('data-bs-theme','dark');
    if(location.pathname.endsWith('/discovery.php'))document.body.classList.add('jrc-discovery');
    const sidebar=document.querySelector('.sidebar')||document.querySelector('.noc-sidebar');
    if(!sidebar)return;
    const catalog=[
      ['/dashboard.php','Dashboard atual','bi-speedometer2'],
      ['/dashboard-noc.php','Painel NOC 2.0','bi-activity'],
      ['/devices.php','Lista atual de equipamentos','bi-router'],
      ['/devices-noc.php','Equipamentos NOC','bi-hdd-stack'],
      ['/discovery.php','Discovery TR-069','bi-search'],
      ['/map.php','Mapa da rede','bi-map'],
      ['/configuration.php','Configurações','bi-gear'],
      ['/speedtest.php','Teste de velocidade','bi-speedometer'],
      ['/users.php','Usuários','bi-people'],
      ['/view-logs.php','Registros do sistema','bi-journal-text'],
      ['/logout.php','Sair','bi-box-arrow-right']
    ];
    const existing=new Set([...sidebar.querySelectorAll('a[href]')].map(a=>new URL(a.getAttribute('href'),location.href).pathname));
    const missing=catalog.filter(([url])=>!existing.has(url));
    if(missing.length){
      const links=document.createElement('nav');links.className='jrc-extra-nav';links.setAttribute('aria-label','Outras áreas do ACS');
      missing.forEach(([url,label,icon])=>{
        const a=document.createElement('a');a.href=url;
        const i=document.createElement('i');i.className='bi '+icon;i.setAttribute('aria-hidden','true');
        const span=document.createElement('span');span.textContent=label;a.append(i,span);links.append(a);
      });
      const menu=sidebar.querySelector('.sidebar-menu');
      if(menu){const li=document.createElement('li');li.append(links);menu.append(li);}else sidebar.insertBefore(links,sidebar.querySelector('.noc-sidebar-bottom'));
    }
    sidebar.querySelectorAll('a[href]').forEach(a=>{
      const label=a.textContent.trim();
      if(label&&!a.hasAttribute('aria-label'))a.setAttribute('aria-label',label);
      if(label&&!a.hasAttribute('title'))a.setAttribute('title',label);
      const url=new URL(a.getAttribute('href'),location.href);
      if(!url.hash&&url.pathname===location.pathname){a.setAttribute('aria-current','page');a.classList.add('active');}
    });
    const topbar=document.querySelector('.topbar');
    if(topbar&&!document.getElementById('jrc-mobile-menu')){
      const button=document.createElement('button');button.id='jrc-mobile-menu';button.type='button';button.className='jrc-mobile-menu';button.setAttribute('aria-label','Abrir menu');button.setAttribute('aria-expanded','false');button.textContent='☰';
      topbar.prepend(button);
      const setOpen=open=>{document.body.classList.toggle('jrc-menu-open',open);button.setAttribute('aria-expanded',String(open));button.setAttribute('aria-label',open?'Fechar menu':'Abrir menu');};
      button.addEventListener('click',()=>setOpen(!document.body.classList.contains('jrc-menu-open')));
      document.addEventListener('keydown',event=>{if(event.key==='Escape')setOpen(false);});
      document.addEventListener('click',event=>{if(document.body.classList.contains('jrc-menu-open')&&!sidebar.contains(event.target)&&!button.contains(event.target))setOpen(false);});
    }
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
