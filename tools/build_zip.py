#!/usr/bin/env python3
"""Build a directly installable WordPress plugin ZIP, without runtime caches."""
from pathlib import Path
import hashlib
import re
import zipfile

root = Path(__file__).resolve().parents[1]
header = (root / 'themify-local-fonts.php').read_text()
version = re.search(r'\* Version:\s*(\S+)', header).group(1)
files = [root / name for name in ('themify-local-fonts.php', 'README.md', 'readme.txt', 'LICENSE')]
for directory in ('includes', 'assets'):
    files.extend(path for path in (root / directory).rglob('*') if path.is_file())
output = root / 'dist'
output.mkdir(exist_ok=True)
archive = output / 'themify-local-fonts.zip'
with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as bundle:
    for file in sorted(files):
        entry = zipfile.ZipInfo('themify-local-fonts/' + file.relative_to(root).as_posix())
        entry.external_attr = 0o100644 << 16
        entry.compress_type = zipfile.ZIP_DEFLATED
        # Stable timestamps make the same source produce the same package.
        entry.date_time = (2026, 1, 1, 0, 0, 0)
        bundle.writestr(entry, file.read_bytes())
with zipfile.ZipFile(archive) as bundle:
    assert bundle.testzip() is None
    assert 'themify-local-fonts/themify-local-fonts.php' in bundle.namelist()
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
(output / 'SHA256SUMS').write_text(digest + '  ' + archive.name + '\n')
print(f'{archive} (v{version}, {archive.stat().st_size} bytes)')
print(f'SHA256: {digest}')
