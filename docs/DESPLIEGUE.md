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

Cada WISP configura su propio número en **Configuración → Mensajería**. Sin credenciales, el sistema funciona
en modo **simulado** (registra los mensajes sin enviarlos).

### Meta (WhatsApp Cloud API)

1. En [developers.facebook.com](https://developers.facebook.com) crea una app tipo **Empresa** y agrega el producto
   **WhatsApp**. Vincula (o crea) la cuenta de WhatsApp Business y agrega el número real (no debe estar en uso en la
   app de WhatsApp del teléfono).
2. En business.facebook.com → Configuración del negocio → **Usuarios del sistema**: crea un usuario del sistema
   (administrador), asígnale la app y la cuenta de WhatsApp, y genera un **token permanente** con los permisos
   `whatsapp_business_messaging` y `whatsapp_business_management`.
3. En el panel: proveedor **WhatsApp Cloud API (Meta)** y captura:
   - **Phone number ID** y **WhatsApp Business Account ID** (WhatsApp → Configuración de la API).
   - **Token de acceso** (el permanente del paso 2).
   - **App secret** (app → Configuración → Básica → Clave secreta de la app).
   Guarda y pulsa **Probar conexión**.
4. **Webhook**: en la app → WhatsApp → Configuración → Webhook, pega la **URL de devolución** y el **token de
   verificación** que muestra el panel, y suscríbete a los campos `messages` y `message_template_status_update`.
   Con esto llegan los estados de entrega, las respuestas de los clientes (bitácora y conversación) y la
   aprobación de plantillas. Requiere el panel publicado con **https**.
5. **Plantillas**: en **Comunicación → Plantillas** pulsa **Enviar todas a Meta** (o *Enviar a Meta* en cada aviso).
   Meta las revisa en minutos u horas; el estado se actualiza solo por el webhook o con **Estado en Meta**.
   Solo las plantillas aprobadas llegan fuera de la ventana de 24 h desde el último mensaje del cliente.
6. Agrega un método de pago en WhatsApp Manager (Meta cobra por conversación) y pasa la app a modo **En vivo**.

### Twilio

SID, token y número remitente (SMS y/o WhatsApp). Los avisos de WhatsApp usan el Content SID de cada plantilla
aprobada en Twilio → Content Template Builder.

## Notificaciones push (app de campo y app del cliente)

Las push salen por **Firebase Cloud Messaging (API HTTP v1)** con un solo proyecto de Firebase para toda la
plataforma. Avisos al técnico/cobrador: ticket o instalación asignada, ruta planeada por la oficina y alertas de
automatizaciones. Avisos al cliente: los eventos de las plantillas por el canal *Push*.

1. En [console.firebase.google.com](https://console.firebase.google.com) crea el proyecto (p. ej. `id-hub`).
2. **Servidor**: Configuración del proyecto → Cuentas de servicio → **Generar nueva clave privada**. Sube el JSON al
   servidor como `wispcontrol/storage/app/private/firebase.json` (no se versiona) o indica su ruta en `.env` con
   `FIREBASE_CREDENTIALS=/ruta/firebase.json`. Verifica en **Configuración → Mensajería → Probar Firebase**.
3. En cada WISP: **Configuración → Mensajería → Notificaciones push = Firebase Cloud Messaging**. Para avisos push
   a clientes, marca también *Push* en los canales predeterminados (o en la plantilla).
4. **App de campo** (`mobile/campo`), una sola vez en tu computadora:
   ```bash
   dart pub global activate flutterfire_cli
   npm install -g firebase-tools && firebase login
   cd mobile/campo && flutterfire configure --project=<id-del-proyecto> --platforms=android,ios
   ```
   Esto reemplaza `lib/firebase_options.dart` y agrega `android/app/google-services.json`. Compila de nuevo
   (`flutter build apk --release`). Sin este paso la app funciona igual, solo sin push.
5. **iOS** (requiere Mac y cuenta de Apple Developer): sube la llave APNs (.p8) en Firebase → Configuración →
   Cloud Messaging, y en Xcode agrega las capacidades *Push Notifications* y *Background Modes → Remote notifications*.

La prueba de push de **Comunicación → Plantillas** llega a los teléfonos donde tu usuario inició sesión en la app de campo.

## Variables nuevas del `.env` (opcionales)

| Variable | Para qué |
|---|---|
| `FIREBASE_CREDENTIALS` | Ruta del JSON de la cuenta de servicio de Firebase (por defecto `storage/app/private/firebase.json`). |
| `TENANT_ROOT_DOMAIN` | Dominio raíz para subdominios de los WISP (`miwisp.<dominio>`); requiere un registro DNS comodín `*.<dominio>` y el subdominio comodín en cPanel apuntando a `wispcontrol/public`. Los WISP con dominio propio deben apuntarlo (CNAME/A) a este servidor y agregarlo como dominio adicional en cPanel. |
| `SAAS_GRACE_DAYS` | Días que un WISP sigue operando con la suscripción vencida antes de suspenderse (10). |
| `SANCTUM_EXPIRATION` | Minutos sin uso para que caduque la sesión de las apps (86400 = 60 días; 0 = nunca). |
| `BACKUP_FTP_HOST`, `BACKUP_FTP_PORT`, `BACKUP_FTP_USERNAME`, `BACKUP_FTP_PASSWORD`, `BACKUP_FTP_PATH`, `BACKUP_FTP_SSL` | Copia externa de cada respaldo por FTP/FTPS (requiere la extensión `ftp` de PHP). |

## Tareas programadas nuevas (las corre el mismo cron de cada minuto)

- `saas:subscriptions` (diario 04:00): suspende WISP con prueba terminada o suscripción vencida + días de gracia.
  Al marcar pagada una factura SaaS, la vigencia se extiende al fin del mes siguiente y se reactiva el WISP.
- `platform:backup --files` (domingos 03:30): respaldo `.zip` de fotos, firmas y logos (se conservan 4).

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
