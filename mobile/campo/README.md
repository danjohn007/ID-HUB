# ID-Hub Campo — app del técnico y del cobrador

App nativa **Flutter** (Android / iOS) de los requerimientos 23–27: ruta del día, tickets, instalaciones,
evidencias con GPS, materiales, firma del cliente, inventario y cobranza en campo con corte de caja.
Consume la API v1 del panel (`docs/API.md`); no tiene backend propio.

## Pantallas

| Rol | Pestañas | Detalle |
|---|---|---|
| Técnico (`field.work`) | Inicio · Ruta · Clientes · Tickets · Más | Ticket → Ejecución de servicio (fotos antes/durante/después, comentarios, materiales, firma, "Guardar servicio") · Instalación (checklist, señal/velocidad, fotos, materiales, firma, finalizar) · Inventario · Mis instalaciones |
| Cobrador (`collection.field`) | Inicio · Ruta · Cobranza · Corte · Más | Ficha de cobro: pago en efectivo (con foto y GPS, comprobante por WhatsApp) o visita sin pago (no localizado, promesa, rechazo, cerrado) |

Al abrir, la app dibuja el logo (iD + ondas de señal) y lo sube al formulario de inicio de sesión;
con sesión guardada entra directo. Si el teléfono tiene "reducir animaciones", se omiten.

El modo se elige con los permisos del token. El color de marca es el `primary_color` del WISP.
Sin señal, Inicio, Ruta, Cobranza e Inventario muestran la última información guardada.

**Bandeja de salida (sin conexión):** llegada / salida de paradas, cambio de estado y comentarios de tickets,
visitas y cobros de cobranza y fotos de evidencia se guardan en el teléfono ("Sin conexión: se enviará al
recuperar señal") y se envían solos al volver a la app, cada minuto y tras cualquier respuesta correcta del
servidor. La barra inferior muestra "N pendientes por enviar". Cada acción lleva una clave `Idempotency-Key`
(UUID v4), así un reintento nunca duplica un cobro ni una visita. El corte de caja se entrega sólo con señal.

**Evidencias confiables:** las fotos de evidencia se toman sólo con la cámara (no desde la galería); la hora
(`taken_at`) y el GPS se registran en el momento de la captura. El servidor las guarda en disco privado y
la app las muestra con enlaces firmados de 30 minutos.

## Estructura

```
lib/
  config.dart          servidor por defecto (--dart-define=API_URL=...)
  core/                api (Dio + caché), session (token en almacenamiento seguro), outbox (bandeja sin conexión),
                       geo, photos (sólo cámara), uuid, launch, format
  models.dart          modelos de la API v1
  theme.dart           paleta y tipografía (Inter) iguales a la web (resources/css/app.css)
  brand.dart           isotipo en vector (se anima trazo por trazo), fondo de marca con ondas de señal
  motion.dart          animaciones comunes: entrada escalonada, cifras que cuentan, esqueleto de carga
  widgets.dart
  screens/
    shell.dart         barra inferior por rol
    tech/ route_screen.dart clients/ tickets/ installations/ work/ (evidencias, materiales, firma)
    collection/        inicio, pendientes, ficha de cobro, corte de caja
```

## Desarrollo

Requisitos: Flutter 3.41+, Android SDK (y Xcode para iOS).

```bash
cd mobile/campo
flutter pub get
flutter analyze && flutter test

# Contra el panel local (emulador Android: 10.0.2.2 es la máquina anfitriona)
flutter run --dart-define=API_URL=http://10.0.2.2:8000
```

El servidor también se cambia en la pantalla de inicio de sesión (botón con el dominio, abajo).
En depuración Android acepta `http://`; en release sólo `https://`.

Usuarios demo: `tecnico@wispcontrol.com` y `cobrador@wispcontrol.com` / `password`.

## Compilar

```bash
# Android (APK para instalar directo en los teléfonos)
flutter build apk --release --dart-define=API_URL=https://midominio.com
#   → build/app/outputs/flutter-apk/app-release.apk

# Google Play
flutter build appbundle --release --dart-define=API_URL=https://midominio.com
```

Si no se pasa `API_URL` se usa `https://id-hub.digital` (la raíz del panel, sin `/campo`: esa ruta es la versión web del técnico).

### Firma de Android

El release se firma con la llave de `android/key.properties`. Si ese archivo no existe, Gradle avisa y firma
con la llave de depuración (sirve para instalar el APK a mano, **no** para Google Play).

1. Crear el keystore una sola vez y guardarlo fuera de git (respaldo seguro: sin él no se pueden publicar actualizaciones):

   ```bash
   keytool -genkey -v -keystore android/app/idhub-campo.jks -keyalg RSA -keysize 2048 -validity 10000 -alias idhub-campo
   ```

2. Crear `android/key.properties` (también ignorado por git); `storeFile` es relativo a `android/app`:

   ```properties
   storePassword=LA_CONTRASEÑA_DEL_KEYSTORE
   keyPassword=LA_CONTRASEÑA_DE_LA_LLAVE
   keyAlias=idhub-campo
   storeFile=idhub-campo.jks
   ```

3. `flutter build appbundle --release ...` ya sale firmado con esa llave.

**Antes de publicar en Play Store / App Store:**
- Identificadores: Android `applicationId = mx.impactosdigitales.wispcontrol_campo`; iOS
  `PRODUCT_BUNDLE_IDENTIFIER = mx.impactosdigitales.wispcontrolcampo` (los bundle id de iOS no admiten "_").
  No cambiarlos después de publicar.
- Versión: `version` en `pubspec.yaml` (nombre+código, p. ej. `1.1.0+2`); subir el código en cada envío.
- Ícono y nombre de la app (`android:label`, `CFBundleDisplayName`).
- El mapa usa mosaicos de OpenStreetMap: para muchos usuarios conviene un proveedor con API key (MapTiler, Mapbox…)
  cambiando `urlTemplate` en `screens/route_screen.dart`.

## Pendiente

- Notificaciones push (FCM): la API ya registra tokens (`POST /push-tokens`); falta integrar `firebase_messaging`.
- Pestaña "Herramientas" del prototipo de inventario: el inventario aún no distingue herramientas de materiales.
