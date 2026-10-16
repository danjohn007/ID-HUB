# ID-Hub Campo — app del técnico y del cobrador

App nativa **Flutter** (Android / iOS) de los requerimientos 23–27: ruta del día, tickets, instalaciones,
evidencias con GPS, materiales, firma del cliente, inventario y cobranza en campo con corte de caja.
Consume la API v1 del panel (`docs/API.md`); no tiene backend propio.

## Pantallas

| Rol | Pestañas | Detalle |
|---|---|---|
| Técnico (`field.work`) | Inicio · Ruta · Clientes · Tickets · Más | Ticket → Ejecución de servicio (fotos antes/durante/después, comentarios, materiales, firma, "Guardar servicio") · Instalación (checklist, señal/velocidad, fotos, materiales, firma, finalizar) · Inventario · Mis instalaciones |
| Cobrador (`collection.field`) | Inicio · Ruta · Cobranza · Corte · Más | Ficha de cobro: pago en efectivo (con foto y GPS, comprobante por WhatsApp) o visita sin pago (no localizado, promesa, rechazo, cerrado) |

El modo se elige con los permisos del token. El color de marca es el `primary_color` del WISP.
Sin señal, Inicio, Ruta, Cobranza e Inventario muestran la última información guardada; las acciones
(llegada, fotos, cobros…) necesitan conexión.

## Estructura

```
lib/
  config.dart          servidor por defecto (--dart-define=API_URL=...)
  core/                api (Dio + caché), session (token en almacenamiento seguro), geo, photos, launch, format
  models.dart          modelos de la API v1
  widgets.dart theme.dart
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

Si no se pasa `API_URL` se usa `https://quickmovewellness.fit/ID-HUB/wispcontrol/public`.

**Antes de publicar en Play Store / App Store:**
- Firma: crear un keystore y configurar `signingConfigs.release` en `android/app/build.gradle.kts`
  (hoy el release se firma con la llave de depuración, sirve sólo para instalar el APK a mano).
- `applicationId` / bundle id: `mx.impactosdigitales.wispcontrol_campo` (cambiar si se publica con otra cuenta).
- Ícono y nombre de la app (`android:label`, `CFBundleDisplayName`).
- El mapa usa mosaicos de OpenStreetMap: para muchos usuarios conviene un proveedor con API key (MapTiler, Mapbox…)
  cambiando `urlTemplate` en `screens/route_screen.dart`.

## Pendiente

- Notificaciones push (FCM): la API ya registra tokens (`POST /push-tokens`); falta integrar `firebase_messaging`.
- Cola de acciones sin conexión (hoy sólo se lee la caché).
- Pestaña "Herramientas" del prototipo de inventario: el inventario aún no distingue herramientas de materiales.
