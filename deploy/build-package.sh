#!/usr/bin/env bash
# ================================================================
#  Genera el paquete listo para subir a cPanel:
#    dist/wispcontrol-cpanel.zip
#      ├── wispcontrol/   → subir a /home/USUARIO/wispcontrol   (aplicación, fuera de public_html)
#      └── public_html/   → contenido para /home/USUARIO/public_html (index.php apunta a ../wispcontrol)
#  Requisitos locales: PHP 8.2 + Composer + Node (solo para compilar).
#  Uso:  bash deploy/build-package.sh
# ================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TOOLS="$ROOT/../.tools"
PHP="${PHP:-$TOOLS/php}"
COMPOSER="${COMPOSER:-$PHP $TOOLS/composer.phar}"
DIST="$ROOT/dist"
STAGE="$DIST/stage"

echo "▶ Compilando assets (Vite)…"
cd "$ROOT"
rm -f public/hot
npm run build >/dev/null

echo "▶ Copiando aplicación…"
rm -rf "$DIST"
mkdir -p "$STAGE/wispcontrol" "$STAGE/public_html"
tar --exclude=./node_modules --exclude=./vendor --exclude=./.git --exclude=./dist --exclude=./tests --exclude=./mobile \
    --exclude=./.env --exclude=./public/hot --exclude=./public/storage \
    --exclude='./storage/logs/*' --exclude='./storage/framework/cache/data/*' \
    --exclude='./storage/framework/sessions/*' --exclude='./storage/framework/views/*' \
    --exclude='./storage/app/public/*' --exclude='./storage/app/private/backups' --exclude='./bootstrap/cache/*.php' \
    -cf - . | (cd "$STAGE/wispcontrol" && tar -xf -)

echo "▶ Dependencias PHP de producción…"
cd "$STAGE/wispcontrol"
$COMPOSER install --no-dev --optimize-autoloader --no-interaction --no-scripts --quiet
$PHP artisan package:discover --ansi >/dev/null 2>&1 || true
cp deploy/env.production.example .env.example.production

echo "▶ Preparando public_html…"
cp -R public/. "$STAGE/public_html/"
# index.php apuntando a la carpeta hermana ../wispcontrol
sed -i "s#__DIR__.'/../storage/framework/maintenance.php'#__DIR__.'/../wispcontrol/storage/framework/maintenance.php'#; s#__DIR__.'/../vendor/autoload.php'#__DIR__.'/../wispcontrol/vendor/autoload.php'#; s#__DIR__.'/../bootstrap/app.php'#__DIR__.'/../wispcontrol/bootstrap/app.php'#" "$STAGE/public_html/index.php"
# La app debe saber dónde está public_html (para assets compilados y storage)
cat > "$STAGE/wispcontrol/bootstrap/public_path.php" <<'EOF'
<?php
// Generado por deploy/build-package.sh: ruta pública cuando la app vive fuera de public_html.
return is_dir(__DIR__.'/../../public_html') ? realpath(__DIR__.'/../../public_html') : null;
EOF

for d in storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views storage/app/public bootstrap/cache; do
  mkdir -p "$STAGE/wispcontrol/$d"; touch "$STAGE/wispcontrol/$d/.gitkeep"
done

echo "▶ Comprimiendo…"
$PHP -r '
$src = $argv[1]; $zipPath = $argv[2];
$zip = new ZipArchive; $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) { $rel = str_replace("\\", "/", substr($f->getPathname(), strlen($src) + 1)); $zip->addFile($f->getPathname(), $rel); }
$zip->close();
' "$STAGE" "$DIST/wispcontrol-cpanel.zip"
rm -rf "$STAGE"
echo "✔ Paquete listo: dist/wispcontrol-cpanel.zip ($(du -h "$DIST/wispcontrol-cpanel.zip" | cut -f1))"
echo "  Recuerda volver a levantar 'npx vite' si estabas en modo desarrollo."
