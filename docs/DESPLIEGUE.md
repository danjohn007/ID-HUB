# Despliegue en cPanel (PHP + MySQL 5.7)

ID-Hub está diseñado para hosting compartido con cPanel: **no necesita Node, Redis, Supervisor ni SSH**.
Todo el trabajo en segundo plano (cortes, recordatorios, monitoreo de red, mensajes y campañas) corre con
**un solo cron cada minuto**.

## Requisitos del hosting

| Requisito | Mínimo |
|---|---|
| PHP | **8.2** o superior (cPanel → *Select PHP Version* / *MultiPHP Manager*) |
| Extensiones PHP | pdo_mysql, mbstring, openssl, fileinfo, dom/xml, curl, zip (Excel), gd (imágenes en PDF) |
| Base de datos | MySQL **5.7.8+** (o MariaDB 10.2+) |
| Cron | 1 tarea cada minuto |
| Límites recomendados | `memory_limit` 256M, `max_execution_time` 120, `upload_max_filesize` 20M |

## 1. Generar el paquete (en tu computadora)

```bash
bash deploy/build-package.sh
```
Crea `dist/wispcontrol-cpanel.zip` con dos carpetas:

```
wispcontrol/   → la aplicación (va FUERA de public_html, por seguridad)
public_html/   → archivos públicos; su index.php ya apunta a ../wispcontrol
```

## 2. Subir al servidor

1. cPanel → **Administrador de archivos** → en `/home/TU_USUARIO/` sube el zip y extráelo.
2. Mueve el contenido de `public_html/` del zip dentro de tu `public_html` real
   (si usas un subdominio o dominio adicional, en su carpeta raíz).
   - Alternativa: si tu dominio permite cambiar la *raíz del documento*, apúntala a `/home/TU_USUARIO/wispcontrol/public`
     y no necesitas la carpeta `public_html/` del paquete (en ese caso borra `wispcontrol/bootstrap/public_path.php`).
3. Permisos: `wispcontrol/storage` y `wispcontrol/bootstrap/cache` con escritura (755/775).

## 3. Base de datos

cPanel → **Bases de datos MySQL**: crea la base, el usuario y asígnale **todos los privilegios**.

## 4. Archivo .env

En `wispcontrol/`, copia `.env.example.production` como `.env` y completa:
- `APP_URL` (con https), `APP_KEY` (genera una con `php artisan key:generate --show` en tu computadora),
- `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`,
- correo SMTP de una cuenta creada en cPanel,
- deja `INSTALLER_ENABLED=true`.

## 5. Instalador web

Abre `https://tudominio.com/install`:
- revisa que todos los requisitos estén en verde,
- crea el **superadministrador**,
- opcional: carga datos de demostración.

El instalador ejecuta las migraciones, crea el enlace `public/storage` y cachea la configuración.
**Al terminar cambia `INSTALLER_ENABLED=false`** en `.env` (además se bloquea solo si ya existe un superadmin).

> Con acceso a *Terminal* en cPanel también puedes instalar por consola:
> `php artisan migrate --force && php artisan db:seed --class=ProductionSeeder --force && php artisan storage:link && php artisan optimize`

## 6. Cron (obligatorio)

cPanel → **Trabajos de cron** → *Cada minuto* (`* * * * *`):

```
/usr/local/bin/php /home/TU_USUARIO/wispcontrol/artisan schedule:run >> /dev/null 2>&1
```
(La ruta del binario de PHP puede variar: en *MultiPHP* suele ser `/opt/cpanel/ea-php82/root/usr/bin/php`.)

Esto procesa: generación de cargos, recordatorios, cortes y reconexiones, monitoreo MikroTik,
detección de fallas, alertas, SLA de tickets, campañas y la cola de mensajes.

## 7. Primer uso

1. Entra como superadmin → **Empresas WISP** → crea tu WISP (se crea su administrador).
2. Entra como administrador del WISP → **Configuración**: datos fiscales, logo, política de cortes,
   pasarelas de pago, WhatsApp/SMS, zonas.
3. **Red → MikroTik**: registra tus routers (ver sección siguiente).
4. **Planes**, **Repetidores**, **Torres**, y da de alta o importa clientes.

## Conexión con MikroTik desde hosting compartido

Muchos hostings **bloquean conexiones salientes** a puertos como 8728/8729. Por eso hay tres modos:

| Modo | Cuándo usarlo |
|---|---|
| **API** (8728/8729) | Tu hosting permite conexiones salientes y el MikroTik tiene IP pública / VPN accesible. |
| **REST** (RouterOS v7, HTTPS 443) | Igual que API, pero vía HTTPS. Suele pasar mejor por firewalls. |
| **Push (agente)** | **Recomendado en cPanel.** El MikroTik se conecta al servidor: envía telemetría y descarga los comandos pendientes (cortes, reconexiones, cambios de plan) cada 1 minuto. En *Red → MikroTik → Script de agente* se genera el script para pegar en el router. |

Para el **bloqueo parcial** (redirigir al portal de pago) crea en el MikroTik una regla de firewall/NAT
para la address-list `WISP_MOROSOS` (o la que configures) que redirija HTTP al portal de pago.

## Pagos en línea (webhooks)

Configura en cada pasarela la URL de notificación:
- Mercado Pago: `https://tudominio.com/webhooks/mercadopago`
- Stripe (incluye OXXO): `https://tudominio.com/webhooks/stripe`
- PayPal: `https://tudominio.com/webhooks/paypal`

## WhatsApp

- **Meta WhatsApp Cloud API**: token permanente y Phone Number ID. Los mensajes iniciados por la empresa fuera
  de la ventana de 24 h requieren **plantillas aprobadas por Meta**.
- **Twilio**: SID, token y número remitente.
- Sin credenciales, el sistema funciona en modo **simulado** (registra los mensajes sin enviarlos).

## Actualizaciones

1. Genera un nuevo paquete y reemplaza los archivos (conserva `.env` y `storage/`).
2. Entra como superadministrador a **Panel SaaS → Sistema**. Si hay migraciones pendientes, pulsa
   **Respaldar y actualizar**: crea un respaldo de la base, aplica las migraciones y regenera las cachés
   (`optimize`). No requiere Terminal.
3. Si una migración falla, la pantalla muestra el error y el respaldo previo; descárgalo y restáuralo en
   phpMyAdmin → **Importar** (en MySQL 5.7 los cambios de esquema no se revierten solos).

Con Terminal también funciona: `php artisan platform:backup --reason=pre-migracion && php artisan migrate --force && php artisan optimize`.
El instalador (`/install`) solo sirve para la primera instalación.

## Respaldos

- **Automático:** el cron ejecuta `platform:backup` todos los días a las 03:00 (y antes de cada actualización).
  Genera `wispcontrol/storage/app/private/backups/wispcontrol-AAAAMMDD-HHMMSS-*.sql.gz` en PHP puro (no usa
  `mysqldump`), conserva los últimos 14 y se descargan desde **Panel SaaS → Sistema**.
- Esos respaldos quedan en el mismo servidor: descarga uno periódicamente o activa además cPanel →
  **Copias de seguridad** para tener una copia externa (incluye `wispcontrol/storage/app` con los archivos subidos).
- Restaurar: phpMyAdmin → base de datos → **Importar** el `.sql.gz` (o `gunzip -c archivo.sql.gz | mysql -u USUARIO -p BASE`).
