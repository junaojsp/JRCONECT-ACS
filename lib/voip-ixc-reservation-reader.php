<?php
declare(strict_types=1);

/**
 * Dedicated GET-only client for unattended VoIP reservation.
 * Never modifies IXC. Does not use/modify the MCP container credential.
 */
final class VoipIxcReservationReader
{
    private string $credential;
    private $transport;
    public const ENDPOINT = 'https://cda.jrconect.com/webservice/v1/';
    public const SECRET = '/etc/jrconect/voip-ixc-writer.basic';

    public function __construct(string $credentialFile = self::SECRET, ?callable $transport = null)
    {
        if (!is_file($credentialFile) || is_link($credentialFile)) {
            throw new RuntimeException('Credencial IXC de consulta ausente.');
        }
        $st=stat($credentialFile);
        if (!is_array($st) || $st['uid'] !== 0 || ($st['mode'] & 0077) !== 0) {
            throw new RuntimeException('Credencial IXC precisa ser root:0600.');
        }
        $raw=file_get_contents($credentialFile);
        $secret=is_string($raw)?trim($raw):'';
        if (!preg_match('/^[^:\\r\\n ]{1,150}:[^\\r\\n ]{6,700}$/D',$secret)) {
            throw new RuntimeException('Credencial IXC invalida.');
        }
        $this->credential=$secret;
        $this->transport=$transport;
    }

    private function requestPage(string $resource, array $body): array
    {
        if ($this->transport !== null) {
            $response=($this->transport)($resource,$body['query'],$body);
        } else {
            if (!function_exists('curl_init')) throw new RuntimeException('cURL nao instalado.');
            $ch=curl_init(self::ENDPOINT.$resource);
            curl_setopt_array($ch,[
                CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'GET',
                CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_HTTPHEADER=>[
                    'Authorization: Basic '.base64_encode($this->credential),
                    'ixcsoft: listar','Content-Type: application/json','Accept: application/json'
                ],
                CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR),
            ]);
            try {
                $raw=curl_exec($ch);
                $status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
                if (!is_string($raw) || $status!==200 || strlen($raw)>1048576) {
                    // Fixed diagnostics only: no response body, URL or credential.
                    $reason=curl_errno($ch)===CURLE_OPERATION_TIMEDOUT?'timeout':'http_ou_transporte';
                    throw new RuntimeException('IXC GET '.$resource.' pagina='.$body['page'].' '.$reason);
                }
            } finally { curl_close($ch); }
            $response=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        }
        if (!is_array($response) || isset($response['error']) ||
            ($response['success']??null)===false ||
            strtolower((string)($response['status']??''))==='error') throw new RuntimeException('IXC GET invalido.');
        return $response;
    }

    private static function id($value): string
    {
        $id=(string)$value;
        if (!preg_match('/^[0-9]{1,12}$/D',$id)) throw new RuntimeException('ID IXC invalido.');
        return $id;
    }

    /** Bounded pagination; reject missing totals, partial or duplicate rows. */
    private function readAll(string $resource, string $field, string $query, string $oper='=', array $extra=[]): array
    {
        if (!in_array($resource,['cliente','cliente_contrato','vd_contratos_produtos'],true) ||
            !in_array($field,['id','id_produto','id_contrato'],true) ||
            !in_array($oper,['=','IN'],true) ||
            !preg_match('/^[0-9]{1,12}(?:,[0-9]{1,12}){0,19}$/D',$query)) {
            throw new RuntimeException('Consulta IXC fora do escopo.');
        }
        $out=[];$seen=[];$expected=null;
        for ($page=1;$page<=10;$page++) {
            $body=['qtype'=>$resource.'.'.$field,'query'=>$query,'oper'=>$oper,
                'page'=>(string)$page,'rp'=>'20','sortname'=>$resource.'.id','sortorder'=>'desc'];
            if ($extra) $body['grid_param']=json_encode($extra,JSON_THROW_ON_ERROR);
            $response=$this->requestPage($resource,$body);
            $rows=$response['registros']??$response['data']??null;
            $total=$response['total']??$response['total_registros']??null;
            if ((!is_int($total) && !is_string($total)) ||
                !preg_match('/^[0-9]{1,3}$/D',(string)$total) ||
                (int)$total>200 || !is_array($rows) || !array_is_list($rows) || count($rows)>20) {
                throw new RuntimeException('Paginacao IXC invalida.');
            }
            $total=(int)$total;
            if ($expected!==null && $expected!==$total) throw new RuntimeException('Total IXC alterado.');
            $expected=$total;
            foreach ($rows as $row) {
                if (!is_array($row)) throw new RuntimeException('Registro IXC invalido.');
                $id=self::id($row['id']??'');
                if (isset($seen[$id])) throw new RuntimeException('Registro IXC duplicado.');
                $seen[$id]=true;$out[]=$row;
            }
            if (count($out)===$expected) return $out;
            if (!$rows || count($out)>$expected) throw new RuntimeException('Snapshot IXC parcial.');
        }
        throw new RuntimeException('Limite de paginas IXC.');
    }

    /** Same snapshot consumed by the existing monitor, without customer lookups. */
    public function snapshot(): array
    {
        $products=$this->readAll('vd_contratos_produtos','id_produto','74');
        $grouped=[];
        foreach ($products as $row) {
            if ((string)($row['id_produto']??'')!=='74') throw new RuntimeException('Filtro produto ignorado.');
            $id=self::id($row['id_contrato']??'');
            $grouped[$id][]=self::id($row['id']);
        }
        $contracts=[];
        foreach (array_chunk(array_keys($grouped),20) as $batch) {
            $wanted=array_fill_keys($batch,true);
            $rows=$this->readAll('cliente_contrato','id',implode(',',$batch),'IN');
            foreach ($rows as $row) {
                $id=self::id($row['id']);
                if (!isset($wanted[$id]) || !is_string($row['status']??null) ||
                    !trim($row['status']) || strlen($row['status'])>40) {
                    throw new RuntimeException('Contrato IXC divergente.');
                }
                unset($wanted[$id]);
                $observation=(string)($row['obs_contrato']??'');
                $contracts[]=['contrato_id'=>$id,'status_ixc_bruto'=>$row['status'],
                    'registros_produto_74'=>$grouped[$id],
                    'voip_cancelar'=>substr_count($observation,'#JRC_VOIP_CANCELAR#')===1];
            }
            if ($wanted) throw new RuntimeException('Contratos IXC ausentes.');
        }
        return ['produto_id'=>'74','total_registros_produto'=>count($products),
            'total_contratos'=>count($contracts),'contratos'=>$contracts,
            'somente_leitura'=>true,'liberacao_automatica'=>false];
    }

    public function readFresh(string $contractId, string $productRecordId): array
    {
        self::id($contractId);self::id($productRecordId);
        $contracts=$this->readAll('cliente_contrato','id',$contractId);
        if (count($contracts)!==1 || (string)$contracts[0]['id']!==$contractId) {
            throw new RuntimeException('Contrato IXC nao unico.');
        }
        // Query ALL product-74 rows for this contract, not just the old row ID.
        $products=$this->readAll('vd_contratos_produtos','id_contrato',$contractId,'=',[
            ['TB'=>'vd_contratos_produtos.id_produto','OP'=>'=','P'=>'74']
        ]);
        if (count($products)!==1 || (string)$products[0]['id']!==$productRecordId ||
            (string)($products[0]['id_contrato']??'')!==$contractId ||
            (string)($products[0]['id_produto']??'')!=='74') {
            throw new RuntimeException('Produto 74 ausente, multiplo ou divergente.');
        }
        $clientId=self::id($contracts[0]['id_cliente']??'');
        $clients=$this->readAll('cliente','id',$clientId);
        if (count($clients)!==1 || (string)($clients[0]['id']??'')!==$clientId) {
            throw new RuntimeException('Cliente IXC nao unico.');
        }
        $clientName=trim((string)($clients[0]['razao']??''));
        if ($clientName==='') $clientName=trim((string)($clients[0]['fantasia']??''));
        if ($clientName==='' || strlen($clientName)>250 || preg_match('/[\x00-\x1f]/',$clientName)) {
            throw new RuntimeException('Nome do cliente IXC invalido.');
        }
        return ['contract'=>$contracts[0],'product_rows'=>$products,
            'client'=>['id'=>$clientId,'name'=>$clientName]];
    }
}
