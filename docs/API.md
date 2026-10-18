# WISPCONTROL — API REST v1

Base: `https://<dominio>/api/v1` (local: `http://127.0.0.1:8000/api/v1`). Página pública: `/api/docs`.

API para las apps móviles (técnico, cobrador y cliente, secciones 23, 26, 33 y 34) e integraciones
(sección 50: ERP, contabilidad, CRM, pasarelas, IoT). Todas las respuestas son JSON en UTF-8 y los
mensajes están en español.

## Arquitectura

| Pieza | Archivo |
|---|---|
| Rutas | `routes/api/v1.php` (cargado por `routes/api.php`, prefijo `/api`) |
| Controladores | `app/Http/Controllers/Api/V1/*` (base `ApiController`: validación en español, paginación, helpers) |
| Recursos JSON | `app/Http/Resources/*` |
| Middleware | `ApiForceJson` (JSON + UTF-8), `ApiAuthenticate` (token + tenant + plan), `ApiAbility` (permiso), `ApiKeyAuthenticate` (integraciones) |
| Limitadores y errores | `app/Providers/ApiServiceProvider.php` (`throttle:api` 120/min por token, `throttle:api-login` 10/min por IP y 5/min por usuario) |
| Documentación | `docs/API.md`, `/api/docs` (`routes/public/api-docs.php`, `ApiDocsController`) |

### Autenticación y multi-tenant
- Tokens **Laravel Sanctum** (`personal_access_tokens`). `User` y `Client` usan `HasApiTokens`; el
  `tokenable_type` se guarda con el alias del morph map (`user` / `client`).
- `ApiAuthenticate:<tipo>,<funciones>` resuelve el token, valida expiración, bloquea usuarios inactivos y
  WISP suspendidos, y fija el `TenantContext` con el WISP del dueño del token → todos los modelos
  `TenantModel` quedan filtrados (un token de un WISP nunca ve registros de otro: responde 404).
- Los IDs se resuelven **dentro** de los controladores (no hay route-model binding implícito) para que el
  scope del tenant ya esté activo.
- Token del personal: habilidades = `['staff', ...permisos del rol]` (`config/permissions.php`; admin `*`).
  Cada endpoint verifica `$user->hasAbility()` **y** `$token->can()`.
- Token del cliente: habilidad `client`. El cliente **no** se autentica en el guard (así `auth()->id()` sigue
  siendo `null` en los servicios: los tickets que crea quedan con `created_by = null`).
- Superadministrador: enviar `X-Tenant-Id: <id>` para operar un WISP.
- Funciones del plan SaaS (`Tenant::hasFeature`):
  - `api` → administración e integraciones (alta/edición de clientes, pagos, cargos, red, alertas, métricas, API key).
  - `api` o `app_tecnico` → apps de personal (clientes lectura, planes, tickets, campo, cobranza, push).
  - `app_cliente` o `api` → app del cliente.

### Formato
- Listados paginados: `{ "data": [...], "links": {...}, "meta": { "current_page", "last_page", "per_page", "total" } }`.
  Parámetros `page`, `per_page` (máx. 100) y `sort=campo` / `sort=-campo` donde aplica.
- Catálogos: `{ "value": "activo", "label": "Activo", "color": "success" }`.
- Fechas ISO-8601 (`2026-10-08T11:52:36-06:00`), fechas simples `Y-m-d`, montos numéricos con 2 decimales.
- Errores:

| Código | Ejemplo |
|---|---|
| 401 | `{"message":"No autenticado. Envía un token válido en el encabezado Authorization: Bearer."}` |
| 403 | `{"message":"No tienes permiso para realizar esta acción (billing.view)."}` / `"El plan de tu empresa no incluye acceso a esta función de la API (api)."` |
| 404 | `{"message":"Recurso no encontrado."}` |
| 422 | `{"message":"Los datos enviados no son válidos.","errors":{"amount":["El campo monto debe ser mayor a 0."]}}` |
| 429 | `{"message":"Demasiadas solicitudes. Intenta de nuevo en 42 segundos."}` |
| 500 | `{"message":"Error interno del servidor. Intenta más tarde."}` (con `debug` sólo si `APP_DEBUG=true`) |

---

## 1. Autenticación

### POST /auth/login (personal)
```bash
curl -X POST $API/auth/login -d "email=tecnico@wispcontrol.com&password=password&device_name=Moto G"
```
```json
{"message":"Sesión iniciada.","token":"3|e799SY...","token_type":"Bearer",
 "abilities":["staff","field.work","tickets.view","clients.view","map.view","network.view","inventory.view"],
 "user":{"id":6,"name":"Carlos Hernández","role":"tecnico","role_label":"Técnico",...},
 "tenant":{"id":1,"name":"Comunidad Valle Verde","slug":"valle-verde","primary_color":"#1A7FF5",...}}
```

### POST /client-auth/login (cliente)
`identifier` = ID de cliente (`code`) o usuario PPPoE; `password` = contraseña del portal
(`clients.portal_password`, hash bcrypt); si el cliente aún no tiene contraseña de portal se acepta su
contraseña PPPoE (`must_set_password: true` en la respuesta). `tenant` (slug) es opcional y sólo se
necesita si el mismo ID existe en dos WISP con la misma contraseña (la app de marca blanca lo envía siempre).
```bash
curl -X POST $API/client-auth/login -d "identifier=1002&password=cliente123&device_name=iPhone&tenant=valle-verde"
```

### POST /auth/logout · POST /client-auth/logout
Revoca el token actual. `push_token` opcional para dar de baja el dispositivo.

### GET /me
Datos del usuario o cliente del token, WISP y permisos.

---

## 2. Personal — clientes, planes y cobranza

```bash
H="Authorization: Bearer $TOKEN"
curl "$API/clients?search=rosa&status=moroso,suspendido&with_balance=1&per_page=20" -H "$H"
curl "$API/clients/1" -H "$H"
curl "$API/clients/1/statement" -H "$H"           # incluye pdf_url firmado (24 h)
curl -X POST "$API/clients" -H "$H" -H "Content-Type: application/json" \
  -d '{"first_name":"Juan","last_name":"Pérez","phone":"4421112233","plan_id":1,"zone_id":1,"activate":true}'
curl -X PATCH "$API/clients/1119" -H "$H" -d "phone=4429998877&plan_id=2"
curl "$API/plans" -H "$H"
curl "$API/payments?client_id=1&from=2026-10-01" -H "$H"
curl -X POST "$API/payments" -H "$H" -d "client_id=1&amount=250&method=transferencia&reference=SPEI-123"
curl "$API/charges?overdue=1" -H "$H"
```
- `POST /clients` requiere `clients.manage` y la función `api`. Opcionales: `code` (si no, consecutivo),
  `activate` (default `true`, aprovisiona en MikroTik), `charge_installation` (default `true`), `referral_code`.
- `PATCH /clients/{id}` no cambia `service_status` (eso se hace con suspender/reactivar en el panel); el
  cambio de `plan_id` pasa por `ServiceControl::changePlan`.
- `POST /payments` usa `BillingService::registerPayment` (aplicación FIFO, saldo a favor, mensajes y
  automatizaciones). Métodos: `efectivo, transferencia, deposito, tarjeta, descuento, bonificacion`.
  Cada pago trae `receipt_url`: PDF del comprobante con firma temporal (7 días), sin sesión.

## 3. Personal — tickets

```bash
curl "$API/tickets?status=open&priority=critica,alta&mine=1" -H "$H"
curl -X POST "$API/tickets" -H "$H" -d "client_id=2&category=lento&priority=media&subject=Internet lento"
curl -X POST "$API/tickets/302/assign" -H "$H" -d "technician_id=6"
curl -X PATCH "$API/tickets/264/status" -H "$H" -H "Content-Type: application/json" \
  -d '{"status":"resuelto","note":"Antena realineada, señal -58 dBm","lat":20.75,"lng":-100.45}'
curl -X POST "$API/tickets/264/comments" -H "$H" -d "body=Se realineó la antena&internal=0"
curl -X POST "$API/tickets/264/attachments" -H "$H" \
  -F "file=@foto.jpg" -F "kind=despues" -F "lat=20.7546" -F "lng=-100.4513" -F "taken_at=2026-10-08 11:50"
```
- Técnicos (`field.work` sin `tickets.manage`): sólo operan tickets asignados a ellos y no pueden cerrar ni cancelar.
- Evidencias: `jpg, jpeg, png, webp, heic, pdf` ≤ 10 MB, disco `public` (`attachments/<tenant>/ticket/<id>/`),
  `attachments.attachable_type = 'ticket'`, con GPS y fecha/hora (sección 24). `kind`: `antes, durante,
  despues, equipo, instalacion, firma, comprobante, otro`.

## 4. App del técnico

```bash
curl "$API/field/today" -H "$H"                       # ?date=2026-10-07&type=tecnico|cobranza
curl -X POST "$API/field/tickets/264/on-way" -H "$H" -d "lat=20.75&lng=-100.45"   # avisa "Técnico en camino"
curl -X POST "$API/field/stops/2/arrive" -H "$H" -d "lat=20.7546&lng=-100.4513"
curl -X POST "$API/field/stops/2/depart" -H "$H" -d "lat=20.7546&lng=-100.4513"
curl -X POST "$API/field/location" -H "$H" -d "lat=20.70&lng=-100.44"           # users.last_lat/last_lng
curl -X POST "$API/push-tokens" -H "$H" -d "token=<fcm-token>&platform=android"
```
`/field/today` devuelve la ruta (`maps_url` de Google Maps con todas las paradas, cada parada con
`navigation_url`, cliente y ticket/orden) y los tickets abiertos asignados ordenados por prioridad y SLA.
Llegada/salida usan `App\Services\Field\FieldTracker` (actualiza la parada, el ticket y el estado de la ruta).

## 5. App del cobrador

```bash
curl "$API/collection/pending?per_page=50" -H "$H"
curl -X POST "$API/collection/visits" -H "$H" -d "client_id=62&result=promesa&promise_date=2026-10-12&promise_amount=200"
curl -X POST "$API/collection/payments" -H "$H" -F client_id=255 -F amount=100 -F lat=20.71 -F lng=-100.41 -F evidence=@ticket.jpg
curl "$API/collection/cash-cut" -H "$H"
```
El cobro en efectivo registra el pago (`collector_id` = cobrador), una visita `pago` y lo agrega al corte de
caja abierto (`CashCutService::attach`), que el supervisor concilia en el panel.

## 5.1 App de campo (técnicos y cobradores) — `mobile/`

La app móvil de campo (`mobile/`, Expo) usa estos endpoints además de los anteriores. Reutilizan los
mismos servicios que las pantallas web «Mi jornada», «Ticket» y «Mi cobranza», así que el resultado es idéntico.

**Técnico**
```bash
curl "$API/field/today" -H "$H"            # ruta guardada o sugerida: suggested, stops[], summary, maps_url
curl -X POST "$API/field/start-day" -H "$H" # guarda la sugerencia como ruta del día
curl -X POST "$API/field/stops/12/skip" -H "$H"
curl "$API/inventory/items?search=antena" -H "$H"
curl -X POST "$API/tickets/264/materials" -H "$H" -d "item_id=3&quantity=1&type=instalacion&serial=LBE-9921"
curl -X POST "$API/tickets/264/materials/88/return" -H "$H"
curl -X POST "$API/tickets/264/signature" -H "$H" -d "signed_by=Juan Pérez&signature=data:image/png;base64,iVBOR..."
curl -X DELETE "$API/tickets/264/attachments/51" -H "$H"
curl "$API/installations?scope=open" -H "$H"
curl -X POST "$API/installations/7/start" -H "$H"
curl -X POST "$API/installations/7/checklist" -H "$H" -d "key=antena&done=1"
curl -X POST "$API/installations/7/measures" -H "$H" -d "signal_dbm=-58&speed_down=30&speed_up=8"
curl -X POST "$API/installations/7/attachments" -H "$H" -F "file=@antena.jpg" -F "kind=instalacion"
curl -X POST "$API/installations/7/signature" -H "$H" -d "signed_by=Ana López&signature=data:image/png;base64,..."
curl -X POST "$API/installations/7/complete" -H "$H"   # 422 con "missing" si falta checklist
```
- `GET /tickets/{id}` ahora incluye `client_detail` (plan, repetidor, señal, referencias), `materials`, `signature`,
  `on_way_at`, `navigation_url` y `open`.
- Reglas iguales a la web: el técnico sin `tickets.manage` sólo cambia a `en_proceso`, `en_espera` o `resuelto`
  (403 en otros) y `resuelto` exige `note` (mín. 5 caracteres, 422).
- Órdenes de instalación: se inician cuando están `programada` y se trabajan (checklist, mediciones, fotos, firma,
  materiales, finalizar) sólo `en_proceso`. Fotografía y firma marcan su punto del checklist automáticamente.
  Cotización, programación y activación siguen en el panel.

**Cobrador**
```bash
curl "$API/collection/today?view=pendientes&lat=20.70&lng=-100.44" -H "$H"   # resumen, corte, ruta, clientes en orden
curl "$API/collection/today?view=visitados" -H "$H"
curl "$API/collection/clients/62" -H "$H"         # cargos pendientes, últimos pagos y visitas
curl -X POST "$API/collection/payments" -H "$H" -d "client_id=62&amount=200&method=transferencia&reference=SPEI-1"
curl -X POST "$API/collection/cash-cut/close" -H "$H" -d "counted_amount=1450&notes=Entrego en oficina"
curl "$API/collection/cash-cuts" -H "$H"
```
- `collection/today` ordena como «Mi cobranza»: primero las paradas de la ruta del día y después por cercanía
  desde `lat/lng` (o la última ubicación). Excluye a los visitados hoy (`view=visitados` los lista).
- Visitas y cobros marcan como atendida la parada del cliente en la ruta del día.
- Sólo se opera sobre clientes asignados al cobrador (`billing.manage` puede operar cualquiera).
- **Hacer el corte** cierra el corte abierto (`closed`, «por entregar»); el efectivo contado queda en las notas.
  El supervisor registra la entrega y concilia en *Cobranza → Cortes de caja* (`delivered` → `reconciled`).

## 6. Red, alertas y métricas

```bash
curl "$API/network/repeaters?status=warning,offline" -H "$H"
curl "$API/network/devices?type=mikrotik,ap&status=offline" -H "$H"
curl "$API/alerts?severity=critical" -H "$H"
curl "$API/metrics/summary" -H "$H"     # MetricsService::headline(); mrr/collected sólo con finance.view
```

## 7. App del cliente (sección 33)

```bash
C="Authorization: Bearer $CLIENT_TOKEN"
curl "$API/client/home" -H "$C"
```
```json
{"greeting":"Hola, Adriana",
 "headline":"Hola, Adriana · Internet ACTIVO · Plan Plus 30 Mbps · Próximo pago $450 · 6 de noviembre",
 "service":{"status":"activo","label":"ACTIVO","color":"success","online":true,"active":true},
 "plan":{"name":"Plus","speed":"30 Mbps","download_mbps":30,"upload_mbps":8},
 "next_payment":{"amount":450,"amount_label":"$450.00","date":"2026-11-06","date_label":"6 de noviembre","overdue":false,"pending_balance":false},
 "balance":0,"credit_balance":0,"open_tickets":1,"promotions":2,"tenant":{...marca del WISP...}}
```
| Endpoint | Uso |
|---|---|
| `GET /client/status` | Estado, conexión, señal, saldo, atraso y mensaje |
| `GET /client/plan` | Plan y planes superiores públicos |
| `GET /client/usage` | `month_usage_gb`, límite y % |
| `GET /client/payments` | Historial con `receipt_url` (PDF firmado) |
| `GET /client/charges` | Cargos pendientes (`all=1` historial) |
| `POST /client/pay` | `{"url": ".../pagar/<token>"}` — `BillingService::paymentLinkFor()`; `amount` opcional |
| `POST /client/report` | Falla → ticket `source=cliente` (`category`: sin_internet, lento, antena, router, pago, domicilio, plan, otro; `description`). Si ya hay uno abierto de la misma categoría → 409 con el ticket |
| `GET /client/tickets`, `GET /client/tickets/{id}`, `POST /client/tickets/{id}/comments` | Mis reportes y comentarios públicos |
| `GET /client/support` | Teléfono, WhatsApp (`whatsapp_url` wa.me), correo, horario (`portal.support_hours`) |
| `GET/PATCH /client/profile` | `phone, whatsapp, email, references`; cambio de contraseña con `current_password`, `new_password`, `new_password_confirmation` (se guarda con hash) |
| `GET /client/promotions` | Promociones vigentes con `show_in_portal` que aplican a su plan |
| `GET /client/referrals` | Código (`ReferralService::ensureCode`), `share_url`, recompensa y referidos |
| `POST/DELETE /client/push-token` | Registro de dispositivo en `push_tokens` (sección 34) |

## 8. Integraciones con API key

```bash
# Generar / rotar (admin con settings.manage; se muestra una sola vez)
curl -X POST "$API/integration/key" -H "$H"
# → {"api_key":"wc_1_<40 caracteres>","header":"X-Api-Key"}

K="X-Api-Key: wc_1_..."
curl "$API/integration/clients?updated_since=2026-10-01" -H "$K"
curl "$API/integration/clients/1001" -H "$K"
curl -X POST "$API/integration/payments" -H "$K" \
  -d "client_code=1001&amount=50&method=transferencia&external_id=ERP-555&reference=Banco"
```
Sólo se guarda el hash SHA-256 en `tenants.settings.api.key_hash`. `POST /integration/payments` es idempotente
por `external_id` (repetir devuelve `"duplicate": true` y el pago original).

## Datos de prueba (demo)
- Personal: `admin@wispcontrol.com`, `tecnico@wispcontrol.com`, `cobrador@wispcontrol.com` / `password`
  (WISP Valle Verde, plan ENTERPRISE). `admin@sierragorda.mx` (plan PRO: tiene `app_tecnico`/`app_cliente`
  pero no `api`, sirve para probar el bloqueo por plan).
- Cliente: `identifier=1002`, `password=cliente123`, `tenant=valle-verde`.

## Pendiente / limitaciones
- Envío real de notificaciones push (FCM): la API registra los tokens; el envío lo hace el módulo de mensajería.
- Firma del cliente y materiales usados en la app del técnico: ya disponibles (sección 5.1).
- Sin versionado de respuestas por encabezado: cambios incompatibles irán en `/api/v2`.
