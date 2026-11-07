#!/usr/bin/env python3
"""Run the actual embedded PHP patcher against isolated production-format fixtures."""
import pathlib
import subprocess
import tempfile
import unittest

SCRIPT = pathlib.Path(__file__).with_name("deploy-central-voip.sh").read_text()
PATCHER = SCRIPT.split("<<'PHP'\n", 1)[1].split("\nPHP\n", 1)[0]
PRODUCTION = """<ul class="sidebar-menu">
<li>
    <a href="/configuration.php" class="<?php echo $nav === 'configuration' ? 'active' : ''; ?>">
        <i class="bi bi-gear"></i>
        <span>Configuração</span>
    </a>
</li>
<li class="jrc-sidebar-mcp-shortcut">
    <a href="/mcp-server.php">MCP Server</a>
</li>
</ul>
"""
MULTILINE = PRODUCTION.replace('<li>\n    <a href=', '<li>\n\n    <a\n        href=', 1)

class MenuTest(unittest.TestCase):
    def patch(self, content):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            src, dest, patcher = [root / name for name in ("header.php", "result.php", "patch.php")]
            src.write_bytes(content.encode())
            patcher.write_text(PATCHER)
            result = subprocess.run(["php", str(patcher), str(src), str(dest)], capture_output=True, text=True)
            self.assertEqual(src.read_bytes().decode(), content)
            output = dest.read_bytes().decode() if dest.exists() else None
            if output is not None:
                subprocess.run(["php", "-l", str(dest)], check=True, capture_output=True)
            return result.returncode, output

    def test_supported_formats_and_idempotence(self):
        for original in (PRODUCTION, MULTILINE, PRODUCTION.replace("\n", "\r\n")):
            with self.subTest(original=original):
                code, output = self.patch(original)
                self.assertEqual(code, 0)
                self.assertEqual(output.count('href="/voip-central.php"'), 1)
                inserted = PATCHER.split("<<<'HTML'\n", 1)[1].split("\nHTML;", 1)[0]
                self.assertEqual(output.replace(inserted, "", 1), original)
                self.assertEqual(self.patch(output), (0, output))

    def test_missing_target_is_rejected(self):
        self.assertEqual(self.patch(PRODUCTION.replace("/configuration.php", "/other.php")), (3, None))

    def test_ambiguous_target_is_rejected(self):
        self.assertEqual(self.patch(PRODUCTION + PRODUCTION), (3, None))

if __name__ == "__main__":
    unittest.main()
