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
            setTimeout(() => location.reload(), 1200);
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
