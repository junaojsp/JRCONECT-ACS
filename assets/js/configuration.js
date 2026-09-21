// Configuration Page JavaScript
// ==============================

// Change Password Form
document.getElementById('form-change-password').addEventListener('submit', async function(e) {
    e.preventDefault();
    showLoading();

    const formData = new FormData(this);
    const data = Object.fromEntries(formData);

    const result = await fetchAPI('/api/update-password.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message, 'success');
        this.reset();
    } else {
        showToast(result.message || 'Gagal mengupdate kredensial', 'danger');
    }
});

// GenieACS Form
document.getElementById('form-genieacs').addEventListener('submit', async function(e) {
    e.preventDefault();
    showLoading();

    const formData = new FormData(this);
    const data = Object.fromEntries(formData);

    const result = await fetchAPI('/api/test-genieacs.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message, 'success');
        setTimeout(() => location.reload(), 1500);
    } else {
        showToast(result?.message || 'Koneksi gagal', 'danger');
    }
});

// MikroTik Form
document.getElementById('form-mikrotik').addEventListener('submit', async function(e) {
    e.preventDefault();
    showLoading();

    const formData = new FormData(this);
    const data = Object.fromEntries(formData);

    const result = await fetchAPI('/api/test-mikrotik.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message, 'success');
        setTimeout(() => location.reload(), 1500);
    } else {
        showToast(result?.message || 'Koneksi gagal', 'danger');
    }
});

// Telegram Form
document.getElementById('form-telegram').addEventListener('submit', async function(e) {
    e.preventDefault();
    showLoading();

    const formData = new FormData(this);
    const data = Object.fromEntries(formData);

    const result = await fetchAPI('/api/test-telegram.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message, 'success');
        setTimeout(() => location.reload(), 1500);
    } else {
        showToast(result?.message || 'Koneksi gagal', 'danger');
    }
});

// AI Form
const aiProviderModels = {
    openai: ['gpt-5.6-sol', 'gpt-5.6-terra', 'gpt-5.6-luna'],
    anthropic: ['claude-sonnet-5', 'claude-opus-5', 'claude-fable-5', 'claude-opus-4-8', 'claude-opus-4-7', 'claude-opus-4-6']
};

function refreshAIProviderForm(forceDefault = false) {
    const form = document.getElementById('form-ai');
    if (!form) return;

    const providerSelect = form.querySelector('[name="provider"]');
    const keyInput = form.querySelector('[name="api_key"]');
    const modelInput = form.querySelector('[name="model"]');
    const datalist = document.getElementById('ai-model-options');
    const help = document.getElementById('ai-model-help');

    const provider = providerSelect?.value || 'openai';
    const providerConfig = (window.AI_PROVIDER_CONFIG || {})[provider] || {};
    const models = aiProviderModels[provider] || [];

    if (datalist) {
        datalist.innerHTML = models.map(model => '<option value="' + model + '"></option>').join('');
    }

    if (modelInput && (forceDefault || !modelInput.value)) {
        modelInput.value = providerConfig.model || models[0] || '';
    }

    if (keyInput) {
        keyInput.value = '';
        keyInput.placeholder = providerConfig.configured
            ? 'Chave já configurada — deixe em branco para manter'
            : (provider === 'anthropic' ? 'Cole sua Anthropic API key aqui' : 'Cole sua OpenAI API key aqui');
    }

    if (help) {
        help.textContent = provider === 'anthropic'
            ? 'Modelos Claude ativos. Sonnet 5 é o padrão desta integração.'
            : 'Modelos OpenAI. Você também pode informar manualmente outro modelo compatível.';
    }
}

const aiForm = document.getElementById('form-ai');
if (aiForm) {
    const providerSelect = aiForm.querySelector('[name="provider"]');
    if (providerSelect) {
        providerSelect.addEventListener('change', () => refreshAIProviderForm(true));
    }
    refreshAIProviderForm(false);
    aiForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        showLoading();

        const formData = new FormData(this);
        const data = Object.fromEntries(formData);

        const result = await fetchAPI('/api/test-ai.php', {
            method: 'POST',
            body: JSON.stringify(data)
        });

        hideLoading();

        if (result && result.success) {
            showToast(result.message, 'success');
            setTimeout(() => { window.location.href = '/configuration.php#concentrators-config'; }, 1200);
        } else {
            showToast(result?.message || 'Falha ao conectar com a IA', 'danger');
        }
    });
}

// Save GenieACS Configuration
async function saveGenieACS() {
    const form = document.getElementById('form-genieacs');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);

    showLoading();

    const result = await fetchAPI('/api/save-genieacs.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message, 'success');
    } else {
        showToast(result.message || 'Gagal menyimpan konfigurasi', 'danger');
    }
}

// Save MikroTik Configuration
async function saveMikroTik() {
    const form = document.getElementById('form-mikrotik');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);

    showLoading();

    const result = await fetchAPI('/api/save-mikrotik.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message, 'success');
    } else {
        showToast(result.message || 'Gagal menyimpan konfigurasi', 'danger');
    }
}

// Save Telegram Configuration
async function saveTelegram() {
    const form = document.getElementById('form-telegram');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);

    showLoading();

    const result = await fetchAPI('/api/save-telegram.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message, 'success');
    } else {
        showToast(result.message || 'Gagal menyimpan konfigurasi', 'danger');
    }
}


// Save AI Configuration
async function saveAI() {
    const form = document.getElementById('form-ai');
    if (!form) return;

    const formData = new FormData(form);
    const data = Object.fromEntries(formData);

    showLoading();

    const result = await fetchAPI('/api/save-ai.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message, 'success');
        const keyInput = form.querySelector('[name="api_key"]');
        if (keyInput) {
            keyInput.value = '';
            keyInput.placeholder = 'Chave já configurada — deixe em branco para manter';
        }
        const provider = form.querySelector('[name="provider"]')?.value || 'openai';
        const model = form.querySelector('[name="model"]')?.value || '';
        window.AI_PROVIDER_CONFIG = window.AI_PROVIDER_CONFIG || {};
        window.AI_PROVIDER_CONFIG[provider] = {
            ...(window.AI_PROVIDER_CONFIG[provider] || {}),
            configured: true,
            model
        };
    } else {
        showToast(result?.message || 'Falha ao salvar configuração da IA', 'danger');
    }
}


// Concentradores / BRAS
function concentratorFormPayload() {
    const form = document.getElementById('form-concentrator');
    if (!form) return null;

    const data = Object.fromEntries(new FormData(form));
    data.is_default = form.querySelector('[name="is_default"]')?.checked ? 1 : 0;
    data.is_active = form.querySelector('[name="is_active"]')?.checked ? 1 : 0;
    return data;
}

function getConcentratorConfig(id) {
    return (window.CONCENTRATORS || []).find(item => Number(item.id) === Number(id)) || null;
}

function newConcentrator() {
    const form = document.getElementById('form-concentrator');
    if (!form) return;

    form.reset();
    form.querySelector('[name="id"]').value = '';
    form.querySelector('[name="vendor"]').value = 'huawei';
    form.querySelector('[name="model"]').value = 'NE8000';
    form.querySelector('[name="protocol"]').value = 'ssh';
    form.querySelector('[name="port"]').value = '22';
    form.querySelector('[name="is_active"]').checked = true;
    form.querySelector('[name="is_default"]').checked = false;

    const password = form.querySelector('[name="password"]');
    if (password) {
        password.value = '';
        password.placeholder = 'Informe a senha';
    }

    const help = document.getElementById('concentrator-password-help');
    if (help) {
        help.textContent = 'A senha é criptografada no servidor e não volta para o navegador.';
    }

    form.querySelector('[name="name"]')?.focus();
}

function editConcentrator(id) {
    const item = getConcentratorConfig(id);
    const form = document.getElementById('form-concentrator');
    if (!item || !form) return;

    form.querySelector('[name="id"]').value = item.id ?? '';
    form.querySelector('[name="name"]').value = item.name ?? '';
    form.querySelector('[name="vendor"]').value = item.vendor ?? 'huawei';
    form.querySelector('[name="model"]').value = item.model ?? 'NE8000';
    form.querySelector('[name="host"]').value = item.host ?? '';
    form.querySelector('[name="port"]').value = item.port ?? 22;
    form.querySelector('[name="protocol"]').value = item.protocol ?? 'ssh';
    form.querySelector('[name="username"]').value = item.username ?? '';
    form.querySelector('[name="ixc_name"]').value = item.ixc_name ?? '';
    form.querySelector('[name="nas_ip"]').value = item.nas_ip ?? '';
    form.querySelector('[name="is_default"]').checked = !!item.is_default;
    form.querySelector('[name="is_active"]').checked = !!item.is_active;

    const password = form.querySelector('[name="password"]');
    if (password) {
        password.value = '';
        password.placeholder = 'Senha já configurada — deixe em branco para manter';
    }

    const help = document.getElementById('concentrator-password-help');
    if (help) {
        help.textContent = 'Senha já configurada. Preencha somente se quiser substituí-la.';
    }

    document.getElementById('concentrators-tab')?.click();
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

const concentratorForm = document.getElementById('form-concentrator');
if (concentratorForm) {
    concentratorForm.addEventListener('submit', async function(e) {
        e.preventDefault();

        const data = concentratorFormPayload();
        if (!data) return;

        showLoading();
        const result = await fetchAPI('/api/test-concentrator.php', {
            method: 'POST',
            body: JSON.stringify(data)
        });
        hideLoading();

        if (result?.success) {
            let detail = result.message || 'Conexão realizada com sucesso.';
            if (result.identity) detail += ' ' + result.identity;
            showToast(detail, 'success');

            if (data.id) {
                setTimeout(() => { window.location.href = '/configuration.php#concentrators-config'; }, 1200);
            }
        } else {
            showToast(result?.message || 'Falha ao conectar ao concentrador.', 'danger');
        }
    });
}

async function saveConcentrator() {
    const data = concentratorFormPayload();
    if (!data) return;

    showLoading();
    const result = await fetchAPI('/api/save-concentrator.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });
    hideLoading();

    if (result?.success) {
        showToast(result.message || 'Concentrador salvo.', 'success');
        setTimeout(() => { window.location.href = '/configuration.php#concentrators-config'; }, 900);
    } else {
        showToast(result?.message || 'Falha ao salvar o concentrador.', 'danger');
    }
}

async function testSavedConcentrator(id) {
    const item = getConcentratorConfig(id);
    if (!item) return;

    const data = {
        ...item,
        id: Number(id),
        password: '',
        is_default: item.is_default ? 1 : 0,
        is_active: item.is_active ? 1 : 0
    };

    showLoading();
    const result = await fetchAPI('/api/test-concentrator.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });
    hideLoading();

    if (result?.success) {
        let detail = result.message || 'Conexão realizada com sucesso.';
        if (result.identity) detail += ' ' + result.identity;
        showToast(detail, 'success');
        setTimeout(() => { window.location.href = '/configuration.php#concentrators-config'; }, 1200);
    } else {
        showToast(result?.message || 'Falha ao conectar ao concentrador.', 'danger');
    }
}

async function deleteConcentrator(id) {
    const item = getConcentratorConfig(id);
    const label = item?.name || ('#' + id);

    if (!confirm('Excluir o concentrador "' + label + '"?')) return;

    showLoading();
    const result = await fetchAPI('/api/delete-concentrator.php', {
        method: 'POST',
        body: JSON.stringify({ id: Number(id) })
    });
    hideLoading();

    if (result?.success) {
        showToast(result.message || 'Concentrador excluído.', 'success');
        setTimeout(() => { window.location.href = '/configuration.php#concentrators-config'; }, 700);
    } else {
        showToast(result?.message || 'Falha ao excluir o concentrador.', 'danger');
    }
}


function activateConfigTabFromHash() {
    if (window.location.hash !== '#concentrators-config') return;
    const tab = document.getElementById('concentrators-tab');
    if (tab) tab.click();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', activateConfigTabFromHash);
} else {
    activateConfigTabFromHash();
}
