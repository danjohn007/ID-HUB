import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api.dart';
import '../../core/cache_store.dart';
import '../../core/day_download.dart';
import '../../core/session.dart';
import '../../models.dart';
import '../../widgets.dart';
import '../open.dart';
import 'ticket_tile.dart';

/// 6. Mis tickets: Asignados · En progreso · Cerrados.
class TicketsScreen extends StatelessWidget {
  const TicketsScreen({super.key});

  // Mismos estados que descarga DayDownload.ticketTabs.
  static const _tabs = [
    ('Asignados', 'abierto,asignado'),
    ('En progreso', 'en_proceso,en_espera'),
    ('Cerrados', 'resuelto,cerrado'),
  ];

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: _tabs.length,
      child: Scaffold(
        body: Column(children: [
          PageHeader(
            eyebrow: 'Soporte en campo',
            title: 'Mis tickets',
            subtitle: 'Ordenados por vencimiento del SLA',
            bottom: HeaderTabs(tabs: [for (final t in _tabs) t.$1]),
          ),
          Expanded(child: TabBarView(children: [for (final t in _tabs) _TicketList(status: t.$2, closed: t.$2.startsWith('resuelto'))])),
        ]),
      ),
    );
  }
}

class _TicketList extends StatefulWidget {
  const _TicketList({required this.status, required this.closed});

  final String status;
  final bool closed;

  @override
  State<_TicketList> createState() => _TicketListState();
}

class _TicketListState extends State<_TicketList> with AutomaticKeepAliveClientMixin {
  final _loader = GlobalKey<LoaderState<Cached<List<Ticket>>>>();

  @override
  bool get wantKeepAlive => true;

  Future<Cached<List<Ticket>>> _load() async {
    final res = await context.read<Session>().api.getCached('/tickets', query: DayDownload.ticketsQuery(widget.status), cacheKey: CacheKeys.tickets(widget.status));
    return Cached((res.data['data'] as List).map(Ticket.new).toList(), fromCache: res.fromCache, savedAt: res.savedAt);
  }

  @override
  Widget build(BuildContext context) {
    super.build(context);
    return Loader<Cached<List<Ticket>>>(
      key: _loader,
      load: _load,
      builder: (context, cached, reload) {
        final tickets = cached.data;
        final banner = cached.fromCache ? OfflineBanner(savedAt: cached.savedAt) : null;
        if (tickets.isEmpty) {
          return ListView(padding: const EdgeInsets.all(16), children: [
            ?banner,
            EmptyState(
              icon: widget.closed ? Icons.task_alt_rounded : Icons.inbox_outlined,
              title: widget.closed ? 'Sin tickets cerrados' : 'No tienes tickets aquí',
              message: 'Desliza hacia abajo para actualizar.',
            ),
          ]);
        }
        return ListView.separated(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
          itemCount: tickets.length + (banner == null ? 0 : 1),
          separatorBuilder: (_, _) => const SizedBox(height: 12),
          itemBuilder: (context, i) {
            if (banner != null && i == 0) return banner;
            final t = tickets[i - (banner == null ? 0 : 1)];
            return TicketCard(
              ticket: t,
              index: i,
              onTap: () async {
                await Open.ticket(context, t.id);
                _loader.currentState?.reload();
              },
            );
          },
        );
      },
    );
  }
}
