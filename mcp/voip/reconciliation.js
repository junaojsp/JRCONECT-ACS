// Central VoIP JR Conect: motor puro, sem I/O e sem efeitos no IXC/ACS.
// Somente snapshots COMPLETOS e validados podem gerar pendencias.
// Nunca realiza baixas ou libera numeros automaticamente.
export const VOIP_PRODUCT_ID = '74';

const digits = value => String(value ?? '').trim();

export function findVoipPending({productRows, assignments, productId = VOIP_PRODUCT_ID}) {
  if (!Array.isArray(productRows) || !Array.isArray(assignments)) {
    throw new TypeError('Snapshots de produtos e vinculos devem ser arrays completos');
  }
  const assigned = new Set();
  for (const item of assignments) {
    if (!item || !/^\d+$/.test(digits(item.productContractId))) {
      throw new TypeError('Vinculo sem ID valido');
    }
    if (!['reserved', 'active', 'suspended', 'awaiting_release'].includes(item.status)) {
      throw new TypeError('Status de vinculo desconhecido');
    }
    assigned.add(digits(item.productContractId));
  }
  const seen = new Set();
  const pending = [];
  for (const item of productRows) {
    const id = digits(item?.id);
    const contractId = digits(item?.contractId);
    const product = digits(item?.productId);
    if (!/^\d+$/.test(id) || !/^\d+$/.test(contractId) || !/^\d+$/.test(product)) {
      throw new TypeError('Linha IXC sem IDs validos');
    }
    if (seen.has(id)) throw new TypeError('Snapshot IXC com ID de produto duplicado');
    seen.add(id);
    if (product !== String(productId)) continue;
    // Status diferente ou nao mapeado: nao abre solicitacao ate validacao do IXC.
    if (item.contractStatus !== 'active') continue;
    if (!assigned.has(id)) pending.push({productContractId:id, contractId, productId:product});
  }
  return pending;
}

// Mudancas contratuais geram revisao humana, nunca devolucao automatica ao estoque.
export function reviewRequired({contractStatus, hasProduct74}) {
  if (contractStatus === 'cancelled' || hasProduct74 === false) return 'awaiting_review';
  if (contractStatus === 'suspended') return 'keep_reserved';
  if (contractStatus === 'active' && hasProduct74 === true) return 'keep_assigned';
  return 'unknown_manual_review';
}
