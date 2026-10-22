/* Read-only NOC equipment views. The legacy action screens remain available. */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const text = value => value === null || value === undefined || value === '' ? 'N/D' : String(value);
  const fmt = value => Number(value).toLocaleString('pt-BR');
  const stamp = value => {
    if (!value) return 'Data não informada';
    // IXC dates have no timezone: retain the value reported by that source.
    if (/^\d{4}-\d{2}-\d{2} /.test(value)) return String(value);
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('pt-BR', {timeZone:'America/Sao_Paulo'});
  };
  const duration = value => {
    if (!Number.isFinite(value) || value < 0) return 'N/D';
    return `${Math.floor(value / 86400)}d ${Math.floor(value / 3600) % 24}h ${Math.floor(value / 60) % 60}min`;
  };
  const signal = d => {
    const value = d.rx?.value;
    const className = d.rx_band === 'critical' ? 'rx-critical' : d.rx_band === 'attention' ? 'rx-attention' : '';
    return `<span class="${className}">${Number.isFinite(value) ? value.toFixed(2)+' dBm' : 'N/D'}</span><br><small>${esc(text(d.rx?.source))} · ${esc(stamp(d.rx?.updated_at))}</small>`;
  };
  const communication = status => status === 'online' ? 'Online TR-069' : 'Sem Inform recente';
  let busy = false, devices = [], page = 1;
  const id = $('inventory-app').getAttribute('data-device-id');

  async function read(url, inventory = true) {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 35000);
    try {
      const response = await fetch(url, {credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json'}, signal:controller.signal});
      if (response.redirected || (inventory && (response.status === 401 || response.status === 403))) throw new Error('Sessão expirada. Entre novamente.');
      if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Resposta inválida da consulta. Atualize a página.');
      let data;
      try { data = await response.json(); } catch { throw new Error('Resposta incompleta da consulta. Tente novamente.'); }
      if (!response.ok || !data?.success) throw new Error(inventory ? (data?.message || `Consulta indisponível (HTTP ${response.status}).`) : 'Consulta óptica da tela atual indisponível.');
      if (inventory && !Array.isArray(data.devices)) throw new Error('Inventário inválido na resposta.');
      return data;
    } catch (error) {
      if (error.name === 'AbortError') throw new Error('A consulta excedeu o tempo limite. Tente novamente.');
      throw error;
    } finally { window.clearTimeout(timeout); }
  }

  function renderList() {
    const query = $('inventory-search').value.trim().toLocaleLowerCase('pt-BR');
    const status = $('inventory-status').value, band = $('inventory-signal').value;
    const filtered = devices.filter(d => (status === 'all' || d.status === status)
      && (band === 'all' || d.rx_band === band)
      && [d.serial,d.manufacturer,d.model,d.ip].some(v => String(v ?? '').toLocaleLowerCase('pt-BR').includes(query)));
    const max = Math.max(1, Math.ceil(filtered.length / 25));
    page = Math.min(page, max);
    $('inventory-total').textContent = fmt(devices.length);
    $('inventory-online').textContent = fmt(devices.filter(d => d.status === 'online').length);
    $('inventory-offline').textContent = fmt(devices.filter(d => d.status !== 'online').length);
    const validRx = devices.filter(d => Number.isFinite(d.rx?.value) && d.rx.value < 0 && d.rx.value >= -50);
    $('inventory-critical').textContent = validRx.length ? fmt(validRx.filter(d => d.rx_band === 'critical').length) : 'N/D';
    $('inventory-rx-coverage').textContent = validRx.length
      ? `${fmt(validRx.length)} leituras válidas · ${fmt(devices.length-validRx.length)} sem RX`
      : 'Nenhuma leitura RX válida nesta consulta';
    $('inventory-rows').innerHTML = filtered.slice((page-1)*25,page*25).map(d => `<tr>
      <td><b>${esc(text(d.manufacturer))} ${esc(text(d.model))}</b><small>${esc(text(d.serial))}</small></td>
      <td>${esc(text(d.ip))}</td><td>${signal(d)}</td><td><span class="noc-chip ${d.status==='online'?'online':'offline'}">${communication(d.status)}</span></td>
      <td>${duration(d.uptime)}</td><td>${esc(stamp(d.last_inform))}</td>
      <td><a href="/device-noc.php?id=${encodeURIComponent(d.id)}">Abrir ↗</a></td></tr>`).join('')
      || '<tr><td colspan="7" class="noc-empty">Nenhum equipamento corresponde aos filtros.</td></tr>';
    $('inventory-page-label').textContent = `${fmt(filtered.length)} encontrados · Página ${page} de ${max}`;
    $('inventory-prev').disabled = page <= 1;
    $('inventory-next').disabled = page >= max;
  }

  function renderDetail(d) { window.renderNocDevice(d); }

  function opticalWarning(faults) {
    const labels = {not_configured:'Configuração IXC não disponível para esta consulta.',
      invalid_configuration:'A configuração da conexão IXC precisa ser verificada.',
      authorization:'O IXC recusou a autorização da consulta.',
      timeout:'O IXC excedeu o tempo de resposta.', deadline:'A coleta IXC excedeu o tempo limite.',
      rate_limited:'O IXC limitou a quantidade de consultas.', connection:'Não foi possível estabelecer a conexão IXC.',
      http_error:'O IXC retornou erro na consulta.', invalid_response:'O IXC retornou uma resposta inválida.',
      cache_unavailable:'O armazenamento privado da leitura IXC não está acessível.',
      refresh_busy:'Outra consulta está atualizando o IXC; tente novamente em instantes.',
      incomplete_inventory:'O IXC não retornou o inventário completo.', inventory_limit:'O inventário IXC excedeu o limite da consulta.'};
    const reasons=[...new Set(faults.map(f=>labels[f] || 'A leitura IXC ficou indisponível nesta consulta.'))];
    $('inventory-optical-warning').hidden=!reasons.length;
    $('inventory-optical-warning').textContent=reasons.join(' ')+(reasons.length
      ? ' Os sinais disponíveis usam as leituras válidas; N/D não significa sinal normal.' : '');
  }

  async function load() {
    if (busy) return;
    busy = true; $('inventory-refresh').disabled = true; $('inventory-error').hidden = true;
    try {
      let last;
      if (id) {
        last = await read('/api/noc-devices.php?id='+encodeURIComponent(id));
        const device = last.devices[0];
        if (!device || device.id !== id || !Array.isArray(device.wan) || !Array.isArray(device.wifi)) throw new Error('Detalhes inválidos na resposta.');
        renderDetail(device);
        opticalWarning(last.optical_status && last.optical_status!=='ok' ? [last.optical_reason || last.optical_status] : []);
        try {
          // Exactly the same authenticated per-ONU endpoint used by device-detail.js.
          const optical = await read('/api/get-onu-optical.php?device_id='+encodeURIComponent(id), false);
          if (!window.mergeNocLegacyOptical(device,optical)) throw new Error('Resposta óptica incompatível.');
          renderDetail(device);
          opticalWarning([]);
        } catch {
          $('inventory-optical-warning').hidden=false;
          $('inventory-optical-warning').textContent='A consulta óptica usada pela tela atual não foi concluída. Mantendo os dados disponíveis do ACS/inventário; tente Atualizar.';
        }
      } else {
        let next = 0, complete = false;
        const rows = [], seen = new Set(), opticalFaults=[];
        $('inventory-load-status').textContent = 'Consultando inventário completo…';
        for (let count=0;count<201;count++) {
          last = await read('/api/noc-devices.php?skip='+next);
          if(last.optical_status && last.optical_status!=='ok')opticalFaults.push(last.optical_reason || last.optical_status);
          for (const row of last.devices) {
            if (typeof row.id !== 'string' || seen.has(row.id)) throw new Error('O inventário mudou durante a consulta. Atualize para carregar novamente.');
            seen.add(row.id); rows.push(row);
          }
          if (rows.length > 20000) throw new Error('Inventário excedeu o limite desta prévia. Use a lista atual.');
          $('inventory-load-status').textContent = `Consultando inventário… ${fmt(rows.length)} recebidos`;
          if (!last.has_more) {complete=true;break;}
          if (!Number.isInteger(last.next_skip) || last.next_skip <= next || !last.devices.length) throw new Error('Paginação inválida no inventário.');
          next = last.next_skip;
        }
        if (!complete) throw new Error('Inventário excedeu o limite desta prévia. Use a lista atual.');
        devices = rows; page = 1; renderList();
        opticalWarning(opticalFaults);
        $('inventory-load-status').textContent = `${fmt(devices.length)} equipamentos ACS · RX IXC ${opticalFaults.length?'com falhas na consulta':'disponível'} · consulta concluída`;
      }
      $('inventory-captured').textContent = 'Consulta: '+stamp(last.captured_at);
    } catch(error) {
      $('inventory-error').textContent = error.message || 'Falha na consulta dos equipamentos.';
      $('inventory-error').hidden = false;
      if (!id) $('inventory-load-status').textContent = devices.length ? 'Falha ao atualizar · mantendo a última lista válida' : 'Inventário indisponível';
    } finally { busy=false; $('inventory-refresh').disabled=false; }
  }
  $('inventory-refresh').addEventListener('click',load);
  $('inventory-menu').addEventListener('click',()=>{
    const open=document.body.classList.toggle('noc-menu-open');
    $('inventory-menu').setAttribute('aria-expanded',String(open));
  });
  $('inventory-menu-close').addEventListener('click',()=>{document.body.classList.remove('noc-menu-open');$('inventory-menu').setAttribute('aria-expanded','false');});
  document.addEventListener('keydown',event=>{if(event.key==='Escape'){
    document.body.classList.remove('noc-menu-open');$('inventory-menu').setAttribute('aria-expanded','false');
  }});
  if (!id) {
    for (const key of ['inventory-search','inventory-status','inventory-signal']) $(key).addEventListener('input',()=>{page=1;renderList();});
    $('inventory-prev').addEventListener('click',()=>{if(page>1){page--;renderList();}});
    $('inventory-next').addEventListener('click',()=>{page++;renderList();});
  }
  load();
})();
