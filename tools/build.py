#!/usr/bin/env python3
"""Build the installable plugin only; exclude tests, private data and OS metadata."""
from pathlib import Path
import re, zipfile
root = Path(__file__).resolve().parents[1]
plugin = root / 'yookds-for-woocommerce'
version = re.search(r'\* Version:\s*(\S+)', (plugin / 'yookds-for-woocommerce.php').read_text()).group(1)
dist = root / 'dist'
dist.mkdir(exist_ok=True)
out = dist / f'yookds-for-woocommerce-{version}.zip'
with zipfile.ZipFile(out, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for path in sorted(plugin.rglob('*')):
        if path.is_file() and not path.name.startswith('.'):
            info = zipfile.ZipInfo(str(path.relative_to(root)), (2026, 9, 28, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, path.read_bytes())
with zipfile.ZipFile(out) as check:
    assert check.testzip() is None
    assert 'yookds-for-woocommerce/yookds-for-woocommerce.php' in check.namelist()
print(out)
