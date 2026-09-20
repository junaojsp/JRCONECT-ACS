(() => {
  'use strict';
  if(window.__JR_EQUIPMENT_FIDELITY_V3__)return;
  window.__JR_EQUIPMENT_FIDELITY_V3__=true;
  const overview=document.getElementById('overview-content');
  if(!overview)return;

  function ensureSource(grid){
    if(grid.querySelector('.jr-eq-source-card'))return;
    const c=document.createElement('section');
    c.className='jr-eq-source-card';
    c.innerHTML='<h2><i class="bi bi-database"></i> Fonte de dados</h2>'+
      '<div><span>Sessão / Autenticação</span><strong>RADIUS / WAN</strong></div>'+
      '<div><span>Monitoramento</span><strong>RADIUS</strong></div>'+
      '<div><span>Gerenciamento</span><strong>TR-069</strong></div>';
    grid.append(c);
  }

  function ensureUnifiedTile(wifi){
    const grid=wifi?.querySelector('.acs-wifi-reference-grid');
    if(!grid||grid.querySelector('.jr-eq-unified-tile'))return;
    const tile=document.createElement('div');
    tile.className='jr-eq-unified-tile';
    tile.innerHTML='<div class="jr-eq-unified-head"><strong>Rede Unificada</strong><span>GERENCIAR</span></div>'+
      '<dl><div><dt>Tipo</dt><dd>Band Steering / Smart Connect</dd></div>'+
      '<div><dt>Bandas</dt><dd>2,4 + 5 GHz</dd></div>'+
      '<div><dt>Estado</dt><dd>Consultar equipamento</dd></div></dl>'+
      '<button type="button" class="acs-soft-btn">Abrir gerenciamento</button>';
    tile.querySelector('button').addEventListener('click',()=>{
      const unified=wifi.querySelector('[data-wng-mode="unified"]');
      if(unified){unified.click();wifi.scrollIntoView({block:'center'});}
    });
    grid.append(tile);
  }

  function tuneDiagnostics(grid){
    const lan=grid.querySelector('.acs-approved-lan');
    const tools=lan?.querySelector('.jr-eq-tools');
    if(!lan||!tools)return;
    const h=tools.querySelector('h2'); if(h)h.remove();
  }

  function tuneAssistant(){
    const rail=document.querySelector('.jr-eq-assistant');
    const intro=rail?.querySelector('.acs-ai-question-only > strong');
    if(intro)intro.textContent='Olá! Posso ajudar com este equipamento.';
    const sub=rail?.querySelector('.acs-ai-question-only > span');
    if(sub)sub.textContent='Consulte Wi-Fi, sinal óptico, portas, sessão e monitoramento.';
  }

  function apply(){
    const grid=overview.querySelector('.jr-eq-overview');
    if(!grid)return;
    ensureSource(grid);
    ensureUnifiedTile(grid.querySelector('.acs-approved-wifi'));
    tuneDiagnostics(grid);
    tuneAssistant();
    const ah=grid.querySelector('.jr-eq-operation-bar h2');
    if(ah)ah.innerHTML='<i class="bi bi-sliders"></i> Ações autorizadas <small style="font-weight:500;color:#8da7b2">(TR-069)</small>';
  }

  let raf=0;
  const obs=new MutationObserver(()=>{if(!raf)raf=requestAnimationFrame(()=>{raf=0;apply();});});
  obs.observe(overview,{childList:true,subtree:true});
  apply();
})();