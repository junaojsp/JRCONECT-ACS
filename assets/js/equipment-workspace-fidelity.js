(() => {
  'use strict';
  if(window.__JR_EQUIPMENT_FIDELITY__)return;
  window.__JR_EQUIPMENT_FIDELITY__=true;
  const overview=document.getElementById('overview-content');
  if(!overview)return;
  function apply(){
    const grid=overview.querySelector('.jr-eq-overview');
    if(!grid)return;
    if(!grid.querySelector('.jr-eq-source-card')){
      const c=document.createElement('section');
      c.className='jr-eq-source-card';
      c.innerHTML='<h2><i class="bi bi-database"></i> Fonte de dados</h2><div><span>Sessão / Autenticação</span><strong>RADIUS / WAN</strong></div><div><span>Monitoramento</span><strong>RADIUS</strong></div><div><span>Gerenciamento</span><strong>TR-069</strong></div>';
      grid.append(c);
    }
    const tools=grid.querySelector('.acs-approved-lan .jr-eq-tools h2');
    if(tools)tools.innerHTML='<i class="bi bi-activity"></i> Diagnósticos';
    const ah=grid.querySelector('.jr-eq-operation-bar h2');
    if(ah)ah.innerHTML='<i class="bi bi-sliders"></i> Ações autorizadas <small style="font-weight:500;color:#8da7b2">(TR-069)</small>';
    const rail=document.querySelector('.jr-eq-assistant');
    const intro=rail?.querySelector('.acs-ai-question-only > strong');
    if(intro)intro.textContent='Olá! Posso ajudar com este equipamento.';
    const sub=rail?.querySelector('.acs-ai-question-only > span');
    if(sub)sub.textContent='Consulte Wi-Fi, sinal óptico, portas, sessão e monitoramento.';
  }
  let raf=0;
  new MutationObserver(()=>{if(!raf)raf=requestAnimationFrame(()=>{raf=0;apply();});}).observe(overview,{childList:true,subtree:true});
  apply();
})();