import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api.dart';
import '../../core/cache_store.dart';
import '../../core/format.dart';
import '../../core/geo.dart';
import '../../core/launch.dart';
import '../../core/session.dart';
import '../../models.dart';
import '../../theme.dart';
import '../../widgets.dart';
import '../open.dart';
import 'service_screen.dart';

/// 4. Ticket: detalle, SLA, en camino / llegada / salida e historial.
class TicketScreen extends StatefulWidget {
  const TicketScreen({super.key, required this.id});

  final int id;

  @override
  State<TicketScreen> createState() => _TicketScreenState();
}

class _TicketScreenState extends State<TicketScreen> {
  final _loader = GlobalKey<LoaderState<Ticket>>();
  String? _busy;

  /// Origen de los datos mostrados (servidor o copia guardada sin señal).
  Cached<Map<String, dynamic>>? _source;

  Future<Ticket> _load() async {
    final res = await context.read<Session>().api.getCached('/tickets/${widget.id}', cacheKey: CacheKeys.ticket(widget.id));
    _source = res;
    return Ticket(res.data['data']);
  }

  Future<void> _field(String action, String success) async {
    final outbox = context.read<Session>().outbox;
    setState(() => _busy = action);
    final gps = await Geo.current();
    if (!mounted) return;
    final ok = await runQueued(context, () => outbox.submit(path: '/field/tickets/${widget.id}/$action', data: gps?.toJson(), label: '$success · ticket #${widget.id}'),
        success: gps == null ? '$success (sin GPS)' : success);
    if (mounted) setState(() => _busy = null);
    if (ok) _loader.currentState?.reload();
  }

  Future<void> _execute(Ticket t) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => ServiceScreen(ticketId: t.id)));
    _loader.currentState?.reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Ticket')),
      body: Loader<Ticket>(
        key: _loader,
        load: _load,
        builder: (context, t, reload) {
          final client = t.client;
          return ListView(padding: EdgeInsets.zero, children: [
            HeroHeader(children: [
              Wrap(spacing: 6, runSpacing: 6, children: [
                FolioTag(t.folio),
                _OnDark(StatusChip.of(t.priority)),
                _OnDark(StatusChip.of(t.status)),
              ]),
              const SizedBox(height: 10),
              Text(t.subject, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: Colors.white)),
              Text(t.categoryLabel, style: const TextStyle(color: Color(0xFFB7C4DC))),
            ]),
            Padding(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                if (_source?.fromCache == true) OfflineBanner(savedAt: _source!.savedAt),
                SectionCard(
                  child: Column(children: [
                    InfoRow(icon: Icons.person_outline, label: 'Cliente', value: client?.name, onTap: client == null ? null : () => Open.client(context, client.id)),
                    InfoRow(icon: Icons.place_outlined, label: 'Dirección', value: client?.address),
                    InfoRow(icon: Icons.schedule, label: 'Reportado', value: Fmt.dateTime(t.createdAt)),
                    if (t.isOpen && t.slaDueAt != null)
                      InfoRow(icon: Icons.timer_outlined, label: 'SLA', value: '${Fmt.dateTime(t.slaDueAt)} (${t.slaBreached ? 'vencido' : Fmt.relative(t.slaDueAt)})'),
                    if (t.resolution != null) InfoRow(icon: Icons.task_alt, label: 'Solución', value: t.resolution),
                  ]),
                ),
                if (client != null) ...[
                  const SizedBox(height: 12),
                  Row(mainAxisAlignment: MainAxisAlignment.spaceEvenly, children: [
                    RoundAction(icon: Icons.call_outlined, label: 'Llamar', onTap: client.phone == null ? null : () => Launch.call(context, client.phone), color: AppColors.success),
                    RoundAction(icon: Icons.chat_outlined, label: 'WhatsApp', onTap: client.phone == null ? null : () => Launch.whatsapp(context, client.phone), color: const Color(0xFF25D366)),
                    RoundAction(icon: Icons.navigation_outlined, label: 'Navegar', onTap: client.location == null ? null : () => Launch.mapsTo(context, client.location!.lat, client.location!.lng)),
                  ]),
                ],
                const SizedBox(height: 12),
                if (t.isOpen) ..._actions(t),
                if (t.description != null && t.description!.isNotEmpty) ...[
                  const SizedBox(height: 12),
                  SectionCard(title: 'Descripción', child: Text(t.description!)),
                ],
                const SizedBox(height: 12),
                SectionCard(title: 'Historial', child: _Timeline(comments: t.comments)),
                const SizedBox(height: 24),
              ]),
            ),
          ]);
        },
      ),
    );
  }

  List<Widget> _actions(Ticket t) {
    final times = [
      if (t.onWayAt != null) 'En camino ${Fmt.time(t.onWayAt)}',
      if (t.arrivedAt != null) 'Llegada ${Fmt.time(t.arrivedAt)}',
      if (t.departedAt != null) 'Salida ${Fmt.time(t.departedAt)}',
    ];
    Widget busy(String key, String label) => Text(_busy == key ? 'Obteniendo GPS…' : label);

    return [
      if (t.arrivedAt == null && t.onWayAt == null && t.client != null)
        FilledButton.icon(onPressed: _busy != null ? null : () => _field('on-way', 'Se avisó al cliente que vas en camino'), icon: const Icon(Icons.local_shipping_outlined), label: busy('on-way', 'En camino')),
      if (t.arrivedAt == null) ...[
        if (t.onWayAt == null && t.client != null) ...[
          const SizedBox(height: 8),
          OutlinedButton.icon(onPressed: _busy != null ? null : () => _field('arrive', 'Llegada registrada'), icon: const Icon(Icons.place_rounded), label: busy('arrive', 'Llegué al domicilio')),
        ] else
          FilledButton.icon(onPressed: _busy != null ? null : () => _field('arrive', 'Llegada registrada'), icon: const Icon(Icons.place_rounded), label: busy('arrive', 'Llegué al domicilio')),
      ],
      if (t.arrivedAt != null) FilledButton.icon(onPressed: () => _execute(t), icon: const Icon(Icons.build_circle_outlined), label: const Text('Ejecutar servicio')),
      if (t.onSite) ...[
        const SizedBox(height: 8),
        OutlinedButton.icon(onPressed: _busy != null ? null : () => _field('depart', 'Salida registrada'), icon: const Icon(Icons.logout_rounded), label: busy('depart', 'Salí · terminar visita')),
      ],
      if (t.arrivedAt == null) ...[
        const SizedBox(height: 8),
        TextButton(onPressed: () => _execute(t), child: const Text('Abrir ejecución sin registrar llegada')),
      ],
      if (times.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 6), child: Text(times.join(' · '), textAlign: TextAlign.center, style: const TextStyle(color: AppColors.slate, fontSize: 12))),
    ];
  }
}

/// Chip legible sobre el encabezado oscuro.
class _OnDark extends StatelessWidget {
  const _OnDark(this.child);

  final Widget child;

  @override
  Widget build(BuildContext context) => DecoratedBox(decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20)), child: child);
}

class _Timeline extends StatelessWidget {
  const _Timeline({required this.comments});

  final List<Comment> comments;

  @override
  Widget build(BuildContext context) {
    if (comments.isEmpty) return const Text('Sin movimientos.', style: TextStyle(color: AppColors.slate));
    final brand = Theme.of(context).colorScheme.primary;
    return Column(children: [
      for (final c in comments.reversed.take(30))
        Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Padding(padding: const EdgeInsets.only(top: 4), child: Icon(c.type == 'comment' ? Icons.chat_bubble_outline : Icons.radio_button_checked, size: 14, color: brand)),
            const SizedBox(width: 10),
            SizedBox(width: 74, child: Text(Fmt.dateTime(c.createdAt), style: const TextStyle(fontSize: 12, color: AppColors.slate))),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(c.body, style: const TextStyle(fontSize: 13)),
                Text('${c.author}${c.internal ? ' · interno' : ''}${c.pending ? ' · por subir' : ''}', style: const TextStyle(fontSize: 11, color: AppColors.slate)),
              ]),
            ),
          ]),
        ),
    ]);
  }
}
