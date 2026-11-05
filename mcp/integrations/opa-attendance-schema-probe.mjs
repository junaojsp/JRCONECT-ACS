#!/usr/bin/env node
// Opa attendance schema probe: READ ONLY, no customer data in output.
import { readFileSync } from 'node:fs';
import { request } from 'node:https';

const base = new URL(process.env.OPA_API_BASE_URL || 'https://opa.jrconect.com');
if (base.protocol !== 'https:' || base.username || base.password ||
    base.pathname !== '/' || base.search || base.hash) {
  throw Error('OPA_API_BASE_URL invalida');
}
const tokenFile = process.env.OPA_API_TOKEN_FILE || '/opt/jrconect-mcp/secrets/opa/api-token';
const token = readFileSync(tokenFile, 'utf8').trim();
if (!token || /\s/.test(token)) throw Error('Token Opa invalido');
const body = JSON.stringify({filter:{},options:{limit:3,skip:0}});
const url = new URL('/api/v1/atendimento',base);
const req = request(url,{
  method:'GET',timeout:12000,
  headers:{Authorization:'Bearer '+token,Accept:'application/json',
  'Content-Type':'application/json','Content-Length':Buffer.byteLength(body)}
},res=>{
  let raw='';
  res.setEncoding('utf8');
  res.on('data',chunk=>{raw+=chunk;if(raw.length>1500000)req.destroy(Error('Resposta acima do limite'));});
  res.on('end',()=>{
    console.log('OPA_ATENDIMENTOS_HTTP:',res.statusCode);
    if(res.statusCode!==200){console.log('OPA: API nao liberada para listagem.');return;}
    let json;try{json=JSON.parse(raw)}catch{console.log('OPA: resposta nao JSON');return;}
    console.log('OPA_STATUS:',String(json.status || 'nao informado').slice(0,40));
    const rows = Array.isArray(json.data)?json.data:Array.isArray(json.data?.rows)?json.data.rows:[];
    console.log('REGISTROS_AMOSTRA:',rows.length);
    console.log('CHAVES_RESPOSTA:',Object.keys(json).sort().join(', '));
    for (const [i,row] of rows.entries()){
      if(!row||typeof row!=='object')continue;
      console.log('ATENDIMENTO_'+(i+1)+'_CAMPOS:',Object.keys(row).sort().join(', '));
      for(const field of ['status','setor','id_atendente','departamento']){
        if(!(field in row))continue;
        const v=row[field];
        // Never print customer IDs, department names or identifiers.
        if(field==='status' && typeof v==='string' && /^[A-Za-z_-]{1,24}$/.test(v)){
          console.log('STATUS_CODIGO_'+(i+1)+':',v);
        }else{
          console.log('FORMATO_'+field.toUpperCase()+'_'+(i+1)+':',Array.isArray(v)?'array':v===null?'null':typeof v,
          v&&typeof v==='object'?'[campos: '+Object.keys(v).sort().join(', ')+']':'');
        }
      }
    }
    if(!rows.length)console.log('OBS: 0 resultados na amostra; pode ser filtro/permissao.');
  });
});
req.on('timeout',()=>req.destroy(Error('Timeout')));
req.on('error',e=>{console.error('OPA_PROBE_ERRO:',e.message.replace(/Bearer\s+\S+/gi,'Bearer [redacted]'));process.exitCode=1;});
req.end(body);
