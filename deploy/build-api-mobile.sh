#!/usr/bin/env bash
# ================================================================
#  Paquete para instalar WISPCONTROL (panel + API de la app móvil) en una
#  SUBCARPETA de public_html, p. ej. public_html/api_mobile:
#
#    Upload_zip/api_mobile.zip              → descomprimir DENTRO de public_html/api_mobile
#      ├── index.php, .htaccess, build/…    (archivos públicos)
#      ├── storage/                         (fotos y firmas; sin enlaces simbólicos)
#      └── wispcontrol/                     (aplicación Laravel, bloqueada al público)
#    Upload_zip/base_datos_demo.sql.gz      → importar en phpMyAdmin (datos de prueba)
#    Upload_zip/INSTRUCCIONES.md
#
#  Uso:  bash deploy/build-api-mobile.sh
#  Variables opcionales:
#    PHP, COMPOSER              binarios (por defecto: php y composer.phar de C:/php o `composer`)
#    COMPOSER_FLAGS             p. ej. --ignore-platform-req=php si tu PHP local es más nuevo que el del lock
#    FOLDER=api_mobile          nombre de la carpeta en public_html
#    DUMP_DB=1                  exporta la BD local con datos demo (requiere mysqldump)
#    DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD MYSQLDUMP
# ================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FOLDER="${FOLDER:-api_mobile}"
PHP="${PHP:-php}"
if [ -z "${COMPOSER:-}" ]; then
  if [ -f /c/php/composer.phar ]; then COMPOSER="$PHP /c/php/composer.phar"; else COMPOSER="composer"; fi
fi
COMPOSER_FLAGS="${COMPOSER_FLAGS:-}"
OUT="$ROOT/Upload_zip"
STAGE="$ROOT/dist/$FOLDER"
CORE="$STAGE/wispcontrol"

echo "▶ Preparando $FOLDER …"
rm -rf "$STAGE"
mkdir -p "$CORE" "$OUT"
cd "$ROOT"
tar --exclude=./node_modules --exclude=./vendor --exclude=./.git --exclude=./dist --exclude=./tests \
    --exclude=./mobile --exclude=./Upload_zip --exclude=./docs --exclude=./.claude \
    --exclude=./.env --exclude=./public/hot --exclude=./public/storage \
    --exclude='./storage/logs/*' --exclude='./storage/framework/cache/data/*' \
    --exclude='./storage/framework/sessions/*' --exclude='./storage/framework/views/*' \
    --exclude='./storage/app/public/*' --exclude='./storage/app/private/*' --exclude='./bootstrap/cache/*.php' \
    --exclude=./.phpunit.result.cache --exclude=./.htaccess \
    -cf - . | (cd "$CORE" && tar -xf -)

echo "▶ Dependencias PHP de producción (sin dev)…"
cd "$CORE"
$COMPOSER install --no-dev --optimize-autoloader --no-interaction --no-scripts --quiet $COMPOSER_FLAGS
$PHP artisan package:discover --ansi >/dev/null 2>&1 || true

echo "▶ Archivos públicos en la raíz de $FOLDER…"
cp -R "$CORE/public/." "$STAGE/"
rm -rf "$CORE/public"
# index.php apunta a la subcarpeta wispcontrol/
sed -i "s#__DIR__.'/../storage/framework/maintenance.php'#__DIR__.'/wispcontrol/storage/framework/maintenance.php'#; s#__DIR__.'/../vendor/autoload.php'#__DIR__.'/wispcontrol/vendor/autoload.php'#; s#__DIR__.'/../bootstrap/app.php'#__DIR__.'/wispcontrol/bootstrap/app.php'#" "$STAGE/index.php"
grep -q "/wispcontrol/bootstrap/app.php" "$STAGE/index.php" || { echo "✖ No se pudo ajustar index.php"; exit 1; }

# La app sabe que su carpeta pública es la carpeta padre (public_html/$FOLDER)
cat > "$CORE/bootstrap/public_path.php" <<'EOF'
<?php
// Generado por deploy/build-api-mobile.sh: la carpeta pública es la carpeta padre de wispcontrol/.
return realpath(__DIR__.'/../..') ?: null;
EOF

# wispcontrol/ nunca se sirve por web (.env, vendor, storage…)
cat > "$CORE/.htaccess" <<'EOF'
# Carpeta interna de la aplicación: acceso web denegado.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
Options -Indexes
EOF

# Carpeta pública de archivos subidos (fotos de evidencia, firmas): sin ejecución de scripts
mkdir -p "$STAGE/storage"
touch "$STAGE/storage/.gitkeep"
cat > "$STAGE/storage/.htaccess" <<'EOF'
# Sólo archivos subidos (fotos, firmas, comprobantes): nunca ejecutar scripts aquí.
Options -Indexes -ExecCGI
<FilesMatch "\.(?i:php\d*|phtml|phar|pl|py|cgi|sh|htaccess)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
EOF

for d in storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views storage/app/public storage/app/private bootstrap/cache; do
  mkdir -p "$CORE/$d"; touch "$CORE/$d/.gitkeep"
done

echo "▶ .env de producción (con APP_KEY nueva)…"
KEY="$($PHP artisan key:generate --show --no-ansi 2>/dev/null | tail -1)"
[ -n "$KEY" ] || { echo "✖ No se pudo generar APP_KEY"; exit 1; }
sed -e "s#^APP_KEY=.*#APP_KEY=$KEY#" \
    -e "s#^APP_URL=.*#APP_URL=https://TU-DOMINIO.com/$FOLDER#" \
    -e "s#^INSTALLER_ENABLED=.*#INSTALLER_ENABLED=false#" \
    -e "s#^MAIL_MAILER=.*#MAIL_MAILER=log#" \
    "$ROOT/deploy/env.production.example" > "$CORE/.env"
cat >> "$CORE/.env" <<'EOF'

# App en subcarpeta de public_html: fotos y firmas en <carpeta>/storage (sin enlace simbólico)
PUBLIC_DISK_IN_WEBROOT=true
EOF
cp "$ROOT/deploy/env.production.example" "$CORE/.env.example.production"

echo "▶ Comprimiendo $OUT/$FOLDER.zip …"
rm -f "$OUT/$FOLDER.zip"
$PHP -r '
$src = $argv[1]; $zipPath = $argv[2];
$zip = new ZipArchive; $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
$n = 0;
foreach ($it as $f) { $rel = str_replace("\\", "/", substr($f->getPathname(), strlen($src) + 1)); $zip->addFile($f->getPathname(), $rel); $n++; }
$zip->close();
echo "  $n archivos\n";
' "$STAGE" "$OUT/$FOLDER.zip"

if [ "${DUMP_DB:-0}" = "1" ]; then
  echo "▶ Base de datos demo…"
  MYSQLDUMP="${MYSQLDUMP:-mysqldump}"
  ARGS=(-h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "${DB_USERNAME:-root}" --default-character-set=utf8mb4 --single-transaction --skip-lock-tables --no-tablespaces --skip-add-locks --skip-comments)
  [ -n "${DB_PASSWORD:-}" ] && ARGS+=(-p"$DB_PASSWORD")
  DB="${DB_DATABASE:-wispcontrol}"
  SQL="$ROOT/dist/base_datos_demo.sql"
  {
    echo "-- WISPCONTROL: base de datos de demostración (usuarios demo con contraseña \"password\"). Sólo para pruebas."
    echo "SET NAMES utf8mb4; SET FOREIGN_KEY_CHECKS=0;"
    "$MYSQLDUMP" "${ARGS[@]}" --no-data "$DB"
    # Datos sin sesiones, caché, colas ni tokens de acceso del equipo de desarrollo
    "$MYSQLDUMP" "${ARGS[@]}" --no-create-info \
      --ignore-table="$DB.sessions" --ignore-table="$DB.cache" --ignore-table="$DB.cache_locks" \
      --ignore-table="$DB.jobs" --ignore-table="$DB.failed_jobs" --ignore-table="$DB.job_batches" \
      --ignore-table="$DB.personal_access_tokens" --ignore-table="$DB.push_tokens" "$DB"
    echo "SET FOREIGN_KEY_CHECKS=1;"
  } | sed '/^\/\*M!999999\\- enable the sandbox mode \*\//d' > "$SQL"
  gzip -f -9 "$SQL"
  mv -f "$SQL.gz" "$OUT/base_datos_demo.sql.gz"
fi

cp "$ROOT/deploy/INSTRUCCIONES-api-mobile.md" "$OUT/INSTRUCCIONES.md"
echo "✔ Listo:"
ls -la "$OUT"
