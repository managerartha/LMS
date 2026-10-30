#!/usr/bin/env bash
# Menyusun paket upload Hostinger: dist/lms-hostinger.zip
#   = tampilan (web/) + server PHP (hostinger/) + pustaka PDF (vendor/, dari composer).
# Jalankan dari root repository:  bash tools/build-hostinger.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/dist/lms-hostinger"
rm -rf "$OUT" "$ROOT/dist/lms-hostinger.zip"
mkdir -p "$OUT"

# 1. Tampilan (tanpa .htaccess versi lama; .htaccess Hostinger dipakai)
cp -r "$ROOT/web/index.html" "$ROOT/web/css" "$ROOT/web/js" "$ROOT/web/img" "$OUT/"

# 2. Server PHP
cp -r "$ROOT/hostinger/app" "$ROOT/hostinger/api" "$ROOT/hostinger/storage" "$ROOT/hostinger/uploads" "$OUT/"
cp "$ROOT/hostinger/install.php" "$ROOT/hostinger/composer.json" "$ROOT/hostinger/.htaccess" "$ROOT/hostinger/.user.ini" "$OUT/"
rm -f "$OUT/app/config.php"
find "$OUT/storage" "$OUT/uploads" -mindepth 1 ! -name .htaccess -exec rm -rf {} + 2>/dev/null || true

# 3. Pustaka PDF (dompdf), tanpa file pengembangan
(cd "$OUT" && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --quiet)
find "$OUT/vendor" -name .git -type d -prune -exec rm -rf {} +
find "$OUT/vendor" -maxdepth 3 -type d \( -name tests -o -name test -o -name doc -o -name docs -o -name .github \) -prune -exec rm -rf {} +
printf '# Pustaka PHP: tidak boleh dibuka dari browser.\nRequire all denied\n' > "$OUT/vendor/.htaccess"
rm -f "$OUT/composer.lock"

# 4. ZIP (isi folder langsung di akar ZIP, termasuk file tersembunyi .htaccess & .user.ini)
(cd "$OUT" && zip -qr "$ROOT/dist/lms-hostinger.zip" . -x '*.DS_Store')
echo "OK: dist/lms-hostinger.zip ($(du -h "$ROOT/dist/lms-hostinger.zip" | cut -f1))"
