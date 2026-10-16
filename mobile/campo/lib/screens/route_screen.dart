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
import '../theme.dart';
import '../widgets.dart';
import 'open.dart';

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
    setState(() => _date = _date.add(Duration(days: days)));
    _loader.currentState?.reload();
  }

  Future<void> _stopAction(RouteStop stop, String action) async {
    final api = context.read<Session>().api;
    final gps = await Geo.current();
    if (!mounted) return;
    final ok = await runAction(context, () => api.post('/field/stops/${stop.id}/$action', data: gps?.toJson()),
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
      appBar: AppBar(title: Text(_collector ? 'Mi ruta de cobranza' : 'Mi ruta de hoy')),
      body: Loader<Cached<FieldDay>>(
        key: _loader,
        load: _load,
        builder: (context, cached, reload) {
          final day = cached.data;
          final route = day.route;
          final stops = route?.stops ?? const <RouteStop>[];

          return ListView(padding: const EdgeInsets.all(16), children: [
            if (cached.fromCache) OfflineBanner(savedAt: cached.savedAt),
            _DateBar(date: _date, onPrev: () => _shift(-1), onNext: () => _shift(1)),
            const SizedBox(height: 12),
            Row(children: [
              _Count(value: day.count('services'), label: _collector ? 'Visitas' : 'Servicios'),
              const SizedBox(width: 10),
              _Count(value: day.count('clients'), label: 'Clientes'),
              const SizedBox(width: 10),
              _collector ? _Count(value: day.count('done'), label: 'Atendidas') : _Count(value: day.count('tickets'), label: 'Tickets'),
            ]),
            const SizedBox(height: 12),
            _RouteMap(stops: stops, fallback: _collector ? const [] : [...day.tickets.map((t) => t.client?.location), ...day.installations.map((o) => o.location)]),
            const SizedBox(height: 12),
            if (stops.isEmpty)
              SectionCard(
                child: EmptyState(
                  icon: Icons.route_outlined,
                  title: 'Sin ruta para esta fecha',
                  message: _collector ? 'Revisa tus clientes pendientes en la pestaña Cobranza.' : (day.message ?? 'Tus tickets e instalaciones pendientes aparecen en Inicio y Tickets.'),
                ),
              )
            else ...[
              for (final s in stops) _StopTile(stop: s, onTap: () => _openStop(s), onMore: () => _stopMenu(s)),
              const SizedBox(height: 8),
              if (route?.distanceKm != null)
                Text('Recorrido estimado: ${route!.distanceKm!.toStringAsFixed(1)} km', textAlign: TextAlign.center, style: const TextStyle(color: AppColors.slate)),
              const SizedBox(height: 12),
              FilledButton.icon(
                onPressed: () => Launch.navigate(context, route?.mapsUrl),
                icon: const Icon(Icons.map_outlined),
                label: const Text('Ver ruta en mapa'),
              ),
            ],
            const SizedBox(height: 16),
          ]);
        },
      ),
    );
  }
}

class _DateBar extends StatelessWidget {
  const _DateBar({required this.date, required this.onPrev, required this.onNext});

  final DateTime date;
  final VoidCallback onPrev;
  final VoidCallback onNext;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Row(children: [
        IconButton(onPressed: onPrev, icon: const Icon(Icons.chevron_left_rounded)),
        Expanded(
          child: Column(children: [
            Text(Fmt.longDate(date), textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w700)),
            if (DateUtils.isSameDay(date, DateTime.now())) const Text('Hoy', style: TextStyle(fontSize: 12, color: AppColors.slate)),
          ]),
        ),
        IconButton(onPressed: onNext, icon: const Icon(Icons.chevron_right_rounded)),
      ]),
    );
  }
}

class _Count extends StatelessWidget {
  const _Count({required this.value, required this.label});

  final int value;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Card(
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 12),
          child: Column(children: [
            Text('$value', style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: AppColors.navy)),
            Text(label, style: const TextStyle(fontSize: 12, color: AppColors.slate)),
          ]),
        ),
      ),
    );
  }
}

/// Mapa OpenStreetMap con las paradas numeradas y la línea del recorrido.
class _RouteMap extends StatelessWidget {
  const _RouteMap({required this.stops, this.fallback = const []});

  final List<RouteStop> stops;
  final List<LatLngJson?> fallback;

  @override
  Widget build(BuildContext context) {
    final brand = Theme.of(context).colorScheme.primary;
    final points = <(LatLng, RouteStop?)>[
      for (final s in stops)
        if (s.location != null) (LatLng(s.location!.lat, s.location!.lng), s),
      if (stops.isEmpty)
        for (final p in fallback)
          if (p != null) (LatLng(p.lat, p.lng), null),
    ];
    if (points.isEmpty) {
      return Container(
        height: 120,
        alignment: Alignment.center,
        decoration: BoxDecoration(color: const Color(0xFFE2E8F0), borderRadius: BorderRadius.circular(16)),
        child: const Text('Sin coordenadas para mostrar en el mapa', style: TextStyle(color: AppColors.slate)),
      );
    }
    final latLngs = points.map((p) => p.$1).toList();
    final options = MapOptions(
      initialCenter: latLngs.first,
      initialZoom: 15,
      initialCameraFit: latLngs.length > 1 ? CameraFit.bounds(bounds: LatLngBounds.fromPoints(latLngs), padding: const EdgeInsets.all(36)) : null,
      interactionOptions: const InteractionOptions(flags: InteractiveFlag.all & ~InteractiveFlag.rotate),
    );

    return ClipRRect(
      borderRadius: BorderRadius.circular(16),
      child: SizedBox(
        height: 240,
        child: FlutterMap(
          options: options,
          children: [
            TileLayer(urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', userAgentPackageName: 'mx.impactosdigitales.wispcontrol_campo'),
            if (stops.length > 1) PolylineLayer(polylines: [Polyline(points: latLngs, color: brand, strokeWidth: 4)]),
            MarkerLayer(markers: [
              for (var i = 0; i < points.length; i++)
                Marker(
                  point: points[i].$1,
                  width: 30,
                  height: 30,
                  child: _Pin(number: points[i].$2?.sequence ?? i + 1, color: points[i].$2?.done == true ? AppColors.success : brand),
                ),
            ]),
            const RichAttributionWidget(attributions: [TextSourceAttribution('OpenStreetMap')]),
          ],
        ),
      ),
    );
  }
}

class _Pin extends StatelessWidget {
  const _Pin({required this.number, required this.color});

  final int number;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      alignment: Alignment.center,
      decoration: BoxDecoration(color: color, shape: BoxShape.circle, border: Border.all(color: Colors.white, width: 2.5), boxShadow: const [BoxShadow(blurRadius: 4, color: Colors.black26)]),
      child: Text('$number', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 13)),
    );
  }
}

class _StopTile extends StatelessWidget {
  const _StopTile({required this.stop, required this.onTap, required this.onMore});

  final RouteStop stop;
  final VoidCallback onTap;
  final VoidCallback onMore;

  @override
  Widget build(BuildContext context) {
    final brand = Theme.of(context).colorScheme.primary;
    final color = stop.done ? AppColors.success : (stop.onSite ? AppColors.warning : brand);
    final client = stop.client;
    final subtitle = [
      if (stop.arrivedAt != null) 'Llegada ${Fmt.time(stop.arrivedAt)}',
      if (stop.departedAt != null) 'Salida ${Fmt.time(stop.departedAt)}',
      if (stop.status == 'skipped') 'Omitida',
    ].join(' · ');

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Card(
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 12, 4, 12),
            child: Row(children: [
              CircleAvatar(
                radius: 16,
                backgroundColor: color,
                child: stop.done ? const Icon(Icons.check_rounded, color: Colors.white, size: 18) : Text('${stop.sequence}', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(client?.name ?? stop.title, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: FontWeight.w700, decoration: stop.done ? TextDecoration.lineThrough : null)),
                  Text(client != null ? '${stop.title} · ${stop.detail}' : stop.detail, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.slate, fontSize: 13)),
                  if (stop.type == 'client' && client != null) Text('Saldo ${Fmt.money(client.balance)}', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: AppColors.danger)),
                  if (subtitle.isNotEmpty) Text(subtitle, style: TextStyle(fontSize: 12, color: color, fontWeight: FontWeight.w600)),
                ]),
              ),
              IconButton(onPressed: onMore, icon: const Icon(Icons.more_vert_rounded)),
            ]),
          ),
        ),
      ),
    );
  }
}
