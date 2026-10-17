import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/session.dart';
import '../../models.dart';
import '../../widgets.dart';
import '../open.dart';
import 'ticket_tile.dart';

/// 6. Mis tickets: Asignados · En progreso · Cerrados.
class TicketsScreen extends StatelessWidget {
  const TicketsScreen({super.key});

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
  final _loader = GlobalKey<LoaderState<List<Ticket>>>();

  @override
  bool get wantKeepAlive => true;

  Future<List<Ticket>> _load() async {
    final res = await context.read<Session>().api.get('/tickets', query: {
      'mine': 1,
      'status': widget.status,
      'per_page': 50,
      'sort': widget.closed ? '-updated_at' : 'sla_due_at',
    });
    return (res['data'] as List).map(Ticket.new).toList();
  }

  @override
  Widget build(BuildContext context) {
    super.build(context);
    return Loader<List<Ticket>>(
      key: _loader,
      load: _load,
      builder: (context, tickets, reload) {
        if (tickets.isEmpty) {
          return ListView(children: [
            EmptyState(
              icon: widget.closed ? Icons.task_alt_rounded : Icons.inbox_outlined,
              title: widget.closed ? 'Sin tickets cerrados' : 'No tienes tickets aquí',
              message: 'Desliza hacia abajo para actualizar.',
            ),
          ]);
        }
        return ListView.separated(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
          itemCount: tickets.length,
          separatorBuilder: (_, _) => const SizedBox(height: 12),
          itemBuilder: (context, i) => TicketCard(
            ticket: tickets[i],
            index: i,
            onTap: () async {
              await Open.ticket(context, tickets[i].id);
              _loader.currentState?.reload();
            },
          ),
        );
      },
    );
  }
}
