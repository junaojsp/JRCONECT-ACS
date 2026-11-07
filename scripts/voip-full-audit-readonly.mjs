// Full VoIP product 74 audit. Read-only, no PII or observations in output.
// Run via stdin in existing MCP container. Does not modify scheduler or secrets.
import { createIxcWebserviceClient } from '/app/integrations/ixc-webservice-client.js';
import { listVoipProductContracts } from '/app/integrations/ixc-voip-tools.js';
const marker = '#JRC_VOIP_SOLICITAR#';
async function exact(client,id) {
  const r=await client.list('contrato',{id:'1',page:1,limit:20,
    filters:[{field:'id',oper:'=',value:id}]});
  const rows=Array.isArray(r?.registros)?r.registros:Array.isArray(r?.data)?r.data:null;
  const total=Number(r?.total??r?.total_registros);
  if(total!==1||!rows||rows.length!==1||String(rows[0]?.id)!==id)
    throw new Error('Contrato incompleto');
  return rows[0];
}
try{
 const client=createIxcWebserviceClient();
 const snapshot=await listVoipProductContracts(client);
 if(snapshot.somente_leitura!==true||snapshot.liberacao_automatica!==false||
    snapshot.total_contratos!==snapshot.contratos.length||
    snapshot.total_contratos>200)throw new Error('Snapshot invalido');
 const rows=[];
 for(const c of snapshot.contratos){
   const contract=await exact(client,c.contrato_id);
   if(typeof contract.obs_contrato!=='string'||contract.obs_contrato.length>40000)
     throw new Error('Observacao nao legivel');
   if(String(contract.status)!==c.status_ixc_bruto)
     throw new Error('Status mudou durante auditoria');
   const occurrences=contract.obs_contrato.split(marker).length-1;
   const existing=contract.obs_contrato.includes('JRC_VOIP_CONTRATO=');
   rows.push({
      contrato_id:c.contrato_id,registros_produto_74:c.registros_produto_74,
      status_ixc_bruto:c.status_ixc_bruto,
      marcador_count:occurrences,possui_vinculo_observacao:existing
   });
 }
 process.stdout.write(JSON.stringify({produto_id:'74',
   total_contratos:rows.length,total_registros_produto:snapshot.total_registros_produto,
   contratos:rows,somente_leitura:true,escrita_ixc:false,reserva_did:false})+'\n');
}catch{
 process.stderr.write('BLOQUEADO: auditoria integral IXC incompleta. Nenhum DID modificado.\n');
 process.exitCode=2;
}
