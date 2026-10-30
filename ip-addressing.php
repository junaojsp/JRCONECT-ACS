<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Endereçamento IP';
$currentPage = 'ip-addressing';
$conn = getDBConnection();
$csrfToken = generateCSRFToken();
$notice = '';
$noticeType = 'success';
$schemaReady = true;

try {
    $conn->query('SELECT 1 FROM ip_networks LIMIT 1');
    $conn->query('SELECT 1 FROM ip_addresses LIMIT 1');
    $conn->query('SELECT 1 FROM ip_addressing_history LIMIT 1');
} catch (Throwable $e) {
    $schemaReady = false;
}

function ipamCidrDetails($cidr) {
    if (!preg_match('/^\s*([0-9.]+)\/(\d{1,2})\s*$/', (string)$cidr, $matches)) return null;
    $ip = trim($matches[1]);
    $prefix = (int)$matches[2];
    $ipLong = ip2long($ip);
    if ($ipLong === false || $prefix < 8 || $prefix > 30) return null;
    $mask = -1 << (32 - $prefix);
    $networkLong = $ipLong & $mask;
    $broadcastLong = $networkLong | (~$mask);
    return [
        'cidr' => long2ip($networkLong) . '/' . $prefix,
        'prefix' => $prefix,
        'network_long' => sprintf('%u', $networkLong),
        'broadcast_long' => sprintf('%u', $broadcastLong),
        'network' => long2ip($networkLong),
        'broadcast' => long2ip($broadcastLong),
        'first' => long2ip($networkLong + 1),
        'last' => long2ip($broadcastLong - 1),
        'hosts' => (int)(pow(2, 32 - $prefix) - 2),
    ];
}

function ipamHistory($conn, $networkId, $address, $action, $details) {
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $stmt = $conn->prepare('INSERT INTO ip_addressing_history (network_id,address,action,details,user_id) VALUES (?,?,?,?,?)');
    $stmt->bind_param('isssi', $networkId, $address, $action, $details, $userId);
    $stmt->execute();
}

if ($schemaReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($postedToken)) {
        $notice = 'Sua sessão expirou. Atualize a página e tente novamente.';
        $noticeType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'create_network') {
                $details = ipamCidrDetails($_POST['cidr'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $type = trim($_POST['network_type'] ?? '');
                $location = trim($_POST['location'] ?? '');
                $gateway = trim($_POST['gateway'] ?? '');
                if (!$details) throw new RuntimeException('Informe uma sub-rede IPv4 válida entre /8 e /30.');
                if ($description === '') throw new RuntimeException('Informe uma descrição para a rede.');
                if ($gateway !== '') {
                    $gatewayLong = ip2long($gateway);
                    if ($gatewayLong === false || sprintf('%u', $gatewayLong) <= $details['network_long'] || sprintf('%u', $gatewayLong) >= $details['broadcast_long']) {
                        throw new RuntimeException('O gateway precisa ser um endereço utilizável dentro da sub-rede.');
                    }
                }
                $existing = $conn->query('SELECT cidr FROM ip_networks');
                while ($row = $existing->fetch_assoc()) {
                    $other = ipamCidrDetails($row['cidr']);
                    if ($other && (float)$details['network_long'] <= (float)$other['broadcast_long'] && (float)$other['network_long'] <= (float)$details['broadcast_long']) {
                        throw new RuntimeException('Essa sub-rede se sobrepõe à rede já cadastrada ' . $row['cidr'] . '.');
                    }
                }
                $createdBy = (int)($_SESSION['user_id'] ?? 0);
                $stmt = $conn->prepare("INSERT INTO ip_networks (cidr,description,network_type,location,gateway,created_by) VALUES (?,?,?,?,?,?)");
                $stmt->bind_param('sssssi', $details['cidr'], $description, $type, $location, $gateway, $createdBy);
                $stmt->execute();
                $networkId = $conn->insert_id;
                if ($gateway !== '') {
                    $gatewayHost = 'gateway';
                    $gatewayDescription = 'Gateway da rede';
                    $gatewayStatus = 'reserved';
                    $stmt = $conn->prepare('INSERT INTO ip_addresses (network_id,address,hostname,description,status,created_by) VALUES (?,?,?,?,?,?)');
                    $stmt->bind_param('issssi', $networkId, $gateway, $gatewayHost, $gatewayDescription, $gatewayStatus, $createdBy);
                    $stmt->execute();
                    ipamHistory($conn, $networkId, $gateway, 'ip_reserved', $gatewayDescription);
                }
                ipamHistory($conn, $networkId, null, 'network_created', 'Rede ' . $details['cidr'] . ' cadastrada');
                header('Location: /ip-addressing.php?network=' . $networkId . '&saved=network'); exit;
            }

            if ($action === 'save_ip') {
                $networkId = (int)($_POST['network_id'] ?? 0);
                $address = trim($_POST['address'] ?? '');
                $hostname = trim($_POST['hostname'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $status = ($_POST['status'] ?? '') === 'reserved' ? 'reserved' : 'in_use';
                $stmt = $conn->prepare('SELECT cidr FROM ip_networks WHERE id=?');
                $stmt->bind_param('i', $networkId); $stmt->execute();
                $network = $stmt->get_result()->fetch_assoc();
                $networkDetails = $network ? ipamCidrDetails($network['cidr']) : null;
                $addressLong = ip2long($address);
                if (!$networkDetails) throw new RuntimeException('Selecione uma rede válida.');
                if ($addressLong === false || sprintf('%u', $addressLong) <= $networkDetails['network_long'] || sprintf('%u', $addressLong) >= $networkDetails['broadcast_long']) throw new RuntimeException('O IP precisa ser utilizável e pertencer à sub-rede selecionada.');
                $stmt = $conn->prepare('SELECT id FROM ip_addresses WHERE address=? LIMIT 1');
                $stmt->bind_param('s', $address); $stmt->execute();
                if ($stmt->get_result()->fetch_assoc()) throw new RuntimeException('Esse endereço IP já está cadastrado.');
                $createdBy = (int)($_SESSION['user_id'] ?? 0);
                $stmt = $conn->prepare('INSERT INTO ip_addresses (network_id,address,hostname,description,status,created_by) VALUES (?,?,?,?,?,?)');
                $stmt->bind_param('issssi', $networkId, $address, $hostname, $description, $status, $createdBy);
                $stmt->execute();
                ipamHistory($conn, $networkId, $address, $status === 'reserved' ? 'ip_reserved' : 'ip_assigned', $description ?: ($hostname ?: 'IP cadastrado'));
                header('Location: /ip-addressing.php?network=' . $networkId . '&tab=addresses&saved=ip'); exit;
            }

            if ($action === 'delete_ip') {
                $ipId = (int)($_POST['ip_id'] ?? 0);
                $stmt = $conn->prepare('SELECT id,network_id,address FROM ip_addresses WHERE id=?');
                $stmt->bind_param('i', $ipId); $stmt->execute();
                $ip = $stmt->get_result()->fetch_assoc();
                if (!$ip) throw new RuntimeException('O endereço IP não foi encontrado.');
                $stmt = $conn->prepare('DELETE FROM ip_addresses WHERE id=?'); $stmt->bind_param('i', $ipId); $stmt->execute();
                ipamHistory($conn, (int)$ip['network_id'], $ip['address'], 'ip_released', 'IP liberado');
                header('Location: /ip-addressing.php?network=' . (int)$ip['network_id'] . '&tab=addresses&saved=released'); exit;
            }

            if ($action === 'update_network') {
                $networkId = (int)($_POST['network_id'] ?? 0);
                $description = trim($_POST['description'] ?? '');
                $type = trim($_POST['network_type'] ?? '');
                $location = trim($_POST['location'] ?? '');
                $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
                if ($description === '') throw new RuntimeException('Informe uma descrição para a rede.');
                $stmt = $conn->prepare('UPDATE ip_networks SET description=?,network_type=?,location=?,status=? WHERE id=?');
                $stmt->bind_param('ssssi', $description, $type, $location, $status, $networkId);
                $stmt->execute();
                if ($stmt->affected_rows < 0) throw new RuntimeException('Não foi possível atualizar a rede.');
                ipamHistory($conn, $networkId, null, 'network_updated', 'Dados da rede atualizados');
                header('Location: /ip-addressing.php?network=' . $networkId . '&saved=network_updated'); exit;
            }

            if ($action === 'delete_network') {
                $networkId = (int)($_POST['network_id'] ?? 0);
                $stmt = $conn->prepare('SELECT cidr FROM ip_networks WHERE id=?'); $stmt->bind_param('i', $networkId); $stmt->execute();
                $network = $stmt->get_result()->fetch_assoc();
                if (!$network) throw new RuntimeException('A rede não foi encontrada.');
                $stmt = $conn->prepare('DELETE FROM ip_networks WHERE id=?'); $stmt->bind_param('i', $networkId); $stmt->execute();
                ipamHistory($conn, null, null, 'network_deleted', 'Rede ' . $network['cidr'] . ' excluída');
                header('Location: /ip-addressing.php?saved=deleted'); exit;
            }
        } catch (Throwable $e) {
            $notice = $e instanceof mysqli_sql_exception && $e->getCode() === 1062 ? 'Esse valor já está cadastrado.' : $e->getMessage();
            $noticeType = 'danger';
        }
    }
}

if (isset($_GET['saved'])) {
    $notice = ['network'=>'Rede cadastrada com sucesso.','network_updated'=>'Rede atualizada.','ip'=>'Endereço IP cadastrado com sucesso.','released'=>'IP liberado com sucesso.','deleted'=>'Rede removida.'][$_GET['saved']] ?? 'Alteração salva.';
}

$networks = [];
$selectedId = (int)($_GET['network'] ?? 0);
$selected = null; $selectedDetails = null; $ipRows = []; $historyRows = []; $dnsRows = [];
$totalUsed = 0; $totalReserved = 0; $totalFree = 0;
if ($schemaReady) {
    $result = $conn->query("SELECT n.*, COUNT(a.id) AS allocated, SUM(a.status='in_use') AS in_use, SUM(a.status='reserved') AS reserved FROM ip_networks n LEFT JOIN ip_addresses a ON a.network_id=n.id GROUP BY n.id ORDER BY INET_ATON(n.cidr) IS NULL, INET_ATON(SUBSTRING_INDEX(n.cidr,'/',1)), n.cidr");
    while ($row = $result->fetch_assoc()) {
        $row['details'] = ipamCidrDetails($row['cidr']);
        $row['in_use'] = (int)$row['in_use']; $row['reserved'] = (int)$row['reserved']; $row['allocated'] = (int)$row['allocated'];
        $row['free'] = max(0, ($row['details']['hosts'] ?? 0) - $row['allocated']);
        $totalUsed += $row['in_use']; $totalReserved += $row['reserved']; $totalFree += $row['free'];
        $networks[] = $row;
    }
    if (!$selectedId && $networks) $selectedId = (int)$networks[0]['id'];
    foreach ($networks as $row) if ((int)$row['id'] === $selectedId) { $selected = $row; $selectedDetails = $row['details']; break; }
    if ($selected) {
        $stmt = $conn->prepare('SELECT * FROM ip_addresses WHERE network_id=? ORDER BY INET_ATON(address)'); $stmt->bind_param('i', $selectedId); $stmt->execute();
        $rs = $stmt->get_result(); while ($r = $rs->fetch_assoc()) $ipRows[] = $r;
        $stmt = $conn->prepare('SELECT * FROM ip_addressing_history WHERE network_id=? ORDER BY created_at DESC LIMIT 100'); $stmt->bind_param('i', $selectedId); $stmt->execute();
        $rs = $stmt->get_result(); while ($r = $rs->fetch_assoc()) $historyRows[] = $r;
        foreach ($ipRows as $r) if ($r['hostname']) $dnsRows[] = $r;
    }
}

include __DIR__ . '/views/layouts/header.php';
?>

<style>
.ipam{--ipam-red:#ef1017;--ipam-panel:#14181e;--ipam-line:#252b34;--ipam-muted:#91a0b2;color:#edf2f8}
.ipam-head{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:18px}.ipam-title{display:flex;align-items:center;gap:14px}.ipam-title-icon{width:52px;height:52px;border-radius:13px;background:#191e25;display:grid;place-items:center;font-size:24px;color:#f22}.ipam-title h2{font-size:22px;margin:0;font-weight:700}.ipam-title p{color:var(--ipam-muted);margin:5px 0 0;font-size:13px}
.ipam-btn{border:1px solid #f1161c;background:#e91017;color:white;border-radius:8px;padding:9px 14px;font-weight:650}.ipam-btn:hover{background:#c90d13;color:white}.ipam-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.ipam-stat,.ipam-panel,.ipam-details{background:var(--ipam-panel);border:1px solid var(--ipam-line);border-radius:12px}.ipam-stat{padding:17px;display:flex;align-items:center;gap:14px;min-height:96px}.ipam-stat-icon{height:48px;width:48px;border-radius:11px;display:grid;place-items:center;font-size:21px;background:#252b34;color:#cad5e3}.ipam-stat:nth-child(2) .ipam-stat-icon{background:#0e3829;color:#28d886}.ipam-stat:nth-child(3) .ipam-stat-icon{background:#102d4b;color:#45b7ff}.ipam-stat:nth-child(4) .ipam-stat-icon{background:#3b2c10;color:#f4a928}.ipam-stat strong{display:block;font-size:24px;line-height:1.15}.ipam-stat small{display:block;color:var(--ipam-muted);margin-top:4px}
.ipam-tabs{display:flex;gap:22px;border-bottom:1px solid var(--ipam-line);margin-bottom:14px}.ipam-tabs a{padding:10px 5px;color:#aab5c4;text-decoration:none;border-bottom:2px solid transparent}.ipam-tabs a.active{color:#ff2630;border-color:#f1161c;font-weight:700}.ipam-layout{display:grid;grid-template-columns:minmax(0,1fr) 328px;gap:14px}.ipam-panel{padding:14px}.ipam-toolbar{display:flex;gap:10px;align-items:center;margin:12px 0}.ipam-search,.ipam-select,.ipam-input{background:#111923!important;border:1px solid #29394d!important;color:#e8eef8!important;border-radius:7px!important}.ipam-search{flex:1;padding:9px 11px}.ipam-select{padding:9px 10px}.ipam-table{width:100%;border-collapse:collapse;font-size:13px}.ipam-table th{font-size:11px;color:#9eacbc;text-align:left;font-weight:600;padding:10px 8px;border-bottom:1px solid var(--ipam-line);white-space:nowrap}.ipam-table td{padding:9px 8px;border-bottom:1px solid #20262d;vertical-align:middle}.ipam-table tbody tr:hover{background:#191f27}.ipam-table a{color:#40b7ff;text-decoration:none}.ipam-pill{padding:4px 9px;border-radius:20px;font-size:11px;font-weight:700;background:#0e3829;color:#43dc91}.ipam-pill.reserved{background:#3b2c10;color:#f2b63e}.ipam-progress{height:7px;background:#28313d;border-radius:9px;overflow:hidden;min-width:82px}.ipam-progress span{height:100%;display:block;background:linear-gradient(90deg,#28c777,#31e98c);border-radius:9px}.ipam-details{padding:16px;height:max-content}.ipam-details h3{font-size:15px;font-weight:700;margin:0 0 14px}.ipam-details dl{display:grid;grid-template-columns:1fr auto;gap:7px;margin:0;font-size:12px}.ipam-details dt{color:#aab5c4;font-weight:500}.ipam-details dd{margin:0;text-align:right}.ipam-detail-cidr{border-left:4px solid #f11;padding-left:10px;margin-bottom:15px}.ipam-detail-cidr strong{font-size:17px}.ipam-detail-cidr small{display:block;color:var(--ipam-muted);margin-top:4px}.ipam-empty{padding:25px 8px;color:var(--ipam-muted);font-size:13px}.ipam-actions{display:flex;gap:6px}.ipam-icon-btn{border:0;background:transparent;color:#c9d3df;padding:4px 6px}.ipam-danger{color:#f54b51}.ipam-help{font-size:12px;color:var(--ipam-muted)}.ipam-modal .modal-content{background:#161b22;color:#edf2f8;border:1px solid #303944}.ipam-modal .form-label{color:#b9c5d2;font-size:12px}.ipam-modal .btn-close{filter:invert(1)}.ipam-inline-form{display:inline}
@media(max-width:1100px){.ipam-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.ipam-layout{grid-template-columns:1fr}.ipam-details{order:-1}}@media(max-width:650px){.ipam-head{align-items:flex-start;flex-direction:column}.ipam-stats{grid-template-columns:1fr 1fr}.ipam-stat{padding:12px;gap:9px}.ipam-stat-icon{width:36px;height:36px}.ipam-table-wrap{overflow:auto}.ipam-table{min-width:760px}.ipam-tabs{overflow:auto;gap:12px}.ipam-tabs a{white-space:nowrap}}
</style>

<main class="ipam">
    <?php if (!$schemaReady): ?>
        <div class="alert alert-warning"><strong>Preparação necessária:</strong> as tabelas do módulo ainda não existem. Execute <code>migrations/20261030_ip_addressing.sql</code> no banco do ACS e recarregue esta página.</div>
    <?php else: ?>
    <?php if ($notice): ?><div class="alert alert-<?php echo htmlspecialchars($noticeType); ?> alert-dismissible fade show"><?php echo htmlspecialchars($notice); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <div class="ipam-head">
        <div class="ipam-title"><div class="ipam-title-icon"><i class="bi bi-diagram-3"></i></div><div><h2>Endereçamento IP</h2><p>Documentação de redes e endereços IP da JR Conect</p></div></div>
        <button class="ipam-btn" data-bs-toggle="modal" data-bs-target="#networkModal"><i class="bi bi-plus-lg"></i> Nova rede</button>
    </div>
    <section class="ipam-stats">
        <div class="ipam-stat"><div class="ipam-stat-icon"><i class="bi bi-diagram-3"></i></div><div><strong><?php echo count($networks); ?></strong><small>Redes cadastradas<br>Total de sub-redes</small></div></div>
        <div class="ipam-stat"><div class="ipam-stat-icon"><i class="bi bi-hdd-network"></i></div><div><strong><?php echo number_format($totalUsed,0,',','.'); ?></strong><small>IPs em uso</small></div></div>
        <div class="ipam-stat"><div class="ipam-stat-icon"><i class="bi bi-cloud"></i></div><div><strong><?php echo number_format($totalFree,0,',','.'); ?></strong><small>IPs livres</small></div></div>
        <div class="ipam-stat"><div class="ipam-stat-icon"><i class="bi bi-bookmark"></i></div><div><strong><?php echo number_format($totalReserved,0,',','.'); ?></strong><small>IPs reservados</small></div></div>
    </section>
    <nav class="ipam-tabs">
        <?php $tab = $_GET['tab'] ?? 'networks'; ?>
        <a class="<?php echo $tab==='networks'?'active':''; ?>" href="?network=<?php echo $selectedId; ?>"><i class="bi bi-diagram-3"></i> Redes</a>
        <a class="<?php echo $tab==='addresses'?'active':''; ?>" href="?network=<?php echo $selectedId; ?>&tab=addresses"><i class="bi bi-ethernet"></i> Endereços IP</a>
        <a class="<?php echo $tab==='dns'?'active':''; ?>" href="?network=<?php echo $selectedId; ?>&tab=dns"><i class="bi bi-globe2"></i> DNS / Hostnames</a>
        <a class="<?php echo $tab==='history'?'active':''; ?>" href="?network=<?php echo $selectedId; ?>&tab=history"><i class="bi bi-clock-history"></i> Histórico</a>
    </nav>
    <div class="ipam-layout">
        <section class="ipam-panel">
            <div class="d-flex justify-content-between align-items-center"><strong><?php echo ['addresses'=>'Endereços cadastrados','dns'=>'DNS / Hostnames','history'=>'Histórico de alterações'][$tab] ?? 'Redes cadastradas'; ?> (<?php echo $tab==='addresses'?count($ipRows):($tab==='dns'?count($dnsRows):($tab==='history'?count($historyRows):count($networks))); ?>)</strong><div class="d-flex gap-2"><button class="ipam-btn" data-bs-toggle="modal" data-bs-target="#networkModal"><i class="bi bi-plus-lg"></i> Nova rede</button><?php if($selected): ?><button class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#ipModal"><i class="bi bi-plus"></i> Adicionar IP</button><?php endif; ?></div></div>
            <?php if ($tab==='networks'): ?>
            <div class="ipam-toolbar"><input class="ipam-search" id="networkSearch" placeholder="Buscar rede, descrição ou localização..."><select class="ipam-select" id="statusFilter"><option value="">Todos os status</option><option value="active">Ativas</option><option value="inactive">Inativas</option></select></div>
            <div class="ipam-table-wrap"><table class="ipam-table"><thead><tr><th>REDE</th><th>DESCRIÇÃO</th><th>LOCALIZAÇÃO</th><th>USO</th><th>LIVRES</th><th>EM USO</th><th>RESERVADOS</th><th>STATUS</th><th>AÇÕES</th></tr></thead><tbody id="networkRows">
            <?php foreach($networks as $n): $pct=$n['details']['hosts']?round(($n['allocated']/$n['details']['hosts'])*100):0; ?>
            <tr data-search="<?php echo htmlspecialchars(strtolower($n['cidr'].' '.$n['description'].' '.$n['location'])); ?>" data-status="<?php echo htmlspecialchars($n['status']); ?>"><td><a href="?network=<?php echo (int)$n['id']; ?>"><?php echo htmlspecialchars($n['cidr']); ?></a></td><td><?php echo htmlspecialchars($n['description']); ?></td><td><?php echo htmlspecialchars($n['location'] ?: '—'); ?></td><td><div><?php echo $pct; ?>%</div><div class="ipam-progress"><span style="width:<?php echo min(100,$pct); ?>%"></span></div></td><td><?php echo $n['free']; ?></td><td><?php echo $n['in_use']; ?></td><td><?php echo $n['reserved']; ?></td><td><span class="ipam-pill <?php echo $n['status']==='inactive'?'reserved':''; ?>"><?php echo $n['status']==='active'?'● Ativa':'Inativa'; ?></span></td><td><button class="ipam-icon-btn" title="Selecionar rede" onclick="location.href='?network=<?php echo (int)$n['id']; ?>'"><i class="bi bi-eye"></i></button><form class="ipam-inline-form" method="post" onsubmit="return confirm('Excluir esta rede e os IPs vinculados?')"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="action" value="delete_network"><input type="hidden" name="network_id" value="<?php echo (int)$n['id']; ?>"><button class="ipam-icon-btn ipam-danger" title="Excluir"><i class="bi bi-trash"></i></button></form></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php elseif ($tab==='addresses' || $tab==='dns'): $rows=$tab==='dns'?$dnsRows:$ipRows; ?>
            <div class="ipam-toolbar"><input class="ipam-search" id="ipSearch" placeholder="Buscar IP, hostname ou descrição..."></div><div class="ipam-table-wrap"><table class="ipam-table"><thead><tr><th>ENDEREÇO IP</th><th>HOSTNAME</th><th>DESCRIÇÃO</th><th>STATUS</th><th>AÇÕES</th></tr></thead><tbody><?php foreach($rows as $ip): ?><tr class="ip-search-row"><td><?php echo htmlspecialchars($ip['address']); ?></td><td><?php echo htmlspecialchars($ip['hostname'] ?: '—'); ?></td><td><?php echo htmlspecialchars($ip['description'] ?: '—'); ?></td><td><span class="ipam-pill <?php echo $ip['status']==='reserved'?'reserved':''; ?>"><?php echo $ip['status']==='reserved'?'Reservado':'Em uso'; ?></span></td><td><form class="ipam-inline-form" method="post" onsubmit="return confirm('Liberar este endereço IP?')"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="action" value="delete_ip"><input type="hidden" name="ip_id" value="<?php echo (int)$ip['id']; ?>"><button class="ipam-icon-btn ipam-danger" title="Liberar IP"><i class="bi bi-trash"></i></button></form></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="5" class="ipam-empty">Nenhum endereço cadastrado nesta rede.</td></tr><?php endif; ?></tbody></table></div>
            <?php elseif($tab==='history'): ?><div class="ipam-table-wrap"><table class="ipam-table"><thead><tr><th>DATA</th><th>AÇÃO</th><th>IP</th><th>DETALHES</th></tr></thead><tbody><?php foreach($historyRows as $h): ?><tr><td><?php echo htmlspecialchars($h['created_at']); ?></td><td><?php echo htmlspecialchars(str_replace('_',' ',$h['action'])); ?></td><td><?php echo htmlspecialchars($h['address'] ?: '—'); ?></td><td><?php echo htmlspecialchars($h['details'] ?: '—'); ?></td></tr><?php endforeach; ?><?php if(!$historyRows): ?><tr><td colspan="4" class="ipam-empty">Nenhuma alteração registrada.</td></tr><?php endif; ?></tbody></table></div>
            <?php endif; ?>
        </section>
        <aside class="ipam-details"><h3>Detalhes da rede</h3><?php if($selected): ?><div class="d-flex justify-content-between align-items-start"><div class="ipam-detail-cidr"><strong><?php echo htmlspecialchars($selected['cidr']); ?></strong> <span class="ipam-pill <?php echo $selected['status']==='inactive'?'reserved':''; ?>"><?php echo $selected['status']==='active'?'◆ Ativa':'Inativa'; ?></span><small><?php echo htmlspecialchars($selected['description']); ?></small></div><button class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#editNetworkModal"><i class="bi bi-pencil"></i> Editar</button></div><dl><dt>Tipo:</dt><dd><?php echo htmlspecialchars($selected['network_type'] ?: '—'); ?></dd><dt>Localização:</dt><dd><?php echo htmlspecialchars($selected['location'] ?: '—'); ?></dd><dt>Gateway:</dt><dd><?php echo htmlspecialchars($selected['gateway'] ?: '—'); ?></dd><dt>Máscara:</dt><dd><?php echo htmlspecialchars(long2ip(-1 << (32-$selectedDetails['prefix']))); ?> (/<?php echo $selectedDetails['prefix']; ?>)</dd><dt>Faixa:</dt><dd><?php echo htmlspecialchars($selectedDetails['first'].' - '.$selectedDetails['last']); ?></dd><dt>Broadcast:</dt><dd><?php echo htmlspecialchars($selectedDetails['broadcast']); ?></dd><dt>Total de IPs:</dt><dd><?php echo $selectedDetails['hosts']; ?></dd><dt>Em uso:</dt><dd><?php echo $selected['in_use']; ?></dd><dt>Livres:</dt><dd><?php echo $selected['free']; ?></dd><dt>Reservados:</dt><dd><?php echo $selected['reserved']; ?></dd></dl><div class="ipam-progress mt-3"><span style="width:<?php echo min(100,$selectedDetails['hosts']?round(($selected['allocated']/$selectedDetails['hosts'])*100):0); ?>%"></span></div><div class="text-end small mt-1"><?php echo $selectedDetails['hosts']?round(($selected['allocated']/$selectedDetails['hosts'])*100):0; ?>% utilizado</div><hr><div class="fw-semibold small mb-2">Ações rápidas</div><a class="btn btn-sm btn-outline-light w-100 text-start mb-1" href="?network=<?php echo $selectedId; ?>&tab=addresses"><i class="bi bi-list-ul me-2"></i>Ver endereços desta rede</a><button class="btn btn-sm btn-outline-light w-100 text-start mb-1" data-bs-toggle="modal" data-bs-target="#ipModal"><i class="bi bi-plus me-2"></i>Adicionar novo IP</button><form method="post" onsubmit="return confirm('Excluir esta rede e todos os IPs vinculados?')"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="action" value="delete_network"><input type="hidden" name="network_id" value="<?php echo $selectedId; ?>"><button class="btn btn-sm btn-outline-danger w-100 text-start"><i class="bi bi-trash me-2"></i>Excluir rede</button></form><?php else: ?><div class="ipam-empty">Cadastre ou selecione uma rede para ver o gateway, a faixa e os IPs disponíveis.</div><?php endif; ?></aside>
    </div>

    <div class="modal fade ipam-modal" id="networkModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><form method="post"><div class="modal-header"><h5 class="modal-title">Cadastrar rede</h5><button class="btn-close" data-bs-dismiss="modal" type="button"></button></div><div class="modal-body"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="action" value="create_network"><div class="row g-3"><div class="col-md-6"><label class="form-label">Rede em CIDR</label><input class="form-control ipam-input" name="cidr" placeholder="10.0.0.0/24" required><div class="ipam-help mt-1">IPv4 entre /8 e /30; sub-redes sobrepostas são bloqueadas.</div></div><div class="col-md-6"><label class="form-label">Gateway</label><input class="form-control ipam-input" name="gateway" placeholder="10.0.0.1"></div><div class="col-md-6"><label class="form-label">Descrição</label><input class="form-control ipam-input" name="description" placeholder="Gerência Core" required maxlength="160"></div><div class="col-md-6"><label class="form-label">Tipo</label><input class="form-control ipam-input" name="network_type" placeholder="Gerência, OLT, Servidores..."></div><div class="col-12"><label class="form-label">Localização</label><input class="form-control ipam-input" name="location" placeholder="POP Central, Franco da Rocha..."></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="ipam-btn">Salvar rede</button></div></form></div></div></div>
    <div class="modal fade ipam-modal" id="ipModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post"><div class="modal-header"><h5 class="modal-title">Cadastrar endereço IP</h5><button class="btn-close" data-bs-dismiss="modal" type="button"></button></div><div class="modal-body"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="action" value="save_ip"><input type="hidden" name="network_id" value="<?php echo $selectedId; ?>"><label class="form-label">Endereço IP</label><input class="form-control ipam-input mb-3" name="address" placeholder="10.0.0.10" required><label class="form-label">Hostname / DNS</label><input class="form-control ipam-input mb-3" name="hostname" placeholder="switch-core-01"><label class="form-label">Descrição</label><input class="form-control ipam-input mb-3" name="description" placeholder="Equipamento ou finalidade"><label class="form-label">Status</label><select class="form-select ipam-select w-100" name="status"><option value="in_use">Em uso</option><option value="reserved">Reservado</option></select></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="ipam-btn">Salvar IP</button></div></form></div></div></div>
    <?php if($selected): ?><div class="modal fade ipam-modal" id="editNetworkModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post"><div class="modal-header"><h5 class="modal-title">Editar rede <?php echo htmlspecialchars($selected['cidr']); ?></h5><button class="btn-close" data-bs-dismiss="modal" type="button"></button></div><div class="modal-body"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="action" value="update_network"><input type="hidden" name="network_id" value="<?php echo $selectedId; ?>"><label class="form-label">Descrição</label><input class="form-control ipam-input mb-3" name="description" value="<?php echo htmlspecialchars($selected['description']); ?>" maxlength="160" required><label class="form-label">Tipo</label><input class="form-control ipam-input mb-3" name="network_type" value="<?php echo htmlspecialchars($selected['network_type'] ?? ''); ?>"><label class="form-label">Localização</label><input class="form-control ipam-input mb-3" name="location" value="<?php echo htmlspecialchars($selected['location'] ?? ''); ?>"><label class="form-label">Status</label><select class="form-select ipam-select w-100" name="status"><option value="active" <?php echo $selected['status']==='active'?'selected':''; ?>>Ativa</option><option value="inactive" <?php echo $selected['status']==='inactive'?'selected':''; ?>>Inativa</option></select></div><div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="ipam-btn">Salvar alterações</button></div></form></div></div></div><?php endif; ?>
    <?php endif; ?>
</main>
<script>
document.getElementById('networkSearch')?.addEventListener('input',filterNetworks);document.getElementById('statusFilter')?.addEventListener('change',filterNetworks);
function filterNetworks(){const q=(document.getElementById('networkSearch')?.value||'').toLowerCase(),s=document.getElementById('statusFilter')?.value||'';document.querySelectorAll('#networkRows tr').forEach(r=>r.hidden=!(r.dataset.search.includes(q)&&(!s||r.dataset.status===s)));}
document.getElementById('ipSearch')?.addEventListener('input',e=>{const q=e.target.value.toLowerCase();document.querySelectorAll('.ip-search-row').forEach(r=>r.hidden=!r.innerText.toLowerCase().includes(q));});
</script>

<?php include __DIR__ . '/views/layouts/footer.php'; ?>
