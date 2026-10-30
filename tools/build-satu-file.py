"""
Membuat satu file HTML mandiri dari folder web/ (CSS, JS, dan gambar dimasukkan ke dalam file).

Dipakai untuk builder halaman yang hanya menerima satu file HTML (mis. Super Landingpage AI
di arthasafetyindonesia.com/<slug>). Jalankan dari root repository:

    python3 tools/build-satu-file.py [URL_API_EXEC]

Hasil: dist/lms-satu-file.html. Jika URL /exec tidak diberikan, nilai dari web/js/config.js dipakai.
"""
import base64
import io
import os
import re
import sys

from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
WEB = os.path.join(ROOT, 'web')
OUT = os.path.join(ROOT, 'dist', 'lms-satu-file.html')


def baca(path):
    with open(os.path.join(WEB, path), encoding='utf-8') as f:
        return f.read()


def data_uri(path):
    """Gambar sebagai data URI; PNG diubah ke WebP agar file HTML lebih kecil."""
    full = os.path.join(WEB, path)
    if path.endswith('.png'):
        buf = io.BytesIO()
        Image.open(full).save(buf, 'WEBP', quality=90, method=6)
        return 'data:image/webp;base64,' + base64.b64encode(buf.getvalue()).decode()
    mime = 'image/webp' if path.endswith('.webp') else 'image/jpeg'
    with open(full, 'rb') as f:
        return 'data:' + mime + ';base64,' + base64.b64encode(f.read()).decode()


def main():
    html = baca('index.html')
    api_url = sys.argv[1] if len(sys.argv) > 1 else re.search(r"API_URL:\s*'([^']*)'", baca('js/config.js')).group(1)
    gambar = {p: data_uri(p) for p in ['img/logo.png', 'img/ikon.png', 'img/foto.webp']}

    # Pengaturan ditaruh paling atas agar mudah ditemukan & diganti di editor.
    config = (
        '\n  <!-- ============================================================\n'
        '       PENGATURAN LMS: ganti API_URL dengan Web app URL Apps Script (/exec)\n'
        '       ============================================================ -->\n'
        "  <script>window.LMS_CONFIG = { API_URL: '" + api_url + "' };</script>\n"
    )
    html = html.replace('<title>LMS Artha Safety Indonesia</title>',
                        '<title>LMS Artha Safety Indonesia</title>' + config, 1)
    html = html.replace('  <script src="js/config.js?v=1"></script>\n', '')

    html = re.sub(r'<link rel="stylesheet" href="css/app\.css[^"]*">',
                  lambda m: '<style>\n' + baca('css/app.css') + '</style>', html)
    for nama in ['common', 'peserta', 'pic']:
        html = re.sub(r'<script src="js/' + nama + r'\.js[^"]*"></script>',
                      lambda m, n=nama: '<script>\n' + baca('js/' + n + '.js') + '</script>', html)
    for path, uri in gambar.items():
        html = html.replace('"' + path + '"', '"' + uri + '"').replace("'" + path + "'", "'" + uri + "'")

    sisa = re.findall(r'(?:src|href)="(?:css|js|img)/[^"]*"', html)
    if sisa:
        sys.exit('Masih ada file eksternal: ' + ', '.join(sisa))
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, 'w', encoding='utf-8') as f:
        f.write(html)
    print('OK', OUT, round(len(html.encode()) / 1024), 'KB')


if __name__ == '__main__':
    main()
