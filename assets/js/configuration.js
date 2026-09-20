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
const aiForm = document.getElementById('form-ai');
if (aiForm) {
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
    } else {
        showToast(result?.message || 'Falha ao salvar configuração da IA', 'danger');
    }
}
