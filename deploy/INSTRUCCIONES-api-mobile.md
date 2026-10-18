# Subir la API de la app móvil a `public_html/api_mobile`

Contenido de esta carpeta:

| Archivo | Para qué |
|---|---|
| `api_mobile.zip` | WISPCONTROL completo (panel web + API `/api/v1` de la app). Se descomprime **dentro** de `public_html/api_mobile` |
| `base_datos_demo.sql.gz` | Base de datos con datos de prueba (técnico, cobrador, clientes, tickets, órdenes, inventario) |
| `INSTRUCCIONES.md` | Este archivo |

Requisitos del hosting: PHP 8.2 o superior con pdo_mysql, mbstring, openssl, fileinfo, curl, dom, zip y gd, y MySQL 5.7.8+ o MariaDB 10.2+.

---

## 1. Subir y descomprimir

1. Entra a cPanel → **Administrador de archivos** → `public_html`.
2. Crea la carpeta **`api_mobile`** y entra en ella.
3. Sube `api_mobile.zip` ahí y usa **Extraer**. Deben quedar en `public_html/api_mobile/` estos elementos: `index.php`, `.htaccess`, `build/`, `storage/` y `wispcontrol/`.
   - Activa "Mostrar archivos ocultos" para ver los `.htaccess`.
4. Permisos de escritura (755 o 775) en:
   - `api_mobile/wispcontrol/storage` y todo su contenido
   - `api_mobile/wispcontrol/bootstrap/cache`
   - `api_mobile/storage`
5. Borra `api_mobile.zip` del servidor.

> `wispcontrol/` tiene un `.htaccess` que bloquea el acceso web. Ahí están `.env`, el código y las librerías, y nadie puede descargarlos desde el navegador.

## 2. Base de datos

1. cPanel → **Bases de datos MySQL**:
   - Crea la base, por ejemplo `usuario_wispmobile`.
   - Crea un usuario con contraseña.
   - Agrega el usuario a la base con **todos los privilegios**.
2. cPanel → **phpMyAdmin**:
   - Selecciona la base nueva → **Importar**.
   - Elige `base_datos_demo.sql.gz`, desde tu computadora, sin descomprimir.
   - Pulsa **Continuar**.

## 3. Configurar `.env`

Edita `public_html/api_mobile/wispcontrol/.env`. Solo hay que cambiar estas líneas:

```
APP_URL=https://TU-DOMINIO.com/api_mobile      ← tu dominio real, con /api_mobile y sin diagonal final
DB_DATABASE=usuario_wispmobile
DB_USERNAME=usuario_wispuser
DB_PASSWORD=la-contraseña-del-usuario
```

`APP_KEY` ya viene generada; **no la cambies**. `PUBLIC_DISK_IN_WEBROOT=true` guarda las fotos y firmas en `api_mobile/storage/`, así que no se necesita un enlace simbólico.

## 4. Probar el servidor

Abre en el navegador:

| URL | Debe mostrar |
|---|---|
| `https://TU-DOMINIO.com/api_mobile/up` | Página verde "Application up" |
| `https://TU-DOMINIO.com/api_mobile/api/v1` | `{"name":"WISPCONTROL API","version":"v1",...}` |
| `https://TU-DOMINIO.com/api_mobile/login` | Login del panel web |
| `https://TU-DOMINIO.com/api_mobile/wispcontrol/.env` | **403 Prohibido**. Si se ve el archivo, falta el `.htaccess` de `wispcontrol/` |

Usuarios de prueba; la contraseña de todos es `password`:

| Usuario | Rol | Dónde |
|---|---|---|
| `tecnico@wispcontrol.com` | Técnico: jornada, tickets, órdenes, clientes | App |
| `cobrador@wispcontrol.com` | Cobrador: cobranza y corte de caja | App |
| `supervisor@wispcontrol.com` | Supervisor: concilia cortes de caja | Panel web |
| `admin@wispcontrol.com` | Administrador del WISP | Panel web |
| `superadmin@wispcontrol.com` | Superadministrador SaaS | Panel web |

> **Importante:** son datos de demostración con contraseñas conocidas. Antes de usar el sistema con datos reales, cambia las contraseñas desde el panel en *Usuarios*, o instala con una base vacía (ver el anexo).

Si ves "500 | Server Error":
- Revisa `api_mobile/wispcontrol/storage/logs/laravel-AAAA-MM-DD.log`.
- Lo más común: datos de BD incorrectos en `.env`, o permisos de `storage/`.

## 5. Probar la app con Expo Go

En tu computadora, en la carpeta del proyecto:

```bash
cd mobile
npm install
```

Crea (o edita) el archivo `mobile/.env.local` con la dirección del servidor:

```
EXPO_PUBLIC_API_URL=https://TU-DOMINIO.com/api_mobile
```

Arranca el servidor de desarrollo:

```bash
npx expo start
```

1. Instala **Expo Go** en el teléfono, desde Play Store o App Store. Debe ser la versión para el **SDK 57**.
2. Escanea el código QR que aparece en la terminal: con Expo Go en Android, o con la cámara en iPhone. El teléfono y la computadora deben estar en la misma red Wi-Fi. Si no lo están, usa `npx expo start --tunnel`.
3. Inicia sesión con `tecnico@wispcontrol.com` o `cobrador@wispcontrol.com` / `password`.

En la pantalla de inicio de sesión también puedes cambiar el **Servidor** sin editar `.env.local`.

### Si Expo Go muestra "Compiling JS failed … expected" o "invalid expression"

Ese error **no** está en el código de la app. Significa que el paquete de JavaScript (unos 8 MB) llegó alterado al teléfono. En las pruebas lo causaba el **escudo web del antivirus (Avast)** de la computadora, que inspecciona el tráfico HTTP del puerto 8081. La posición del error cambia en cada intento.

Soluciones, de la más simple a la más completa:

1. Usar el túnel de Expo, que viaja cifrado y el antivirus no lo modifica:

   ```bash
   npx expo start --tunnel
   ```

2. Pausar unos minutos el *Escudo web* del antivirus mientras cargas la app, o agregar una excepción para el puerto 8081.
3. Con el teléfono conectado por USB y la depuración USB activada:

   ```bash
   adb reverse tcp:8081 tcp:8081
   ```

   Después abre `exp://127.0.0.1:8081` en Expo Go.

Las peticiones a la API (login, jornada, cobros) no tienen este problema: solo afecta la descarga del código en modo desarrollo.

## 6. APK para instalar directo (sin Expo Go)

```bash
cd mobile
npx eas build -p android --profile preview     # APK firmado por EAS (requiere cuenta Expo)
```

O compílalo localmente (requiere Android SDK y JDK 21):

```bash
npx expo prebuild --platform android
cd android && ./gradlew assembleRelease
```

El APK queda en `mobile/android/app/build/outputs/apk/release/app-release.apk`. La dirección del servidor se toma de `EXPO_PUBLIC_API_URL` al compilar.

---

## Anexo A: tareas programadas (opcional para probar la app)

Para cortes automáticos, recordatorios, monitoreo y la cola de mensajes, crea en cPanel → **Trabajos de cron** una tarea *cada minuto*:

```
/usr/local/bin/php /home/TU_USUARIO/public_html/api_mobile/wispcontrol/artisan schedule:run >> /dev/null 2>&1
```

## Anexo B: instalar con base de datos vacía (sin datos demo)

1. En el paso 2 no importes `base_datos_demo.sql.gz`; deja la base vacía.
2. En `.env` pon `INSTALLER_ENABLED=true`.
3. Abre `https://TU-DOMINIO.com/api_mobile/install`, crea el superadministrador y **no** marques los datos de demostración.
4. Vuelve a poner `INSTALLER_ENABLED=false`.
5. Desde el panel:
   - Crea el WISP, sus usuarios (técnicos y cobradores), planes y clientes.
   - Asigna el plan SaaS con la función **app_tecnico** o **api**. Sin ella, la app responde "El plan de tu empresa no incluye acceso a esta función".

## Anexo C: actualizar después

Vuelve a generar el paquete con `bash deploy/build-api-mobile.sh` y sube el contenido nuevo **sin** reemplazar:
- `wispcontrol/.env`
- `wispcontrol/storage/`
- `storage/` (las fotos y firmas)
