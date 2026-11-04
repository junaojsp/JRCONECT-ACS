"""Add only theme assets to existing HTML heads; preserve application code and local customizations."""
import re
THEME_PAGES = ['views/layouts/header.php', 'discovery.php', 'login.php', 'init.php']
START = '<!-- JRC NOC SHARED THEME -->'
END = '<!-- /JRC NOC SHARED THEME -->'
BLOCK = START + '\n<link rel="stylesheet" href="/assets/css/jrc-theme.css?v=1">\n<script src="/assets/js/jrc-theme.js?v=1" defer></script>\n' + END
def inject_theme(data):
    text = data.decode('utf-8')
    if START in text or END in text:
        if text.count(START) != 1 or text.count(END) != 1 or text.index(END) < text.index(START):
            raise RuntimeError('Duplicate or incomplete shared theme markers; manual review required.')
        return re.sub(re.escape(START)+'.*?'+re.escape(END), lambda _: BLOCK, text, count=1, flags=re.S).encode('utf-8')
    if len(re.findall(r'</head\s*>', text, flags=re.I)) != 1:
        raise RuntimeError('HTML head differs; manual theme review required.')
    return re.sub(r'</head\s*>', lambda match: BLOCK+'\n'+match.group(), text, count=1, flags=re.I).encode('utf-8')
