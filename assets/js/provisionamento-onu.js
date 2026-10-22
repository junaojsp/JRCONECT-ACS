(() => {
'use strict';
const $ = id => document.getElementById(id);
const esc = value => String(value ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let devices=[],filtered=[],page=1;const size=20;
function statusOf(d){return String(d.status||'');}
function render(){
 const st=$('onu-status').value,serial=$('onu-serial').value.toLowerCase().trim(),model=$('onu-model').value.toLowerCase().trim(),client=$('onu-client').value.toLowerCase().trim();
 filtered=devices.filter(d=>(st==='ALL'||statusOf(d)===st)&&String(d.serial||'').toLowerCase().includes(serial)&&String(d.acs?.product_class||d.ixc?.onu_tipo||'').toLowerCase().includes(model)&&String(d.ixc?.nome||'').toLowerCase().includes(client));
 const pages=Math.max(1,Math.ceil(filtered.length/size));page=Math.min(page,pages);
 const list=filtered.slice((page-1)*size,page*size);
 $('onu-rows').innerHTML=list.length?list.map(d=>{const acs=d.acs||{},ixc=d.ixc||{},st=statusOf(d),label=st==='TR069_ACTIVE'?'TR-069 ativo':st==='TR069_STALE'?'Sem Inform':'Não no ACS',cls=st==='TR069_ACTIVE'?'ok':st==='TR069_STALE'?'warn':'',url=acs.registered&&acs.device_id?'/device-detail.php?id='+encodeURIComponent(acs.device_id):null;return '<tr><td><span class="onu-badge '+cls+'">'+label+'</span></td><td>'+esc(d.serial)+'</td><td>'+esc(acs.product_class||ixc.onu_tipo||'—')+'</td><td>'+esc(ixc.nome||'—')+'</td><td>'+esc(ixc.pon_id||'—')+'</td><td>'+esc(ixc.onu_numero||'—')+'</td><td>'+esc(acs.last_inform||'—')+'</td><td>'+(url?'<a class="onu-open" href="'+esc(url)+'">Abrir dispositivo</a>':'<span title="Autorização na OLT ainda não integrada">Indisponível</span>')+'</td></tr>'}).join(''):'<tr><td colspan="8" class="onu-empty">Nenhuma ONU encontrada para estes filtros.</td></tr>';
 $('onu-count').textContent=filtered.length+' registro(s)';$('onu-page-label').textContent='Página '+page+' de '+pages;$('onu-prev').disabled=page<=1;$('onu-next').disabled=page>=pages;
 document.querySelectorAll('.onu-tabs button').forEach(b=>b.classList.toggle('selected',b.dataset.status===st));
}
async function load(){
 $('onu-refresh').disabled=true;$('onu-rows').innerHTML='<tr><td colspan="8" class="onu-empty">Consultando IXC e GenieACS...</td></tr>';
 try{const res=await fetch('/api/discovery-ixc-tr069.php',{cache:'no-store',credentials:'same-origin'});const data=await res.json();if(!res.ok||!data.success)throw Error(data.message||'Falha na consulta');devices=Array.isArray(data.devices)?data.devices:[];const count=s=>devices.filter(d=>statusOf(d)===s).length;$('onu-pending').textContent=count('NOT_IN_ACS');$('onu-active').textContent=count('TR069_ACTIVE');$('onu-stale').textContent=count('TR069_STALE');$('onu-total').textContent=devices.length;$('onu-fail').textContent='0';page=1;render();}
 catch(err){$('onu-fail').textContent='1';$('onu-rows').innerHTML='<tr><td colspan="8" class="onu-empty">Erro na consulta: '+esc(err.message)+'</td></tr>';}
 finally{$('onu-refresh').disabled=false;}
}
$('onu-refresh').addEventListener('click',load);$('onu-search').addEventListener('click',()=>{page=1;render()});$('onu-clear').addEventListener('click',()=>{$('onu-status').value='ALL';for(const id of ['onu-serial','onu-model','onu-client'])$(id).value='';page=1;render()});$('onu-prev').addEventListener('click',()=>{page--;render()});$('onu-next').addEventListener('click',()=>{page++;render()});document.querySelectorAll('.onu-tabs button').forEach(b=>b.addEventListener('click',()=>{$('onu-status').value=b.dataset.status;page=1;render()}));load();
})();