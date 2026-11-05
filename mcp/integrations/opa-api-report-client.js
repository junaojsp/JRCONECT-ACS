// Read-only Opa report. Does not return individual customer records.
import {readFileSync} from 'node:fs';
import {request} from 'node:https';

const DAY = 86400000;
const MAX_PAGES = 1000; // Fail closed, never silently report partial totals.
const PAGE_SIZE = 200;
const tz = new Intl.DateTimeFormat('en-US', {
  timeZone:'America/Sao_Paulo',year:'numeric',month:'2-digit',day:'2-digit'
});

function localDate(value) {
  if (!value) return null;
  const date = new Date(value);
  if (!Number.isFinite(date.getTime())) return null;
  const parts = Object.fromEntries(tz.formatToParts(date).map(x=>[x.type,x.value]));
  return parts.year + '-' + parts.month + '-' + parts.day;
}
function parseDay(value) {
  if (typeof value!=='string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) throw Error('Data invalida');
  const time = Date.parse(value + 'T00:00:00Z');
  if (!Number.isFinite(time) || new Date(time).toISOString().slice(0,10)!==value) throw Error('Data invalida');
  return time;
}

export function createOpaReportClient({
  baseUrl = process.env.OPA_API_BASE_URL,
  tokenFile = '/run/secrets/opa-api-token',
}={}) {
  if (!baseUrl) throw Error('OPA_API_BASE_URL ausente');
  const base = new URL(baseUrl);
  if (base.protocol!=='https:' || base.username || base.password || base.pathname!=='/' ||
      base.search || base.hash) throw Error('OPA_API_BASE_URL invalida');

  function page(skip) {
    const token=readFileSync(tokenFile,'utf8').trim();
    if (!token || /\s/.test(token)) throw Error('Token Opa invalido');
    const body=JSON.stringify({filter:{status:'F'},options:{limit:PAGE_SIZE,skip}});
    const url=new URL('/api/v1/atendimento',base);
    return new Promise((resolve,reject)=>{
      const req=request(url,{method:'GET',timeout:20000,headers:{
        Authorization:'Bearer '+token,
        Accept:'application/json',
        'Content-Type':'application/json',
        'Content-Length':Buffer.byteLength(body),
      }},res=>{
        let raw='';
        res.setEncoding('utf8');
        res.on('data',chunk=>{
          raw+=chunk;
          if (raw.length > 5_000_000) req.destroy(Error('Resposta Opa excedeu limite'));
        });
        res.on('end',()=>{
          if(res.statusCode!==200) return reject(Error('Opa HTTP '+res.statusCode));
          try {
            const j=JSON.parse(raw);
            if(j.status!=='success' || !Array.isArray(j.data)) throw Error('Resposta Opa invalida');
            resolve(j.data);
          }catch(e){reject(e);}
        });
        res.on('error',reject);
      });
      req.on('timeout',()=>req.destroy(Error('Timeout Opa')));
      req.on('error',reject);
      req.end(body);
    });
  }

  async function finalizados({inicio,fim}) {
    const lo=parseDay(inicio),hi=parseDay(fim);
    if (hi<lo || hi-lo > 89*DAY) throw Error('Periodo deve ter de 1 a 90 dias');
    const today=localDate(new Date());
    if(fim>today) throw Error('Data final futura');
    let total=0,analisados=0,semFim=0,paginas=0;
    const porDia={};
    let finished=false;
    // All available historical F records may have to be scanned because
    // we have not validated server-side date filters on this installation.
    for(let p=0;p<MAX_PAGES;p++) {
      const rows=await page(p*PAGE_SIZE);
      paginas++;
      for(const item of rows){
        analisados++;
        if(item?.status!=='F') continue;
        const dia=localDate(item.fim);
        if(!dia){semFim++;continue;}
        if(dia>=inicio && dia<=fim){total++;porDia[dia]=(porDia[dia]||0)+1;}
      }
      if(rows.length<PAGE_SIZE){finished=true;break;}
    }
    if(!finished) throw Error('Paginacao incompleta: resultado nao disponivel');
    return {
      sistema:'Opa Suite',periodo:{inicio,fim,fuso:'America/Sao_Paulo'},
      criterio:'status F e data fim dentro do periodo (inclusive)',
      finalizados:total,por_dia:porDia,
      auditoria:{registros_analisados:analisados,paginas,sem_data_de_fim:semFim,paginacao_completa:true},
      observacao:'Consulta por varredura do historico disponibilizado pela API; nao e uma fotografia transacional.',
    };
  }
  return {finalizados};
}
