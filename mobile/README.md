# WISPCONTROL Campo — app móvil de técnicos y cobradores

App Android / iOS (Expo + React Native + TypeScript) para el personal de campo de cada WISP.
Usa la API REST v1 del sistema (`/api/v1`, ver `../docs/API.md`) y los mismos servicios que las
pantallas web «Mi jornada», «Ticket» y «Mi cobranza», así que lo que se registra en la app aparece igual en el panel.

## Funciones (requerimientos 23 a 27)

| Rol | Pantalla | Qué hace |
|---|---|---|
| Técnico (`field.work`) | **Jornada** | Ruta del día (guardada o sugerida por urgencia y cercanía), «Iniciar», mapa, navegar, en camino, llegué, salí, omitir |
| | **Tickets** → ticket | Datos del cliente, llegada / salida con GPS, estado (en proceso, en espera, resuelto con solución), fotos antes / durante / después / equipo con GPS, materiales (descuentan inventario), comentarios, firma del cliente |
| | **Órdenes** → orden | Iniciar instalación, checklist, mediciones, fotos, materiales, firma, finalizar |
| | **Clientes** | Búsqueda y ficha (contacto, ubicación, servicio, saldo) |
| Cobrador (`collection.field`) | **Cobranza** | Clientes con adeudo en orden de ruta y cercanía, monto, atraso, dirección, historial; registrar pago, no localizado, promesa, rechazo o domicilio cerrado, con foto y GPS |
| | **Corte** | Corte abierto y sus cobros, «Hacer corte de caja» con el efectivo contado, cortes por entregar y conciliados |

Las pestañas se muestran según el rol del usuario (los mismos permisos del panel, `config/permissions.php`).
El supervisor registra la entrega y concilia los cortes en el panel: *Cobranza → Cortes de caja*.

## Desarrollo

```bash
cd mobile
npm install
cp .env.example .env        # pon la IP de tu PC o el dominio del sistema
npx expo start              # escanea el QR con Expo Go (Android / iOS)
```

Servidor local accesible desde el teléfono (misma red Wi-Fi):

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

Usuarios demo (`password`): `tecnico@wispcontrol.com`, `cobrador@wispcontrol.com`, `supervisor@wispcontrol.com`.

La dirección del servidor también se puede cambiar desde la pantalla de inicio de sesión («Servidor»).

## Compilar APK / AAB (EAS)

```bash
npx eas build -p android --profile preview      # APK para instalar directo
npx eas build -p android --profile production   # AAB para Google Play
```

## Estructura

```
src/app/            Pantallas (expo-router): login, (tabs)/jornada|tickets|ordenes|cobranza|corte|clientes|perfil,
                    ticket/[id], orden/[id], cliente/[id]
src/components/     Kit de UI (equivalente a <x-ui.*> del panel), mapa, firma, evidencias, materiales, cobro/visita
src/lib/            api (cliente HTTP), auth (sesión con token Sanctum), geo (GPS), photos, format, types
src/theme.ts        Colores y tipografía del panel (resources/css/app.css)
```
