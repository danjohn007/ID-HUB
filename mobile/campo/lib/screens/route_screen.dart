import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:provider/provider.dart';

import '../core/api.dart';
import '../core/format.dart';
import '../core/geo.dart';
import '../core/launch.dart';
import '../core/session.dart';
import '../models.dart';
import '../motion.dart';
import '../theme.dart';
import '../widgets.dart';
import 'open.dart';
import 'tickets/ticket_tile.dart';

/// 2. Ruta del día (sección 25): fecha, resumen, mapa con las paradas numeradas,
/// lista en orden y navegación con la app de mapas. Llegada / salida con GPS.
class RouteScreen extends StatefulWidget {
  const RouteScreen({super.key, required this.type});

  /// 'tecnico' o 'cobranza'
  final String type;

  @override
  State<RouteScreen> createState() => _RouteScreenState();
}

class _RouteScreenState extends State<RouteScreen> {
  final _loader = GlobalKey<LoaderState<Cached<FieldDay>>>();
  DateTime _date = DateUtils.dateOnly(DateTime.now());

  bool get _collector => widget.type == 'cobranza';
  bool get _isToday => DateUtils.isSameDay(_date, DateTime.now());

  Future<Cached<FieldDay>> _load() async {
    final api = context.read<Session>().api;
    final query = {'type': widget.type, 'date': Fmt.apiDate(_date)};
    if (_isToday) {
      final res = await api.getCached('/field/today', query: query, cacheKey: 'today-${widget.type}');
      return Cached(FieldDay(res.data), fromCache: res.fromCache, savedAt: res.savedAt);
    }
    return Cached(FieldDay(await api.get('/field/today', query: query)));
  }

  void _shift(int days) {
    setState(() => _date = days == 0 ? DateUtils.dateOnly(DateTime.now()) : _date.add(Duration(days: days)));
    _loader.currentState?.reload();
  }

  Future<void> _stopAction(RouteStop stop, String action) async {
    final outbox = context.read<Session>().outbox;
    final gps = await Geo.current();
    if (!mounted) return;
    // Sin señal queda en la bandeja de salida y se envía después (con su clave de idempotencia).
    final ok = await runQueued(
        context,
        () => outbox.submit(
            path: '/field/stops/${stop.id}/$action', data: gps?.toJson(), label: '${action == 'arrive' ? 'Llegada a' : 'Salida de'} ${stop.title}'),
        success: action == 'arrive' ? 'Llegada registrada${gps == null ? ' (sin GPS)' : ''}' : 'Salida registrada');
    if (ok) _loader.currentState?.reload();
  }

  Future<void> _openStop(RouteStop stop) async {
    await Open.stop(context, stop, collector: _collector);
    _loader.currentState?.reload();
  }

  void _stopMenu(RouteStop stop) {
    showModalBottomSheet(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          ListTile(title: Text(stop.client?.name ?? stop.title, style: const TextStyle(fontWeight: FontWeight.w800)), subtitle: Text(stop.title)),
          ListTile(leading: const Icon(Icons.open_in_new_rounded), title: const Text('Abrir detalle'), onTap: () {
            Navigator.pop(ctx);
            _openStop(stop);
          }),
          ListTile(leading: const Icon(Icons.navigation_outlined), title: const Text('Navegar'), onTap: () {
            Navigator.pop(ctx);
            Launch.navigate(context, stop.navigationUrl);
          }),
          if (!stop.done && stop.arrivedAt == null)
            ListTile(leading: const Icon(Icons.place_rounded, color: AppColors.info), title: const Text('Registrar llegada'), onTap: () {
              Navigator.pop(ctx);
              _stopAction(stop, 'arrive');
            }),
          if (stop.onSite)
            ListTile(leading: const Icon(Icons.logout_rounded, color: AppColors.success), title: const Text('Registrar salida'), onTap: () {
              Navigator.pop(ctx);
              _stopAction(stop, 'depart');
            }),
          const SizedBox(height: 8),
        ]),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Loader<Cached<FieldDay>>(
        key: _loader,
        load: _load,
        builder: (context, cached, reload) {
          final day = cached.data;
          final route = day.route;
          final stops = route?.stops ?? const <RouteStop>[];
          final done = stops.where((s) => s.done).length;
          final pending = _collector
              ? const <_MapPoint>[]
              : [
                  for (final t in day.tickets)
                    if (t.client?.location != null) _MapPoint(t.client!.location!, ticketIcon(t), AppColors.semantic(t.priority.color)),
                  for (final o in day.installations)
                    if (o.location != null) _MapPoint(o.location!, Icons.router_rounded, AppColors.teal),
                ];

          return ListView(padding: EdgeInsets.zero, children: [
            PageHeader(
              eyebrow: _collector ? 'Cobranza en campo' : 'Ruta del día',
              title: _collector ? 'Mi ruta de cobranza' : 'Mi ruta',
              actions: [if (!_isToday) HeaderAction(icon: Icons.today_rounded, tooltip: 'Ir a hoy', onTap: () => _shift(0))],
              bottom: Column(children: [
                _DateSwitcher(date: _date, onPrev: () => _shift(-1), onNext: () => _shift(1)),
                const SizedBox(height: 12),
                Row(children: [
                  StatTile(value: '${day.count('services')}', label: _collector ? 'Visitas' : 'Servicios'),
                  const SizedBox(width: 10),
                  StatTile(value: '${day.count('clients')}', label: 'Clientes'),
                  const SizedBox(width: 10),
                  _collector ? StatTile(value: '${day.count('done')}', label: 'Atendidas') : StatTile(value: '${day.count('tickets')}', label: 'Tickets'),
                ]),
              ]),
            ),
            Padding(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                if (cached.fromCache) OfflineBanner(savedAt: cached.savedAt),
                FadeSlideIn(index: 1, child: _RouteMap(stops: stops, pending: pending)),
                const SizedBox(height: 16),
                if (stops.isEmpty)
                  SectionCard(
                    index: 2,
                    child: EmptyState(
                      icon: Icons.route_outlined,
                      title: 'Sin ruta para esta fecha',
                      message: _collector
                          ? 'Revisa tus clientes pendientes en la pestaña Cobranza.'
                          : '${day.message ?? 'Tu supervisor aún no arma la ruta.'}${pending.isEmpty ? '' : ' En el mapa ves tus tickets e instalaciones pendientes.'}',
                    ),
                  )
                else ...[
                  FadeSlideIn(index: 2, child: _RouteProgress(done: done, total: stops.length, km: route?.distanceKm)),
                  const SizedBox(height: 16),
                  for (final (i, s) in stops.indexed)
                    FadeSlideIn(
                      index: 3 + i,
                      child: _StopTile(stop: s, first: i == 0, last: i == stops.length - 1, onTap: () => _openStop(s), onMore: () => _stopMenu(s)),
                    ),
                  const SizedBox(height: 12),
                  FilledButton.icon(
                    onPressed: () => Launch.navigate(context, route?.mapsUrl),
                    icon: const Icon(Icons.map_outlined),
                    label: const Text('Abrir recorrido en Maps'),
                  ),
                ],
                const SizedBox(height: 16),
              ]),
            ),
          ]);
        },
      ),
    );
  }
}

/// Selector de día sobre el encabezado navy.
class _DateSwitcher extends StatelessWidget {
  const _DateSwitcher({required this.date, required this.onPrev, required this.onNext});

  final DateTime date;
  final VoidCallback onPrev;
  final VoidCallback onNext;

  @override
  Widget build(BuildContext context) {
    final today = DateUtils.dateOnly(DateTime.now());
    final diff = DateUtils.dateOnly(date).difference(today).inDays;
    final rel = switch (diff) { 0 => 'Hoy', -1 => 'Ayer', 1 => 'Mañana', < 0 => 'Hace ${-diff} días', _ => 'En $diff días' };

    return Container(
      height: 56,
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .08),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.white.withValues(alpha: .11)),
      ),
      child: Row(children: [
        IconButton(onPressed: onPrev, icon: const Icon(Icons.chevron_left_rounded, color: Colors.white)),
        Expanded(
          child: AnimatedSwitcher(
            duration: Motion.fast,
            transitionBuilder: (c, a) => FadeTransition(opacity: a, child: ScaleTransition(scale: Tween(begin: .92, end: 1.0).animate(a), child: c)),
            child: Column(key: ValueKey(date), mainAxisAlignment: MainAxisAlignment.center, children: [
              Text(Fmt.longDate(date), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 15)),
              Text(rel, style: TextStyle(color: diff == 0 ? AppColors.brand400 : AppColors.navy200, fontSize: 12, fontWeight: FontWeight.w700)),
            ]),
          ),
        ),
        IconButton(onPressed: onNext, icon: const Icon(Icons.chevron_right_rounded, color: Colors.white)),
      ]),
    );
  }
}

/// Avance de la ruta: paradas atendidas y distancia.
class _RouteProgress extends StatelessWidget {
  const _RouteProgress({required this.done, required this.total, this.km});

  final int done;
  final int total;
  final double? km;

  @override
  Widget build(BuildContext context) {
    final brand = Theme.of(context).colorScheme.primary;
    final p = total == 0 ? 0.0 : done / total;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(children: [
          SizedBox(
            width: 58,
            height: 58,
            child: TweenAnimationBuilder<double>(
              tween: Tween(begin: 0, end: p),
              duration: const Duration(milliseconds: 1200),
              curve: Curves.easeOutCubic,
              builder: (_, v, _) => Stack(alignment: Alignment.center, children: [
                SizedBox.expand(child: CircularProgressIndicator(value: v, strokeWidth: 6, strokeCap: StrokeCap.round, backgroundColor: AppColors.border, color: p == 1 ? AppColors.success : brand)),
                Text('${(v * 100).round()}%', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13, color: AppColors.navy)),
              ]),
            ),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(p == 1 ? '¡Ruta terminada!' : 'Avance de la ruta', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16, color: AppColors.navy)),
              const SizedBox(height: 2),
              Text('$done de $total paradas atendidas', style: const TextStyle(color: AppColors.slate, fontSize: 13)),
              if (km != null) ...[
                const SizedBox(height: 6),
                Pill(label: '${km!.toStringAsFixed(1)} km estimados', color: brand, icon: Icons.straighten_rounded),
              ],
            ]),
          ),
        ]),
      ),
    );
  }
}

/// Punto pendiente (sin ruta): ticket o instalación con su icono y color.
class _MapPoint {
  const _MapPoint(this.location, this.icon, this.color);

  final LatLngJson location;
  final IconData icon;
  final Color color;
}

/// Mapa OpenStreetMap con las paradas numeradas y la línea del recorrido.
/// Sin ruta, muestra los tickets e instalaciones pendientes con su icono.
class _RouteMap extends StatelessWidget {
  const _RouteMap({required this.stops, this.pending = const []});

  final List<RouteStop> stops;
  final List<_MapPoint> pending;

  @override
  Widget build(BuildContext context) {
    final brand = Theme.of(context).colorScheme.primary;
    final routed = [for (final s in stops) if (s.location != null) s];
    final points = routed.isNotEmpty ? [for (final s in routed) LatLng(s.location!.lat, s.location!.lng)] : [for (final p in pending) LatLng(p.location.lat, p.location.lng)];

    final decoration = BoxDecoration(
      borderRadius: BorderRadius.circular(22),
      boxShadow: [BoxShadow(color: AppColors.navy.withValues(alpha: .1), blurRadius: 24, offset: const Offset(0, 10))],
    );

    if (points.isEmpty) {
      return Container(
        height: 170,
        decoration: decoration.copyWith(color: Colors.white, border: Border.all(color: AppColors.border)),
        child: const Column(mainAxisAlignment: MainAxisAlignment.center, children: [
          IconBadge(Icons.map_outlined, color: AppColors.slate, size: 52),
          SizedBox(height: 10),
          Text('Sin puntos para mostrar en el mapa', style: TextStyle(color: AppColors.slate, fontWeight: FontWeight.w600)),
        ]),
      );
    }

    final options = MapOptions(
      initialCenter: points.first,
      initialZoom: 15,
      initialCameraFit: points.length > 1 ? CameraFit.bounds(bounds: LatLngBounds.fromPoints(points), padding: const EdgeInsets.fromLTRB(40, 64, 40, 40)) : null,
      interactionOptions: const InteractionOptions(flags: InteractiveFlag.all & ~InteractiveFlag.rotate),
    );

    return Container(
      decoration: decoration,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(22),
        child: SizedBox(
          height: 280,
          child: Stack(children: [
            FlutterMap(
              options: options,
              children: [
                TileLayer(urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', userAgentPackageName: 'mx.impactosdigitales.wispcontrol_campo'),
                if (routed.length > 1)
                  PolylineLayer(polylines: [
                    Polyline(points: points, color: Colors.white, strokeWidth: 8),
                    Polyline(points: points, color: brand, strokeWidth: 4.5),
                  ]),
                MarkerLayer(markers: [
                  if (routed.isNotEmpty)
                    for (final s in routed)
                      Marker(
                        point: LatLng(s.location!.lat, s.location!.lng),
                        width: 40,
                        height: 40,
                        child: _Pin(
                          color: s.done ? AppColors.success : (s.onSite ? AppColors.warning : brand),
                          pulse: s.onSite,
                          child: s.done
                              ? const Icon(Icons.check_rounded, color: Colors.white, size: 17)
                              : Text('${s.sequence}', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 13)),
                        ),
                      )
                  else
                    for (final p in pending)
                      Marker(
                        point: LatLng(p.location.lat, p.location.lng),
                        width: 40,
                        height: 40,
                        child: _Pin(color: p.color, child: Icon(p.icon, color: Colors.white, size: 16)),
                      ),
                ]),
                const RichAttributionWidget(attributions: [TextSourceAttribution('OpenStreetMap')]),
              ],
            ),
            // Leyenda
            Positioned(
              left: 12,
              top: 12,
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 7),
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: .94),
                  borderRadius: BorderRadius.circular(12),
                  boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: .12), blurRadius: 10)],
                ),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  Icon(routed.isNotEmpty ? Icons.route_rounded : Icons.pending_actions_rounded, size: 16, color: brand),
                  const SizedBox(width: 6),
                  Text(
                    routed.isNotEmpty ? '${routed.length} paradas' : '${pending.length} pendientes sin ruta',
                    style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 12.5, color: AppColors.navy),
                  ),
                ]),
              ),
            ),
          ]),
        ),
      ),
    );
  }
}

/// Pin redondo con borde blanco y sombra de color; [pulse] para la parada en sitio.
class _Pin extends StatefulWidget {
  const _Pin({required this.color, required this.child, this.pulse = false});

  final Color color;
  final Widget child;
  final bool pulse;

  @override
  State<_Pin> createState() => _PinState();
}

class _PinState extends State<_Pin> with SingleTickerProviderStateMixin {
  late final _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1600));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (widget.pulse && !reduceMotion(context) && !_c.isAnimating) _c.repeat();
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final pin = Container(
      width: 32,
      height: 32,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: widget.color,
        shape: BoxShape.circle,
        border: Border.all(color: Colors.white, width: 3),
        boxShadow: [BoxShadow(color: widget.color.withValues(alpha: .5), blurRadius: 10, offset: const Offset(0, 3))],
      ),
      child: widget.child,
    );
    if (!widget.pulse) return Center(child: pin);
    return AnimatedBuilder(
      animation: _c,
      builder: (_, child) => Stack(alignment: Alignment.center, children: [
        Container(
          width: 32 + 22 * _c.value,
          height: 32 + 22 * _c.value,
          decoration: BoxDecoration(shape: BoxShape.circle, color: widget.color.withValues(alpha: .35 * (1 - _c.value))),
        ),
        child!,
      ]),
      child: pin,
    );
  }
}

/// Parada como línea de tiempo: número, línea que une las paradas y tarjeta.
class _StopTile extends StatelessWidget {
  const _StopTile({required this.stop, required this.first, required this.last, required this.onTap, required this.onMore});

  final RouteStop stop;
  final bool first;
  final bool last;
  final VoidCallback onTap;
  final VoidCallback onMore;

  @override
  Widget build(BuildContext context) {
    final brand = Theme.of(context).colorScheme.primary;
    final color = stop.done ? AppColors.success : (stop.onSite ? AppColors.warning : brand);
    final client = stop.client;
    final times = [
      if (stop.arrivedAt != null) 'Llegada ${Fmt.time(stop.arrivedAt)}',
      if (stop.departedAt != null) 'Salida ${Fmt.time(stop.departedAt)}',
      if (stop.status == 'skipped') 'Omitida',
    ].join(' · ');
    final line = stop.done ? AppColors.success.withValues(alpha: .5) : AppColors.border;

    return IntrinsicHeight(
      child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        SizedBox(
          width: 40,
          child: Column(children: [
            Container(width: 3, height: 18, color: first ? Colors.transparent : line),
            Container(
              width: 34,
              height: 34,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: color,
                shape: BoxShape.circle,
                boxShadow: [BoxShadow(color: color.withValues(alpha: .4), blurRadius: 10, offset: const Offset(0, 3))],
              ),
              child: stop.done
                  ? const Icon(Icons.check_rounded, color: Colors.white, size: 19)
                  : Text('${stop.sequence}', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
            ),
            Expanded(child: Container(width: 3, color: last ? Colors.transparent : line)),
          ]),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: AccentCard(
              accent: color,
              onTap: onTap,
              padding: const EdgeInsets.fromLTRB(14, 12, 4, 12),
              child: Row(children: [
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [
                      Text(stop.onSite ? 'EN SITIO' : stop.kindLabel.toUpperCase(),
                          style: TextStyle(color: color, fontWeight: FontWeight.w800, fontSize: 10.5, letterSpacing: .8)),
                    ]),
                    const SizedBox(height: 2),
                    Text(client?.name ?? stop.title,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: AppColors.navy, decoration: stop.done ? TextDecoration.lineThrough : null)),
                    Text(client != null ? '${stop.title} · ${stop.detail}' : stop.detail,
                        maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.slate, fontSize: 13)),
                    if (stop.type == 'client' && client != null) ...[
                      const SizedBox(height: 6),
                      Pill(label: 'Saldo ${Fmt.money(client.balance)}', color: AppColors.danger, icon: Icons.account_balance_wallet_outlined),
                    ],
                    if (times.isNotEmpty) ...[
                      const SizedBox(height: 6),
                      Pill(label: times, color: color, icon: Icons.schedule_rounded),
                    ],
                  ]),
                ),
                IconButton(onPressed: onMore, icon: const Icon(Icons.more_vert_rounded, color: AppColors.slate)),
              ]),
            ),
          ),
        ),
      ]),
    );
  }
}
