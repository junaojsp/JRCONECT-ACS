#!/usr/bin/env python3
import argparse
import datetime
import fcntl
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
from pathlib import Path

def php_check(path):
    p = subprocess.run(["php", "-l", str(path)], capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError((p.stderr or p.stdout)[:2500])

def atomic_write(path, text):
    meta = path.stat()
    fd, tmp = tempfile.mkstemp(prefix=".jrc-wifi5g-", dir=str(path.parent))
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            f.write(text)
            f.flush()
            os.fsync(f.fileno())
            os.fchmod(f.fileno(), stat.S_IMODE(meta.st_mode))
            if os.geteuid() == 0:
                os.fchown(f.fileno(), meta.st_uid, meta.st_gid)
        os.replace(tmp, path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)

def patch_php(text):
    if "detectLegacyWifiBand(" not in text:
        marker = "    private static function bandLabel(string $band): string\n"
        if marker not in text:
            raise RuntimeError("Funcao bandLabel nao encontrada em api/device-tools.php")

        helper = r'''    private static function detectLegacyWifiBand(array $w, string $index, ?int $channel, string $ssid, string $existing = ''): string
    {
        // 1) Respeita banda explicita quando o equipamento realmente a fornece.
        $explicit = [
            $existing,
            self::has($w, 'OperatingFrequencyBand') ? (string)self::val($w, 'OperatingFrequencyBand', '') : '',
            self::has($w, 'FrequencyBand') ? (string)self::val($w, 'FrequencyBand', '') : '',
            self::has($w, 'Band') ? (string)self::val($w, 'Band', '') : '',
        ];
        foreach ($explicit as $candidate) {
            $label = self::bandLabel(trim((string)$candidate));
            if ($label === '2.4 GHz' || $label === '5 GHz') {
                return $label;
            }
        }

        // 2) Canal e o indicador mais confiavel quando disponivel.
        if ($channel !== null && $channel > 0) {
            if ($channel >= 36 && $channel <= 196) {
                return '5 GHz';
            }
            if ($channel >= 1 && $channel <= 14) {
                return '2.4 GHz';
            }
        }

        // 3) PossibleChannels costuma existir em TR-098 mesmo quando Channel=0/auto.
        $possible = self::has($w, 'PossibleChannels')
            ? (string)self::val($w, 'PossibleChannels', '')
            : '';
        if ($possible !== '') {
            preg_match_all('/\d+/', $possible, $matches);
            $numbers = array_values(array_filter(array_map('intval', $matches[0] ?? [])));
            if ($numbers) {
                foreach ($numbers as $number) {
                    if ($number >= 36 && $number <= 196) {
                        return '5 GHz';
                    }
                }
                $all24 = true;
                foreach ($numbers as $number) {
                    if ($number < 1 || $number > 14) {
                        $all24 = false;
                        break;
                    }
                }
                if ($all24) {
                    return '2.4 GHz';
                }
            }
        }

        // 4) Standards: 802.11a/ac sao exclusivos/fortes indicios de 5 GHz.
        $standard = '';
        foreach (['Standard', 'OperatingStandards', 'SupportedStandards'] as $field) {
            if (self::has($w, $field)) {
                $standard .= ' ' . strtolower((string)self::val($w, $field, ''));
            }
        }
        if ($standard !== '') {
            if (
                preg_match('/(^|[^a-z0-9])802\.11a([^a-z0-9]|$)/i', $standard) ||
                preg_match('/(^|[^a-z0-9])a([^a-z0-9]|$)/i', $standard) ||
                str_contains($standard, '802.11ac') ||
                preg_match('/(^|[^a-z0-9])ac([^a-z0-9]|$)/i', $standard)
            ) {
                return '5 GHz';
            }
            if (
                preg_match('/(^|[^a-z0-9])802\.11b([^a-z0-9]|$)/i', $standard) ||
                preg_match('/(^|[^a-z0-9])802\.11g([^a-z0-9]|$)/i', $standard)
            ) {
                return '2.4 GHz';
            }
        }

        // 5) Ultimo fallback sem depender do fabricante: sufixos como -5G/_5G/5GHz.
        if (preg_match('/(^|[-_.\s])(5g|5ghz|5\.8g)([-_.\s]|$)/i', $ssid)) {
            return '5 GHz';
        }
        if (preg_match('/(^|[-_.\s])(2g|2\.4g|2\.4ghz)([-_.\s]|$)/i', $ssid)) {
            return '2.4 GHz';
        }

        // 6) Fallback legado comum em ONUs TR-098 dual-band (ex.: FiberHome):
        // WLANConfiguration.1 = 2.4 GHz e WLANConfiguration.5 = 5 GHz.
        $idx = (int)$index;
        if ($idx === 5) {
            return '5 GHz';
        }
        if ($idx === 1) {
            return '2.4 GHz';
        }

        return self::bandLabel($existing);
    }

'''
        text = text.replace(marker, helper + marker, 1)

    old = "'band' => self::bandLabel($band),"
    new = "'band' => self::detectLegacyWifiBand($w, (string)$wi, $channel, (string)self::val($w, 'SSID', ''), (string)$band),"
    if old in text:
        text = text.replace(old, new, 1)
    elif new not in text:
        raise RuntimeError("Linha de classificacao TR-098 nao encontrada")

    return text

def patch_js(text):
    before = text

    # Evita que o SSID generico (normalmente 2.4 GHz) seja usado como falso 5 GHz.
    text = re.sub(
        r'device\.wifi_ssid_5g_name\s*,\s*device\.wifi_ssid',
        'device.wifi_ssid_5g_name',
        text
    )
    text = re.sub(
        r'device\.wifi_ssid_5g_name\s*\|\|\s*device\.wifi_ssid',
        'device.wifi_ssid_5g_name',
        text
    )

    if text == before:
        # Nao e erro se uma versao futura ja estiver corrigida.
        if 'device.wifi_ssid_5g_name, device.wifi_ssid' in text or 'device.wifi_ssid_5g_name || device.wifi_ssid' in text:
            raise RuntimeError("Fallback 5 GHz encontrado, mas nao foi possivel corrigi-lo")

    return text

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--root", default="/var/www/gacs")
    ap.add_argument("--backup-dir", default="/root")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    root = Path(args.root).resolve()
    php = root / "api/device-tools.php"
    js = root / "assets/js/device-detail.js"

    for p in (php, js):
        if not p.is_file() or p.is_symlink():
            raise RuntimeError("Arquivo ausente ou invalido: " + str(p))

    php_before = php.read_text(encoding="utf-8")
    js_before = js.read_text(encoding="utf-8")

    php_after = patch_php(php_before)
    js_after = patch_js(js_before)

    if "detectLegacyWifiBand" not in php_after:
        raise RuntimeError("Helper de deteccao 5 GHz nao foi inserido")
    if "self::detectLegacyWifiBand($w" not in php_after:
        raise RuntimeError("Classificacao TR-098 nao foi ligada ao helper")

    # Valida PHP em arquivo temporario antes de alterar producao.
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False, encoding="utf-8") as f:
        f.write(php_after)
        tmp_php = Path(f.name)
    try:
        php_check(tmp_php)
    finally:
        tmp_php.unlink(missing_ok=True)

    print("VALIDADO: detector Wi-Fi TR-098 2.4/5 GHz.")
    print("5 GHz sera inferido por banda explicita, canal, PossibleChannels, standard, SSID e fallback WLAN.5.")
    print("Fallback falso de SSID 2.4 GHz como 5 GHz sera removido do frontend.")
    print("wifiSet/escrita de SSID, senha e canal NAO sera alterada.")

    if args.dry_run:
        print("SIMULACAO OK: nenhum arquivo alterado.")
        return

    backup_parent = Path(args.backup_dir).resolve()
    lock_path = backup_parent / ".jrc-wifi5g-v1.lock"

    with lock_path.open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        if php.read_text(encoding="utf-8") != php_before:
            raise RuntimeError("api/device-tools.php mudou durante a instalacao")
        if js.read_text(encoding="utf-8") != js_before:
            raise RuntimeError("assets/js/device-detail.js mudou durante a instalacao")

        backup = Path(tempfile.mkdtemp(
            prefix="gacs-wifi5g-v1-" + datetime.datetime.now().strftime("%Y%m%d-%H%M%S") + "-",
            dir=str(backup_parent)
        ))
        os.chmod(backup, 0o700)

        (backup / "api").mkdir(parents=True, exist_ok=True)
        (backup / "assets/js").mkdir(parents=True, exist_ok=True)
        shutil.copy2(php, backup / "api/device-tools.php")
        shutil.copy2(js, backup / "assets/js/device-detail.js")
        shutil.copy2(Path(__file__), backup / "install.py")

        atomic_write(php, php_after)
        atomic_write(js, js_after)

        php_check(php)

        print("INSTALADO: detector Wi-Fi 5 GHz V1 aplicado.")
        print("Backup: " + str(backup))

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("CANCELADO: " + str(exc), file=sys.stderr)
        sys.exit(1)
