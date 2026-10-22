<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Mapa da Rede';
$currentPage = 'map';

$genieacsConfigured = isGenieACSConfigured();

include __DIR__ . '/views/layouts/header.php';
?>

<?php if (!$genieacsConfigured): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i>
        O GenieACS ainda não foi configurado.
        Configure primeiro em
        <a href="/configuration.php">Configurações</a>.
    </div>
<?php else: ?>

    <div class="row mb-3" id="map-container-fullscreen">

        <div class="col-12">

            <div class="card">

                <div class="card-body">

                    <button
                        class="btn btn-primary"
                        onclick="showAddItemModal()"
                    >
                        <i class="bi bi-plus-lg"></i>
                        Adicionar
                    </button>

                    <button
                        class="btn btn-warning"
                        id="edit-line-mode-toggle"
                        onclick="toggleEditLineMode()"
                    >
                        <span id="edit-line-mode-text">
                            <i class="bi bi-pencil"></i>
                            Editar rota
                        </span>
                    </button>

                    <button
                        class="btn btn-secondary"
                        id="fullscreen-toggle"
                        onclick="toggleFullscreen()"
                        title="Alternar tela cheia"
                    >
                        <i
                            class="bi bi-arrows-fullscreen"
                            id="fullscreen-icon"
                        ></i>
                    </button>

                    <button class="btn btn-warning text-dark">
                        <i class="bi bi-zoom-in"></i>
                        Zoom
                        <strong id="zoom-level-indicator">13</strong>
                    </button>

                    <div class="d-flex gap-2 float-end">

                        <!-- Indicador de servidores -->
                        <div
                            class="badge bg-primary d-flex align-items-center gap-1 px-3 py-2"
                            style="font-size: 0.875rem; cursor: pointer;"
                            onclick="showServerListModal()"
                            title="Clique para visualizar os servidores"
                        >
                            <i class="bi bi-server"></i>

                            <span>
                                Servidores
                                <strong id="server-count">0</strong>
                            </span>
                        </div>

                        <!-- Botões das camadas -->
                        <div class="btn-group">

                            <button
                                class="btn btn-sm btn-outline-secondary"
                                onclick="showItemListModal('olt')"
                                title="Clique para visualizar a lista de OLTs"
                            >
                                <i class="bi bi-broadcast-pin"></i>
                                OLT
                                <span
                                    class="badge bg-secondary"
                                    id="olt-count"
                                >0</span>
                            </button>

                            <button
                                class="btn btn-sm btn-outline-secondary"
                                onclick="showItemListModal('odc')"
                                title="Clique para visualizar a lista de ODCs"
                            >
                                <i class="bi bi-box"></i>
                                ODC
                                <span
                                    class="badge bg-secondary"
                                    id="odc-count"
                                >0</span>
                            </button>

                            <button
                                class="btn btn-sm btn-outline-secondary"
                                onclick="showItemListModal('odp')"
                                title="Clique para visualizar a lista de ODPs"
                            >
                                <i class="bi bi-cube"></i>
                                ODP
                                <span
                                    class="badge bg-secondary"
                                    id="odp-count"
                                >0</span>
                            </button>

                            <button
                                class="btn btn-sm btn-outline-secondary"
                                onclick="showItemListModal('onu')"
                                title="Clique para visualizar a lista de ONUs"
                            >
                                <i class="bi bi-wifi"></i>
                                ONU
                                <span
                                    class="badge bg-secondary"
                                    id="onu-count"
                                >0</span>
                            </button>

                        </div>

                    </div>

                </div>

            </div>

        </div>


        <div class="col-12">

            <div class="card" id="map-card">

                <div class="card-body p-0">
                    <div id="map"></div>
                </div>

            </div>

        </div>

    </div>


    <!-- Menu de contexto das rotas -->
    <div
        id="polyline-context-menu"
        class="context-menu"
        style="
            display: none;
            position: absolute;
            z-index: 9999;
            background: white;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            min-width: 150px;
        "
    >

        <div
            class="context-menu-item"
            onclick="enablePolylineEdit()"
        >
            <i class="bi bi-pencil"></i>
            Editar rota
        </div>

        <div
            class="context-menu-item"
            onclick="savePolylineWaypoints()"
        >
            <i class="bi bi-save"></i>
            Salvar pontos
        </div>

        <div
            class="context-menu-item"
            onclick="resetPolylineToStraight()"
        >
            <i class="bi bi-arrow-counterclockwise"></i>
            Redefinir para linha reta
        </div>

        <div
            class="context-menu-item"
            onclick="closeContextMenu()"
        >
            <i class="bi bi-x-lg"></i>
            Fechar
        </div>

    </div>

<?php endif; ?>


<!-- Modal Adicionar Item -->
<div class="modal fade" id="addItemModal" tabindex="-1">

    <div class="modal-dialog">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">
                    <i class="bi bi-plus-circle"></i>
                    Adicionar item de rede
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <div class="modal-body">

                <form id="form-add-item">

                    <div class="form-group">

                        <label>Tipo do item</label>

                        <select
                            name="item_type"
                            class="form-control"
                            required
                            onchange="updateItemForm(this.value)"
                        >

                            <option value="">
                                Selecione o tipo
                            </option>

                            <option value="server">
                                Servidor
                            </option>

                            <option value="olt">
                                OLT
                            </option>

                            <option value="odc">
                                ODC
                            </option>

                            <option value="odp">
                                ODP
                            </option>

                            <option value="onu">
                                ONU
                            </option>

                        </select>

                    </div>

                    <div class="form-group">
                        <label>Nome</label>

                        <input
                            type="text"
                            name="name"
                            class="form-control"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>Latitude</label>

                        <input
                            type="number"
                            step="0.00000001"
                            name="latitude"
                            class="form-control"
                            required
                            readonly
                        >
                    </div>

                    <div class="form-group">
                        <label>Longitude</label>

                        <input
                            type="number"
                            step="0.00000001"
                            name="longitude"
                            class="form-control"
                            required
                            readonly
                        >
                    </div>

                    <div id="dynamic-fields"></div>

                </form>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    Fechar
                </button>

                <button
                    type="button"
                    class="btn btn-primary"
                    onclick="addItem()"
                >
                    Adicionar item
                </button>

            </div>

        </div>

    </div>

</div>


<!-- Modal Editar Item -->
<div class="modal fade" id="editItemModal" tabindex="-1">

    <div class="modal-dialog">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">
                    <i class="bi bi-pencil-square"></i>
                    Editar item de rede
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <div class="modal-body">

                <form id="form-edit-item">

                    <input
                        type="hidden"
                        name="item_id"
                    >

                    <div class="form-group">

                        <label>Tipo do item</label>

                        <input
                            type="text"
                            name="item_type"
                            class="form-control"
                            readonly
                            style="
                                background-color: #f8f9fa;
                                cursor: not-allowed;
                            "
                        >

                        <small class="text-muted">
                            O tipo do item não pode ser alterado
                        </small>

                    </div>

                    <div class="form-group">

                        <label>Nome</label>

                        <input
                            type="text"
                            name="name"
                            class="form-control"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label>Latitude</label>

                        <input
                            type="number"
                            step="0.00000001"
                            name="latitude"
                            class="form-control"
                            readonly
                            style="
                                background-color: #f8f9fa;
                                cursor: not-allowed;
                            "
                        >

                        <small class="text-muted">
                            As coordenadas não podem ser alteradas.
                            Exclua e recrie o item se precisar mudar sua localização.
                        </small>

                    </div>

                    <div class="form-group">

                        <label>Longitude</label>

                        <input
                            type="number"
                            step="0.00000001"
                            name="longitude"
                            class="form-control"
                            readonly
                            style="
                                background-color: #f8f9fa;
                                cursor: not-allowed;
                            "
                        >

                        <small class="text-muted">
                            As coordenadas não podem ser alteradas.
                            Exclua e recrie o item se precisar mudar sua localização.
                        </small>

                    </div>

                    <div id="edit-dynamic-fields"></div>

                </form>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    Fechar
                </button>

                <button
                    type="button"
                    class="btn btn-primary"
                    onclick="updateItem()"
                >
                    Atualizar item
                </button>

            </div>

        </div>

    </div>

</div>


<!-- Modal de Links do Servidor -->
<div class="modal fade" id="serverLinksModal" tabindex="-1">

    <div class="modal-dialog">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">
                    <i class="bi bi-diagram-3"></i>
                    Gerenciar links do servidor
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <div class="modal-body">

                <form id="form-server-links">

                    <input
                        type="hidden"
                        name="item_id"
                    >

                    <div class="form-group">

                        <label>🌐 ISP</label>

                        <select
                            name="isp_link"
                            class="form-control"
                            id="isp-link-select"
                        >
                            <option value="">
                                Sem link
                            </option>
                        </select>

                    </div>

                    <div class="form-group">

                        <label>🔧 Equipamento MikroTik</label>

                        <select
                            name="mikrotik_device_id"
                            class="form-control"
                            id="mikrotik-device-select"
                        >
                            <option value="">
                                Sem equipamento
                            </option>
                        </select>

                    </div>

                    <div class="form-group">

                        <label>📡 OLT</label>

                        <select
                            name="olt_link"
                            class="form-control"
                            id="olt-link-select"
                        >
                            <option value="">
                                Sem link
                            </option>
                        </select>

                    </div>

                    <div id="pon-output-power-container">
                        <!-- Campos de potência PON serão gerados dinamicamente -->
                    </div>

                </form>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    Fechar
                </button>

                <button
                    type="button"
                    class="btn btn-primary"
                    onclick="saveServerLinks()"
                >
                    Salvar links
                </button>

            </div>

        </div>

    </div>

</div>


<!-- Lista de Servidores -->
<div class="modal fade" id="serverListModal" tabindex="-1">

    <div class="modal-dialog">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">
                    <i class="bi bi-server"></i>
                    Lista de Servidores
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <div class="modal-body">

                <div id="server-list-container">
                    <!-- Lista de servidores gerada dinamicamente -->
                </div>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    Fechar
                </button>

            </div>

        </div>

    </div>

</div>


<!-- Lista de OLTs -->
<div class="modal fade" id="oltListModal" tabindex="-1">

    <div class="modal-dialog">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">
                    <i class="bi bi-broadcast-pin"></i>
                    Lista de OLTs
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <div class="modal-body">

                <div id="olt-list-container">
                    <!-- Lista de OLTs gerada dinamicamente -->
                </div>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    Fechar
                </button>

            </div>

        </div>

    </div>

</div>


<!-- Lista de ODCs -->
<div class="modal fade" id="odcListModal" tabindex="-1">

    <div class="modal-dialog">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">
                    <i class="bi bi-box"></i>
                    Lista de ODCs
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <div class="modal-body">

                <div id="odc-list-container">
                    <!-- Lista de ODCs gerada dinamicamente -->
                </div>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    Fechar
                </button>

            </div>

        </div>

    </div>

</div>


<!-- Lista de ODPs -->
<div class="modal fade" id="odpListModal" tabindex="-1">

    <div class="modal-dialog">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">
                    <i class="bi bi-cube"></i>
                    Lista de ODPs
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <div class="modal-body">

                <div id="odp-list-container">
                    <!-- Lista de ODPs gerada dinamicamente -->
                </div>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    Fechar
                </button>

            </div>

        </div>

    </div>

</div>


<!-- Lista de ONUs -->
<div class="modal fade" id="onuListModal" tabindex="-1">

    <div class="modal-dialog">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">
                    <i class="bi bi-wifi"></i>
                    Lista de ONUs
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <div class="modal-body">

                <div id="onu-list-container">
                    <!-- Lista de ONUs gerada dinamicamente -->
                </div>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    Fechar
                </button>

            </div>

        </div>

    </div>

</div>


<!-- Estilos do mapa -->
<link rel="stylesheet" href="/assets/css/map.css">

<?php
include __DIR__ . '/views/layouts/footer.php';
?>


<!-- Leaflet.Editable -->
<script src="/assets/js/Leaflet.Editable.js"></script>


<!-- Módulos JavaScript do mapa -->
<?php
$jsVersion = time();
?>

<script src="/assets/js/map/map-utils.js?v=<?php echo $jsVersion; ?>"></script>
<script src="/assets/js/map/map-core.js?v=<?php echo $jsVersion; ?>"></script>
<script src="/assets/js/map/map-markers.js?v=<?php echo $jsVersion; ?>"></script>
<script src="/assets/js/map/map-polylines.js?v=<?php echo $jsVersion; ?>"></script>
<script src="/assets/js/map/map-items.js?v=<?php echo $jsVersion; ?>"></script>
<script src="/assets/js/map/map-server.js?v=<?php echo $jsVersion; ?>"></script>


<script>

document.addEventListener(
    'DOMContentLoaded',
    function() {

        initMap();
        startAutoRefresh();

        const urlParams =
            new URLSearchParams(
                window.location.search
            );

        const focusType =
            urlParams.get('focus_type');

        const focusId =
            urlParams.get('focus_id');

        const focusSerial =
            urlParams.get('focus_serial');


        if (
            (focusType && focusId) ||
            (
                focusType === 'onu' &&
                focusSerial
            )
        ) {

            const waitForMapReady = () => {

                if (
                    typeof allMapItems !== 'undefined' &&
                    allMapItems.length > 0
                ) {

                    console.log(
                        '✓ Itens do mapa carregados'
                    );

                    setTimeout(
                        () => {

                            let focused = false;

                            if (
                                focusType === 'onu' &&
                                focusSerial
                            ) {

                                focused =
                                    focusOnONUBySerial(
                                        decodeURIComponent(
                                            focusSerial
                                        ),
                                        17
                                    );

                            } else if (
                                focusType &&
                                focusId
                            ) {

                                focused =
                                    focusOnMapItem(
                                        focusType,
                                        parseInt(
                                            focusId
                                        ),
                                        17
                                    );

                            }


                            if (focused) {

                                console.log(
                                    '✓ Item localizado no mapa'
                                );

                            } else {

                                console.log(
                                    '✗ Item não encontrado'
                                );

                                showToast(
                                    'Equipamento não encontrado no mapa',
                                    'warning'
                                );

                            }

                        },
                        500
                    );

                } else {

                    console.log(
                        '⏳ Aguardando itens do mapa...'
                    );

                    setTimeout(
                        waitForMapReady,
                        200
                    );

                }

            };

            waitForMapReady();

        }

    }
);


// Alternar tela cheia
function toggleFullscreen() {

    const mapContainer =
        document.getElementById(
            'map-container-fullscreen'
        );

    const fullscreenBtn =
        document.getElementById(
            'fullscreen-toggle'
        );

    const fullscreenIcon =
        document.getElementById(
            'fullscreen-icon'
        );


    if (!document.fullscreenElement) {

        if (
            mapContainer.requestFullscreen
        ) {

            mapContainer.requestFullscreen();

        } else if (
            mapContainer.webkitRequestFullscreen
        ) {

            mapContainer.webkitRequestFullscreen();

        } else if (
            mapContainer.msRequestFullscreen
        ) {

            mapContainer.msRequestFullscreen();

        }


        fullscreenBtn.classList.remove(
            'btn-secondary'
        );

        fullscreenBtn.classList.add(
            'btn-danger'
        );

        fullscreenIcon.classList.remove(
            'bi-arrows-fullscreen'
        );

        fullscreenIcon.classList.add(
            'bi-fullscreen-exit'
        );


        fixModalsZIndex();


        showToast(
            'Modo tela cheia ativado! Pressione ESC ou clique no botão para sair.',
            'info',
            5000
        );

    } else {

        if (
            document.exitFullscreen
        ) {

            document.exitFullscreen();

        } else if (
            document.webkitExitFullscreen
        ) {

            document.webkitExitFullscreen();

        } else if (
            document.msExitFullscreen
        ) {

            document.msExitFullscreen();

        }


        fullscreenBtn.classList.remove(
            'btn-danger'
        );

        fullscreenBtn.classList.add(
            'btn-secondary'
        );

        fullscreenIcon.classList.remove(
            'bi-fullscreen-exit'
        );

        fullscreenIcon.classList.add(
            'bi-arrows-fullscreen'
        );


        restoreModalsToBody();


        showToast(
            'Modo tela cheia desativado',
            'info',
            3000
        );

    }


    setTimeout(
        () => {

            if (map) {
                map.invalidateSize();
            }

        },
        100
    );

}


// Ajustar modais durante tela cheia
function fixModalsZIndex() {

    console.log(
        '🔧 Movendo modais para o modo tela cheia...'
    );

    const fullscreenContainer =
        document.getElementById(
            'map-container-fullscreen'
        );

    const allModals =
        document.querySelectorAll(
            '.modal'
        );

    allModals.forEach(
        modal => {

            fullscreenContainer.appendChild(
                modal
            );

            console.log(
                '✓ Modal movido:',
                modal.id
            );

        }
    );


    const bodyObserver =
        new MutationObserver(
            function(mutations) {

                mutations.forEach(
                    function(mutation) {

                        mutation.addedNodes.forEach(
                            function(node) {

                                if (
                                    node.nodeType === 1 &&
                                    node.classList &&
                                    node.classList.contains(
                                        'modal-backdrop'
                                    )
                                ) {

                                    fullscreenContainer.appendChild(
                                        node
                                    );

                                }

                            }
                        );

                    }
                );

            }
        );


    bodyObserver.observe(
        document.body,
        {
            childList: true,
            subtree: false
        }
    );


    console.log(
        '✅ Modais ajustados para tela cheia'
    );

}


// Restaurar modais ao sair da tela cheia
function restoreModalsToBody() {

    console.log(
        '🔄 Restaurando modais...'
    );

    const allModals =
        document.querySelectorAll(
            '#map-container-fullscreen .modal'
        );

    allModals.forEach(
        modal => {

            document.body.appendChild(
                modal
            );

        }
    );


    const allBackdrops =
        document.querySelectorAll(
            '#map-container-fullscreen .modal-backdrop'
        );

    allBackdrops.forEach(
        backdrop => {

            document.body.appendChild(
                backdrop
            );

        }
    );

}


// Detectar saída da tela cheia
document.addEventListener(
    'fullscreenchange',
    function() {

        if (
            !document.fullscreenElement
        ) {

            const fullscreenBtn =
                document.getElementById(
                    'fullscreen-toggle'
                );

            const fullscreenIcon =
                document.getElementById(
                    'fullscreen-icon'
                );


            fullscreenBtn.classList.remove(
                'btn-danger'
            );

            fullscreenBtn.classList.add(
                'btn-secondary'
            );

            fullscreenIcon.classList.remove(
                'bi-fullscreen-exit'
            );

            fullscreenIcon.classList.add(
                'bi-arrows-fullscreen'
            );


            restoreModalsToBody();


            setTimeout(
                () => {

                    if (map) {
                        map.invalidateSize();
                    }

                },
                100
            );

        }

    }
);


document.addEventListener(
    'webkitfullscreenchange',
    function() {

        document.dispatchEvent(
            new Event(
                'fullscreenchange'
            )
        );

    }
);


document.addEventListener(
    'mozfullscreenchange',
    function() {

        document.dispatchEvent(
            new Event(
                'fullscreenchange'
            )
        );

    }
);

</script>