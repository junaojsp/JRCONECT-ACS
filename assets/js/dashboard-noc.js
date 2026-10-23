/* JRC-ACS NOC 2.0: read-only dashboard; no device writes. */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const fmt = value => Number(value ?? 0).toLocaleString('pt-BR');
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const state = { busy:false };
  const status = (value, kind) => {
    const node = $('noc-health');
    node.textContent = value;
    node.className = 'noc-pill ' + kind;
  };
  function setText(id, value) { const el=$(id); if(el) el.textContent=value; }
  function dateLabel(value) {
    if (!value) return 'Não informado';
    const time = new Date(value);
    return Number.isNaN(time.getTime()) ? 'Não informado' : time.toLocaleString('pt-BR', {dateStyle:'short',timeStyle:'short'});
  }
  function issueClass(item) {
    if (item.reason === 'RX óptico crítico') return 'critical';
    if (item.reason === 'RX óptico em atenção') return 'attention';
    return '';
  }
  function detailLink(id) { return '/device-detail.php?id=' + encodeURIComponent(id); }
  function renderHistory(history, capturedAt) {
    const target=$('noc-history-chart');
    if(!target) return;
    const end=new Date(capturedAt).getTime()/1000;
    const now=Number.isFinite(end)?end:Date.now()/1000,start=now-86400;
    const points=(Array.isArray(history?.points)?history.points:[])
      .filter(p=>Number.isFinite(p.at)&&p.at>=start&&p.at<=now&&Number.isFinite(p.total)&&p.total>0
        &&Number.isFinite(p.online)&&p.online>=0&&p.online<=p.total)
      .sort((a,b)=>a.at-b.at).map(p=>({at:p.at,value:100*p.online/p.total}));
    if(!points.length){
      target.innerHTML='<div class="noc-empty">O histórico começa com as primeiras consultas válidas do ACS.</div>';
      setText('noc-history-notice',history?.status==='unavailable'?'Histórico indisponível nesta consulta. Os indicadores atuais continuam válidos.':'Aguardando observações. Não há dados retroativos.');
      return;
    }
    const width=850,height=195,left=37,right=12,top=12,bottom=26;
    const floor=Math.max(0,Math.floor((Math.min(...points.map(p=>p.value))-3)/5)*5);
    const x=at=>left+(at-start)/86400*(width-left-right);
    const y=value=>top+(100-value)/(100-floor)*(height-top-bottom);
    let grid='';
    for(let i=0;i<=3;i++){
      const value=floor+(100-floor)*i/3,py=y(value);
      grid+='<line x1="'+left+'" y1="'+py+'" x2="'+(width-right)+'" y2="'+py+'" stroke="#15384b"/>';
      grid+='<text x="'+(left-7)+'" y="'+(py+3)+'" text-anchor="end" fill="#7ea7c1" font-size="10">'+Math.round(value)+'%</text>';
    }
    for(let i=0;i<=8;i++){
      const at=start+i*10800,px=x(at);
      const label=new Date(at*1000).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit',timeZone:'America/Sao_Paulo'});
      grid+='<line x1="'+px+'" y1="'+top+'" x2="'+px+'" y2="'+(height-bottom)+'" stroke="#103044" stroke-dasharray="2 4"/>';
      grid+='<text x="'+px+'" y="'+(height-7)+'" text-anchor="middle" fill="#7ea7c1" font-size="10">'+label+'</text>';
    }
    const segments=[];
    for(const point of points){
      const segment=segments[segments.length-1];
      if(!segment||point.at-segment[segment.length-1].at>600)segments.push([point]);else segment.push(point);
    }
    let lines='';
    for(const segment of segments){
      const coords=segment.map(p=>x(p.at).toFixed(2)+','+y(p.value).toFixed(2));
      if(coords.length>1){
        const area=coords.join(' ')+' '+x(segment[segment.length-1].at)+','+(height-bottom)+' '+x(segment[0].at)+','+(height-bottom);
        lines+='<polygon points="'+area+'" fill="url(#noc-history-fill)"/><polyline points="'+coords.join(' ')+'" fill="none" stroke="#00c9f7" stroke-width="2"/>';
      }
    }
    lines+=points.map(p=>'<circle cx="'+x(p.at)+'" cy="'+y(p.value)+'" r="2.7" fill="#00d7f8"><title>'+esc(dateLabel(new Date(p.at*1000).toISOString())+' · '+p.value.toFixed(1)+'%')+'</title></circle>').join('');
    target.innerHTML='<svg viewBox="0 0 '+width+' '+height+'" role="img" aria-label="Comunicação ACS observada nas últimas 24 horas"><defs><linearGradient id="noc-history-fill" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#00c8f6" stop-opacity=".32"/><stop offset="1" stop-color="#00c8f6" stop-opacity=".02"/></linearGradient></defs>'+grid+lines+'</svg>';
    setText('noc-history-notice',fmt(points.length)+' observações · desde '+dateLabel(new Date(points[0].at*1000).toISOString())+'. Lacunas maiores que 10 min não são conectadas; não equivalem a queda.');
  }
  function renderPons(optical) {
    const target=$('noc-pon-body');if(!target)return;
    const pons=Array.isArray(optical.pons)?optical.pons:[];
    if(!pons.length){target.innerHTML='<tr><td colspan="4" class="noc-empty">'+(optical.status==='ok'?'O cadastro IXC não retornou identificação suficiente de OLT / slot / PON.':'Aguardando conexão IXC para agrupar as portas PON.')+'</td></tr>';return;}
    target.innerHTML=pons.slice(0,5).map(p=>{
      const registered=Math.max(0,Number(p.registered)||0),critical=Math.max(0,Number(p.critical)||0);
      const percent=registered>0?Math.min(100,100*critical/registered):0;
      const label='OLT #'+String(p.olt_id)+(p.slot!==''?' · '+String(p.slot)+'/':' · PON ')+String(p.pon);
      return '<tr><td>'+esc(label)+'</td><td><div class="noc-pon-meter"><span><i style="width:'+percent+'%"></i></span><b>'+fmt(critical)+'</b></div></td><td>'+fmt(registered)+'</td><td>'+fmt(p.unavailable)+'</td></tr>';
    }).join('');
  }
  function render(data) {
    const s=data.summary || {};
    const total=Number(s.total)||0, online=Number(s.online)||0, offline=Number(s.offline)||0;
    const optical=data.optical||{},ixc=optical.status==='ok'&&optical.summary?optical.summary:null;
    const good=Number(ixc?ixc.good:s.optical_good)||0, attention=Number(ixc?ixc.attention:s.optical_attention)||0;
    const critical=Number(ixc?ixc.critical:s.optical_critical)||0, unknown=Number(ixc?ixc.unavailable:s.optical_unavailable)||0;
    const percent=total>0 ? 100*online/total : 0;
    setText('noc-total',fmt(total));setText('noc-online',fmt(online));setText('noc-offline',fmt(offline));
    const valid=good+attention+critical;
    setText('noc-critical',ixc&&valid>0?fmt(critical):'N/D');
    const fromIxc=Number(s.optical_from_ixc)||0,fromTr069=Number(s.optical_from_tr069)||0;
    setText('noc-optical-source',ixc?'Inventário IXC completo · '+fmt(ixc.total)+' registros':'Fallback TR-069 / IXC correlacionado · '+fmt(total)+' equipamentos ACS');
    const inventory=ixc?'Inventário IXC completo. Na triagem dos '+fmt(total)+' equipamentos ACS: '+fmt(fromIxc)+' leituras IXC e '+fmt(fromTr069)+' TR-069. ':'';
    const note=ixc
      ? 'Consulta IXC: '+dateLabel(optical.captured_at)+' (cache de até 2 min).'
      : optical.status==='not_configured'?'IXC não configurado; usando apenas RX disponível no TR-069.'
      : 'IXC indisponível; usando apenas RX disponível no TR-069.';
    setText('noc-optical-notice',inventory+note+(valid===0?' Sem leitura óptica válida; não é possível avaliar a criticidade.':''));
    const availability=total>0 ? percent.toLocaleString('pt-BR',{minimumFractionDigits:1,maximumFractionDigits:1})+'%' : 'N/D';
    setText('noc-availability',availability);setText('noc-availability-large',availability);
    setText('noc-legend-online',fmt(online));setText('noc-legend-offline',fmt(offline));
    const progress=$('noc-progress'); progress.setAttribute('aria-valuenow',String(Math.round(percent)));
    $('noc-progress-bar').style.width=Math.min(100,Math.max(0,percent))+'%';
    setText('noc-optical-good',fmt(good));setText('noc-optical-attention',fmt(attention));
    setText('noc-optical-critical',fmt(critical));setText('noc-optical-unknown',fmt(unknown));
    setText('noc-donut-total',fmt(ixc?ixc.total:valid+unknown));
    setText('noc-donut-label',ixc?'Registros IXC':'Equipamentos ACS');
    const opticalTotal=valid+unknown;
    if(opticalTotal>0){
      const g=100*good/opticalTotal,a=100*attention/opticalTotal,c=100*critical/opticalTotal;
      $('noc-donut').style.background='conic-gradient(#00d89a 0% '+g+'%, #ffac36 '+g+'% '+(g+a)+'%, #ff5563 '+(g+a)+'% '+(g+a+c)+'%, #72869c '+(g+a+c)+'% 100%)';
    } else {
      $('noc-donut').style.background='#24445c';
    }
    renderHistory(data.history,data.captured_at);
    renderPons(optical);
    const items=Array.isArray(data.issues)?data.issues:[];
    const list=$('noc-issues'),table=$('noc-table-body');
    if(!items.length){
      list.innerHTML='<p class="noc-empty">Nenhuma ocorrência prioritária identificada neste snapshot.</p>';
      table.innerHTML='<tr><td colspan="6" class="noc-empty">Nenhum equipamento prioritário neste snapshot.</td></tr>';
    } else {
      list.innerHTML=items.slice(0,4).map(item=>{
        const cls=issueClass(item), id=String(item.device_id||'');
        return '<div class="noc-issue"><span class="noc-chip '+cls+'">'+esc(cls==='critical'?'Crítico':cls==='attention'?'Atenção':'Offline')+'</span><div><div class="noc-reason">'+esc(item.reason)+'</div><small>'+esc(item.serial)+' · '+esc(item.model)+'</small></div><a href="'+detailLink(id)+'">Detalhes <i class="bi bi-arrow-up-right"></i></a></div>';
      }).join('');
      table.innerHTML=items.map(item=>{
        const cls=issueClass(item),rx=typeof item.rx==='number'?item.rx.toFixed(2)+' dBm':'N/D';
        const rxInfo=typeof item.rx==='number'?'<br><small>'+esc(item.rx_source||'TR-069')+(item.rx_updated_at?' · '+esc(item.rx_updated_at):'')+'</small>':'';
        return '<tr><td>'+esc(item.serial)+'</td><td>'+esc(item.model)+'</td><td class="'+(cls==='critical'?'rx-critical':cls==='attention'?'rx-attention':'')+'">'+esc(rx)+rxInfo+'</td><td><span class="noc-chip '+cls+'">'+esc(item.reason)+'</span></td><td>'+esc(dateLabel(item.last_inform))+'</td><td><a href="'+detailLink(String(item.device_id||''))+'">Abrir <i class="bi bi-arrow-up-right"></i></a></td></tr>';
      }).join('');
    }
    setText('noc-captured','Leitura: '+dateLabel(data.captured_at));
    status('ACS RESPONDENDO','ok');
  }
  async function load(){
    if(state.busy || document.hidden) return;
    state.busy=true;const button=$('noc-refresh');button.disabled=true;
    $('noc-error').hidden=true;
    try {
      const res=await fetch('/api/dashboard-noc-data.php',{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
      if(res.redirected||res.status===401||res.status===403){throw new Error('Sessão expirada. Entre novamente para consultar o NOC.');}
      const fallback=res.ok
        ? 'Resposta inválida do painel NOC. Tente novamente.'
        : 'Consulta NOC indisponível (HTTP '+res.status+'). Consulte o log PHP do servidor.';
      const type=res.headers.get('content-type')||'';
      if(!type.includes('application/json'))throw new Error(fallback);
      let data;
      try { data=await res.json(); } catch { throw new Error(fallback); }
      if(!data||typeof data!=='object'||Array.isArray(data))throw new Error(fallback);
      if(!res.ok||!data.success)throw new Error(data.message||'Consulta indisponível.');
      render(data);
    }catch(e){
      $('noc-error').textContent=e.message||'Falha ao carregar indicadores.';
      $('noc-error').hidden=false;
      status('DADOS INDISPONÍVEIS','error');
      setText('noc-captured', $('noc-captured').textContent.replace(/^Leitura: /,'Última leitura válida: '));
      // Never reset last valid numbers to zero upon an outage.
    }finally {state.busy=false;button.disabled=false;}
  }
  $('noc-refresh').addEventListener('click',load);
  const menu=$('noc-menu-toggle');
  if(menu)menu.addEventListener('click',()=>{
    const open=document.body.classList.toggle('noc-menu-open');
    menu.setAttribute('aria-expanded',String(open));
  });
  if(document.querySelectorAll)document.querySelectorAll('.noc-sidebar nav a').forEach(link=>link.addEventListener('click',()=>{
    document.body.classList.remove('noc-menu-open');if(menu)menu.setAttribute('aria-expanded','false');
  }));
  load();
  window.setInterval(load,120000);
})();
