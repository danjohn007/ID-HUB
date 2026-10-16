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
        appBar: AppBar(
          title: const Text('Mis tickets'),
          bottom: PreferredSize(
            preferredSize: const Size.fromHeight(48),
            child: Container(
              color: Colors.white,
              child: TabBar(tabs: [for (final t in _tabs) Tab(text: t.$1)]),
            ),
          ),
        ),
        body: TabBarView(children: [for (final t in _tabs) _TicketList(status: t.$2, closed: t.$2.startsWith('resuelto'))]),
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
            EmptyState(icon: Icons.inbox_outlined, title: widget.closed ? 'Sin tickets cerrados' : 'No tienes tickets aquí', message: 'Desliza hacia abajo para actualizar.'),
          ]);
        }
        return ListView.separated(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          itemCount: tickets.length,
          separatorBuilder: (_, _) => const Divider(height: 1),
          itemBuilder: (context, i) => TicketTile(
            ticket: tickets[i],
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
