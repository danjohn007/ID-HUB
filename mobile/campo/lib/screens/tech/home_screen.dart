import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api.dart';
import '../../core/cache_store.dart';
import '../../core/format.dart';
import '../../core/geo.dart';
import '../../core/launch.dart';
import '../../core/session.dart';
import '../../models.dart';
import '../../motion.dart';
import '../../theme.dart';
import '../../widgets.dart';
import '../open.dart';
import '../shell.dart';
import '../tickets/ticket_tile.dart';

/// Inicio del técnico: resumen del día, siguiente parada, instalaciones y tickets prioritarios.
class TechHomeScreen extends StatefulWidget {
  const TechHomeScreen({super.key});

  @override
  State<TechHomeScreen> createState() => _TechHomeScreenState();
}

class _TechHomeScreenState extends State<TechHomeScreen> {
  final _loader = GlobalKey<LoaderState<Cached<FieldDay>>>();

  Future<Cached<FieldDay>> _load() async {
    final res = await context.read<Session>().api.getCached('/field/today', query: {'type': 'tecnico'}, cacheKey: CacheKeys.today('tecnico'));
    return Cached(FieldDay(res.data), fromCache: res.fromCache, savedAt: res.savedAt);
  }

  Future<void> _after(Future<void> nav) async {
    await nav;
    _loader.currentState?.reload();
  }

  Future<void> _shareLocation() async {
    final session = context.read<Session>();
    final gps = await Geo.current();
    if (!mounted) return;
    if (gps == null) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(Geo.lastError ?? 'Sin GPS'), backgroundColor: AppColors.warning));
      return;
    }
    await runQueued(context, () => session.outbox.submit(path: '/field/location', data: gps.toJson(), label: 'Ubicación compartida'), success: 'Ubicación compartida con la oficina');
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();

    return Loader<Cached<FieldDay>>(
      key: _loader,
      load: _load,
      builder: (context, cached, reload) {
        final day = cached.data;
        final stops = day.route?.stops ?? const <RouteStop>[];
        final next = stops.where((s) => s.onSite).firstOrNull ?? stops.where((s) => !s.done).firstOrNull;

        return ListView(padding: EdgeInsets.zero, children: [
          GreetingHeader(
            name: session.user?.name ?? '',
            subtitle: '${session.tenant?.name ?? ''} · ${Fmt.longDate(DateTime.now())}',
            bottom: Row(children: [
              StatTile(value: '${day.count('services')}', label: 'Servicios', onTap: () => Shell.of(context).go(ShellState.routeTab)),
              const SizedBox(width: 10),
              StatTile(value: '${day.count('clients')}', label: 'Clientes'),
              const SizedBox(width: 10),
              StatTile(value: '${day.count('tickets')}', label: 'Tickets', onTap: () => Shell.of(context).go(3)),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              if (cached.fromCache) OfflineBanner(savedAt: cached.savedAt),
              if (stops.isNotEmpty) ...[
                _Progress(done: day.count('done'), total: stops.length),
                const SizedBox(height: 12),
              ],
              if (next != null)
                FadeSlideIn(index: 2, child: _NextStop(stop: next, onOpen: () => _after(Open.stop(context, next, collector: false))))
              else if (stops.isNotEmpty)
                const SectionCard(index: 2, child: EmptyState(icon: Icons.task_alt_rounded, title: '¡Ruta terminada!', message: 'Atendiste todas las paradas de hoy.'))
              else
                SectionCard(
                  index: 2,
                  child: Column(children: [
                    EmptyState(icon: Icons.route_outlined, title: 'Sin ruta planeada', message: day.message ?? 'Tu supervisor aún no arma la ruta de hoy. Abajo tienes tus pendientes.'),
                  ]),
                ),
              if (day.installations.isNotEmpty) ...[
                const SizedBox(height: 16),
                SectionCard(
                  index: 3,
                  title: 'Instalaciones',
                  trailing: Text('${day.installations.length}', style: const TextStyle(color: AppColors.slate, fontWeight: FontWeight.w700)),
                  padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
                  child: Column(children: [
                    for (final o in day.installations.take(4))
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: CircleAvatar(backgroundColor: AppColors.teal.withValues(alpha: .12), child: const Icon(Icons.router_outlined, color: AppColors.teal)),
                        title: Text(o.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700)),
                        subtitle: Text('${o.folio} · ${o.scheduledAt == null ? 'Sin fecha' : Fmt.dateTime(o.scheduledAt)}'),
                        trailing: StatusChip.of(o.status, dense: true),
                        onTap: () => _after(Open.installation(context, o.id)),
                      ),
                  ]),
                ),
              ],
              if (day.tickets.isNotEmpty) ...[
                const SizedBox(height: 16),
                SectionCard(
                  index: 4,
                  title: 'Tickets prioritarios',
                  trailing: TextButton(onPressed: () => Shell.of(context).go(3), child: const Text('Ver todos')),
                  padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
                  child: Column(children: [
                    for (final t in day.tickets.take(4)) TicketTile(ticket: t, onTap: () => _after(Open.ticket(context, t.id))),
                  ]),
                ),
              ],
              const SizedBox(height: 16),
              FadeSlideIn(index: 5, child: OutlinedButton.icon(onPressed: _shareLocation, icon: const Icon(Icons.my_location_rounded), label: const Text('Compartir mi ubicación'))),
              const SizedBox(height: 24),
            ]),
          ),
        ]);
      },
    );
  }
}

class _Progress extends StatelessWidget {
  const _Progress({required this.done, required this.total});

  final int done;
  final int total;

  @override
  Widget build(BuildContext context) {
    return SectionCard(
      index: 1,
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          const Text('Avance de la ruta', style: TextStyle(fontWeight: FontWeight.w700)),
          const Spacer(),
          Text('$done de $total', style: const TextStyle(color: AppColors.slate, fontWeight: FontWeight.w600)),
        ]),
        const SizedBox(height: 10),
        ClipRRect(
          borderRadius: BorderRadius.circular(8),
          child: TweenAnimationBuilder<double>(
            tween: Tween(begin: 0, end: total == 0 ? 0 : done / total),
            duration: const Duration(milliseconds: 1100),
            curve: Curves.easeOutCubic,
            builder: (_, v, _) => LinearProgressIndicator(value: v, minHeight: 8, backgroundColor: AppColors.border),
          ),
        ),
      ]),
    );
  }
}

class _NextStop extends StatelessWidget {
  const _NextStop({required this.stop, required this.onOpen});

  final RouteStop stop;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    final brand = Theme.of(context).colorScheme.primary;
    final client = stop.client;
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onOpen,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Text(stop.onSite ? 'EN SITIO' : 'SIGUIENTE PARADA', style: TextStyle(color: stop.onSite ? AppColors.success : brand, fontWeight: FontWeight.w800, fontSize: 12, letterSpacing: .6)),
              const Spacer(),
              StatusChip(stop.kindLabel, color: 'info', dense: true),
            ]),
            const SizedBox(height: 8),
            Text(client?.name ?? stop.title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: AppColors.navy)),
            Text(client != null ? '${stop.title} · ${stop.detail}' : stop.detail, style: const TextStyle(color: AppColors.slate)),
            if (client != null && client.address.isNotEmpty) ...[
              const SizedBox(height: 8),
              Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const Icon(Icons.place_outlined, size: 18, color: AppColors.slate),
                const SizedBox(width: 6),
                Expanded(child: Text(client.address, style: const TextStyle(fontSize: 13))),
              ]),
            ],
            const SizedBox(height: 14),
            Row(children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: () => Launch.navigate(context, stop.navigationUrl),
                  icon: const Icon(Icons.navigation_outlined),
                  label: const Text('Navegar'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(child: FilledButton(onPressed: onOpen, child: const Text('Abrir'))),
            ]),
          ]),
        ),
      ),
    );
  }
}
