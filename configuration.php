<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/AIConfig.php';
requireLogin();

$pageTitle = 'Configurações';
$currentPage = 'configuration';

// Buscar configurações existentes
$conn = getDBConnection();

$genieacs = $conn->query("SELECT * FROM genieacs_credentials LIMIT 1")->fetch_assoc();
$mikrotik = $conn->query("SELECT * FROM mikrotik_credentials LIMIT 1")->fetch_assoc();
$telegram = $conn->query("SELECT * FROM telegram_config LIMIT 1")->fetch_assoc();

ensureAIConfigTable($conn);
$ai = getAIConfig($conn);

include __DIR__ . '/views/layouts/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i>
            Configure as credenciais para conexão com os serviços.
        </div>
    </div>
</div>

<!-- Navegação das abas -->
<div class="row">
    <div class="col-12">
        <ul class="nav nav-tabs" id="configTabs" role="tablist">

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link active"
                    id="site-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#site-config"
                    type="button"
                    role="tab"
                >
                    <i class="bi bi-person-lock"></i>
                    Configuração do Site
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link"
                    id="acs-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#acs-config"
                    type="button"
                    role="tab"
                >
                    <i class="bi bi-hdd-network"></i>
                    Configuração ACS
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link"
                    id="mikrotik-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#mikrotik-config"
                    type="button"
                    role="tab"
                >
                    <i class="bi bi-ethernet"></i>
                    Configuração MikroTik
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link"
                    id="bot-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#bot-config"
                    type="button"
                    role="tab"
                >
                    <i class="fab fa-telegram"></i>
                    Configuração do Bot
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link"
                    id="ai-config-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#ai-config"
                    type="button"
                    role="tab"
                >
                    <i class="bi bi-stars"></i>
                    Configuração IA
                </button>
            </li>

        </ul>

        <!-- Conteúdo das abas -->
        <div class="tab-content" id="configTabsContent">

            <!-- Configuração do site -->
            <div
                class="tab-pane fade show active"
                id="site-config"
                role="tabpanel"
            >

                <div class="card mt-3">

                    <div class="card-header">
                        <i class="bi bi-person-lock"></i>
                        Alterar credenciais de acesso
                    </div>

                    <div class="card-body">

                        <form id="form-change-password">

                            <div class="form-group">
                                <label>Senha atual</label>

                                <input
                                    type="password"
                                    name="current_password"
                                    class="form-control"
                                    required
                                >
                            </div>

                            <div class="form-group">
                                <label>Novo usuário</label>

                                <input
                                    type="text"
                                    name="new_username"
                                    class="form-control"
                                    value="<?php echo $_SESSION['username']; ?>"
                                    required
                                >
                            </div>

                            <div class="form-group">

                                <label>
                                    Nova senha
                                    (deixe em branco se não quiser alterar)
                                </label>

                                <input
                                    type="password"
                                    name="new_password"
                                    class="form-control"
                                >

                            </div>

                            <div class="form-group">

                                <label>
                                    Confirmar nova senha
                                </label>

                                <input
                                    type="password"
                                    name="confirm_password"
                                    class="form-control"
                                >

                            </div>

                            <button
                                type="submit"
                                class="btn btn-primary"
                            >
                                <i class="bi bi-save"></i>
                                Salvar alterações
                            </button>

                        </form>

                    </div>

                </div>

            </div>


            <!-- Configuração ACS -->
            <div
                class="tab-pane fade"
                id="acs-config"
                role="tabpanel"
            >

                <div class="card mt-3">

                    <div class="card-header">

                        <i class="bi bi-hdd-network"></i>
                        Configuração do GenieACS

                        <?php if ($genieacs && $genieacs['is_connected']): ?>

                            <span class="badge online float-end">

                                Conectado

                                <?php if (!empty($genieacs['role'])): ?>

                                    / Perfil [
                                    <?php
                                    echo htmlspecialchars(
                                        $genieacs['role']
                                    );
                                    ?>
                                    ]

                                <?php endif; ?>

                            </span>

                        <?php endif; ?>

                    </div>

                    <div class="card-body">

                        <form id="form-genieacs">

                            <div class="form-group">

                                <label>Host</label>

                                <input
                                    type="text"
                                    name="host"
                                    class="form-control"
                                    value="<?php echo $genieacs['host'] ?? '192.168.1.1'; ?>"
                                    placeholder="127.0.0.1"
                                    required
                                >

                            </div>

                            <div class="form-group">

                                <label>Porta</label>

                                <input
                                    type="number"
                                    name="port"
                                    class="form-control"
                                    value="<?php echo $genieacs['port'] ?? '7557'; ?>"
                                    placeholder="7557"
                                    required
                                >

                            </div>

                            <div class="form-group">

                                <label>
                                    Usuário (opcional)
                                </label>

                                <input
                                    type="text"
                                    name="username"
                                    class="form-control"
                                    value="<?php echo $genieacs['username'] ?? ''; ?>"
                                    placeholder="Usuário"
                                >

                            </div>

                            <div class="form-group">

                                <label>
                                    Senha (opcional)
                                </label>

                                <input
                                    type="password"
                                    name="password"
                                    class="form-control"
                                    value="<?php echo $genieacs['password'] ?? ''; ?>"
                                    placeholder="Senha"
                                >

                            </div>

                            <button
                                type="submit"
                                class="btn btn-success me-2"
                            >
                                <i class="bi bi-check-circle"></i>
                                Testar conexão
                            </button>

                            <button
                                type="button"
                                class="btn btn-primary"
                                onclick="saveGenieACS()"
                            >
                                <i class="bi bi-save"></i>
                                Salvar
                            </button>

                            <?php if ($genieacs && $genieacs['last_test']): ?>

                                <small class="text-muted d-block mt-2">

                                    Último teste:

                                    <?php
                                    echo timeAgo(
                                        $genieacs['last_test']
                                    );
                                    ?>

                                </small>

                            <?php endif; ?>

                        </form>

                    </div>

                </div>

            </div>


            <!-- Configuração MikroTik -->
            <div
                class="tab-pane fade"
                id="mikrotik-config"
                role="tabpanel"
            >

                <div class="card mt-3">

                    <div class="card-header">

                        <i class="bi bi-ethernet"></i>
                        Configuração do MikroTik

                        <?php if ($mikrotik && $mikrotik['is_connected']): ?>

                            <span class="badge online float-end">
                                Conectado
                            </span>

                        <?php endif; ?>

                    </div>

                    <div class="card-body">

                        <form id="form-mikrotik">

                            <div class="form-group">

                                <label>Host</label>

                                <input
                                    type="text"
                                    name="host"
                                    class="form-control"
                                    value="<?php echo $mikrotik['host'] ?? ''; ?>"
                                    placeholder="192.168.1.1"
                                    required
                                >

                            </div>

                            <div class="form-group">

                                <label>Porta da API</label>

                                <input
                                    type="number"
                                    name="port"
                                    class="form-control"
                                    value="<?php echo $mikrotik['port'] ?? '8728'; ?>"
                                    placeholder="8728"
                                    required
                                >

                            </div>

                            <div class="form-group">

                                <label>Usuário</label>

                                <input
                                    type="text"
                                    name="username"
                                    class="form-control"
                                    value="<?php echo $mikrotik['username'] ?? ''; ?>"
                                    placeholder="admin"
                                    required
                                >

                            </div>

                            <div class="form-group">

                                <label>Senha</label>

                                <input
                                    type="password"
                                    name="password"
                                    class="form-control"
                                    value="<?php echo $mikrotik['password'] ?? ''; ?>"
                                    placeholder="Senha"
                                    required
                                >

                            </div>

                            <button
                                type="submit"
                                class="btn btn-success me-2"
                            >
                                <i class="bi bi-check-circle"></i>
                                Testar conexão
                            </button>

                            <button
                                type="button"
                                class="btn btn-primary"
                                onclick="saveMikroTik()"
                            >
                                <i class="bi bi-save"></i>
                                Salvar
                            </button>

                            <?php if ($mikrotik && $mikrotik['last_test']): ?>

                                <small class="text-muted d-block mt-2">

                                    Último teste:

                                    <?php
                                    echo timeAgo(
                                        $mikrotik['last_test']
                                    );
                                    ?>

                                </small>

                            <?php endif; ?>

                        </form>

                    </div>

                </div>

            </div>


            <!-- Configuração do Bot -->
            <div
                class="tab-pane fade"
                id="bot-config"
                role="tabpanel"
            >

                <div class="card mt-3">

                    <div class="card-header">

                        <i class="fab fa-telegram"></i>
                        Configuração do Bot do Telegram

                        <?php if ($telegram && $telegram['is_connected']): ?>

                            <span class="badge online float-end">
                                Conectado
                            </span>

                        <?php endif; ?>

                    </div>

                    <div class="card-body">

                        <form id="form-telegram">

                            <div class="form-group">

                                <label>Token do Bot</label>

                                <input
                                    type="text"
                                    name="bot_token"
                                    class="form-control"
                                    value="<?php echo $telegram['bot_token'] ?? ''; ?>"
                                    placeholder="123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11"
                                    required
                                >

                                <small class="text-muted">
                                    Obtenha o token através do @BotFather
                                </small>

                            </div>

                            <div class="form-group">

                                <label>ID do Chat</label>

                                <input
                                    type="text"
                                    name="chat_id"
                                    class="form-control"
                                    value="<?php echo $telegram['chat_id'] ?? ''; ?>"
                                    placeholder="123456789"
                                    required
                                >

                                <small class="text-muted">
                                    Obtenha o ID através do @userinfobot
                                </small>

                            </div>

                            <button
                                type="submit"
                                class="btn btn-success me-2"
                            >
                                <i class="bi bi-check-circle"></i>
                                Testar conexão
                            </button>

                            <button
                                type="button"
                                class="btn btn-primary"
                                onclick="saveTelegram()"
                            >
                                <i class="bi bi-save"></i>
                                Salvar
                            </button>

                            <?php if ($telegram && $telegram['last_test']): ?>

                                <small class="text-muted d-block mt-2">

                                    Último teste:

                                    <?php
                                    echo timeAgo(
                                        $telegram['last_test']
                                    );
                                    ?>

                                </small>

                            <?php endif; ?>

                        </form>

                    </div>

                </div>

            </div>


            <!-- Configuração IA -->
            <div
                class="tab-pane fade"
                id="ai-config"
                role="tabpanel"
            >
                <div class="card mt-3">

                    <div class="card-header">
                        <i class="bi bi-stars"></i>
                        Configuração da IA

                        <?php if ($ai && !empty($ai['is_connected'])): ?>
                            <span class="badge online float-end">
                                Conectado
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="card-body">

                        <form id="form-ai">

                            <div class="form-group">
                                <label>Provedor</label>
                                <select
                                    name="provider"
                                    class="form-control"
                                    required
                                >
                                    <option
                                        value="openai"
                                        <?php echo (($ai['provider'] ?? 'openai') === 'openai') ? 'selected' : ''; ?>
                                    >
                                        OpenAI
                                    </option>
                                </select>

                                <small class="text-muted">
                                    O painel usa a API do provedor pelo backend.
                                </small>
                            </div>

                            <div class="form-group">
                                <label>Token / API Key</label>

                                <input
                                    type="password"
                                    name="api_key"
                                    id="ai-api-key"
                                    class="form-control"
                                    value=""
                                    autocomplete="new-password"
                                    placeholder="<?php echo $ai ? 'Chave já configurada — deixe em branco para manter' : 'Cole sua API key aqui'; ?>"
                                >

                                <small class="text-muted">
                                    A chave não é exibida novamente no navegador.
                                </small>
                            </div>

                            <div class="form-group">
                                <label>Modelo</label>

                                <input
                                    type="text"
                                    name="model"
                                    class="form-control"
                                    list="ai-model-options"
                                    value="<?php echo htmlspecialchars($ai['model'] ?? 'gpt-5.6-terra', ENT_QUOTES, 'UTF-8'); ?>"
                                    required
                                >

                                <datalist id="ai-model-options">
                                    <option value="gpt-5.6-sol"></option>
                                    <option value="gpt-5.6-terra"></option>
                                    <option value="gpt-5.6-luna"></option>
                                </datalist>

                                <small class="text-muted">
                                    Terra é uma opção equilibrada entre capacidade e custo; você pode informar outro modelo compatível.
                                </small>
                            </div>

                            <button
                                type="submit"
                                class="btn btn-success me-2"
                            >
                                <i class="bi bi-check-circle"></i>
                                Testar conexão
                            </button>

                            <button
                                type="button"
                                class="btn btn-primary"
                                onclick="saveAI()"
                            >
                                <i class="bi bi-save"></i>
                                Salvar
                            </button>

                            <?php if ($ai && !empty($ai['last_test'])): ?>
                                <small class="text-muted d-block mt-2">
                                    Último teste:
                                    <?php echo timeAgo($ai['last_test']); ?>
                                </small>
                            <?php endif; ?>

                            <div class="alert alert-secondary mt-3 mb-0">
                                <i class="bi bi-shield-lock"></i>
                                O token fica no servidor e não é enviado para o JavaScript da tela.
                            </div>

                        </form>

                    </div>

                </div>
            </div>

        </div>

    </div>

</div>

<!-- Carregar JavaScript externo -->
<script src="/assets/js/configuration.js?v=<?php echo time(); ?>"></script>

<?php
include __DIR__ . '/views/layouts/footer.php';
?>