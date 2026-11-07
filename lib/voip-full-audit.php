<?php
declare(strict_types=1);

/**
 * Full-system VoIP audit. No IXC writes or stock mutations.
 * Each IXC product 74 contract is compared with confirmed stock links.
 */
final class VoipFullAudit {
    public static function compare(array $input, array $stock): array {
        if (($input['produto_id'] ?? null) !== '74' ||
            ($input['somente_leitura'] ?? null) !== true ||
            ($input['escrita_ixc'] ?? null) !== false ||
            ($input['reserva_did'] ?? null) !== false ||
            !is_array($input['contratos'] ?? null) ||
            !is_int($input['total_contratos'] ?? null) ||
            !is_int($input['total_registros_produto'] ?? null) ||
            count($input['contratos']) !== $input['total_contratos'] ||
            count($input['contratos']) > 200 ||
            ($stock['version'] ?? null) !== 1 ||
            !is_array($stock['numbers'] ?? null)) {
            throw new RuntimeException('Snapshot da auditoria invalido ou parcial.');
        }
        $linked = []; $byProduct = []; $free = 0; $occupied = 0;
        foreach ($stock['numbers'] as $number=>$item) {
            if (!is_array($item) || !preg_match('/^[1-9][0-9]{9}$/D',(string)$number))
                throw new RuntimeException('DID invalido no estoque');
            $status=$item['status']??null;
            if ($status==='disponivel' && empty($item['contract_id']) &&
                empty($item['product_record_id']) && empty($item['reservation_id'])) $free++;
            if ($status==='ocupado') $occupied++;
            $id=(string)($item['contract_id']??'');
            $pid=(string)($item['product_record_id']??'');
            if ($id!=='' || $pid!=='') {
                if ($id==='' || $pid==='' || isset($linked[$id]) || isset($byProduct[$pid]))
                    throw new RuntimeException('Vinculo de estoque incompleto ou duplicado');
                $linked[$id]=['did'=>(string)$number,'produto'=>$pid,'status'=>$status];
                $byProduct[$pid]=$id;
            }
        }
        $seen=[]; $seenProducts=[]; $rows=[];
        $summary=['contratos'=>0,'ja_vinculados'=>0,'solicitacoes_sem_did'=>0,
            'sem_marcador_sem_did'=>0,'marcador_ja_vinculado'=>0,'observacao_ja_contem_vinculo'=>0,
            'marcador_duplicado'=>0,'divergencia'=>0,'estoque_disponivel'=>$free,
            'estoque_ocupado'=>$occupied];
        foreach($input['contratos'] as $row) {
            $id=$row['contrato_id']??null;
            $ids=$row['registros_produto_74']??null;
            $status=$row['status_ixc_bruto']??null;
            $marker=$row['marcador_count']??null;
            $existing=$row['possui_vinculo_observacao']??null;
            if(!is_string($id)||!preg_match('/^[0-9]{1,12}$/D',$id)||isset($seen[$id])||
                !is_array($ids)||count($ids)<1||!is_string($status)||strlen($status)>40||
                !is_int($marker)||$marker<0||$marker>40000||!is_bool($existing))
                throw new RuntimeException('Linha IXC inválida');
            $seen[$id]=true;
            foreach($ids as $pid) {
                if(!is_string($pid)||!preg_match('/^[0-9]{1,12}$/D',$pid)||isset($seenProducts[$pid]))
                    throw new RuntimeException('Produto 74 duplicado ou invalido');
                $seenProducts[$pid]=true;
            }
            $link=$linked[$id]??null;
            $match=$link!==null && count($ids)===1 && $link['produto']===$ids[0];
            if($link!==null && !$match) $type='divergencia';
            elseif($marker>1) $type='marcador_duplicado';
            elseif($existing && $marker>0) $type='divergencia';
            elseif($existing && !$link) $type='observacao_ja_contem_vinculo';
            elseif($link && $marker===1) $type='marcador_ja_vinculado';
            elseif($link) $type='ja_vinculados';
            elseif($marker===1 && count($ids)===1) $type='solicitacoes_sem_did';
            elseif($marker===1) $type='divergencia';
            else $type='sem_marcador_sem_did';
            $summary[$type]++;
            $rows[]=['contrato_id'=>$id,'produto_registros'=>$ids,
                'status_ixc_bruto'=>$status,'categoria'=>$type,
                'tem_marcador'=>$marker>0,'did_vinculado'=>$link['did']??null,
                'nenhuma_alteracao'=>true];
            $summary['contratos']++;
        }
        if(count($seenProducts)!==$input['total_registros_produto'])
            throw new RuntimeException('Contagem de produtos parcial');
        foreach($linked as $id=>$link) {
            if(!isset($seen[$id])) {
                $summary['divergencia']++;
                $rows[]=['contrato_id'=>(string)$id,'produto_registros'=>[$link['produto']],
                    'status_ixc_bruto'=>null,'categoria'=>'vinculo_sem_produto_74',
                    'tem_marcador'=>false,'did_vinculado'=>$link['did'],'nenhuma_alteracao'=>true];
            }
        }
        usort($rows,static fn($a,$b)=>((int)$a['contrato_id']<=> (int)$b['contrato_id']));
        return ['summary'=>$summary,'rows'=>$rows,'modo'=>'somente_leitura',
            'reserva_did'=>false,'escrita_ixc'=>false,'liberacao_automatica'=>false];
    }
}
