import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/format.dart';
import '../../core/session.dart';
import '../../models.dart';
import '../../theme.dart';
import '../../widgets.dart';
import '../open.dart';
import '../shell.dart';
import 'pending_screen.dart';

/// Inicio del cobrador: por cobrar, cobrado en el corte abierto y próximos clientes.
class CollectorHomeScreen extends StatefulWidget {
  const CollectorHomeScreen({super.key});

  @override
  State<CollectorHomeScreen> createState() => _CollectorHomeScreenState();
}

class _HomeData {
  _HomeData(this.pending, this.summary, this.cut);

  final List<PendingClient> pending;
  final Map<String, dynamic> summary;
  final Map<String, dynamic>? cut;
}

class _CollectorHomeScreenState extends State<CollectorHomeScreen> {
  final _loader = GlobalKey<LoaderState<_HomeData>>();

  Future<_HomeData> _load() async {
    final api = context.read<Session>().api;
    // El corte es opcional: si falla, el inicio se muestra igual.
    final cutFuture = api.get('/collection/cash-cut').then<Map<String, dynamic>?>((r) => r, onError: (_) => null);
    final pending = (await api.getCached('/collection/pending', query: {'per_page': 5}, cacheKey: 'pending-home')).data;
    final cut = await cutFuture;
    return _HomeData(
      (pending['data'] as List).map(PendingClient.new).toList(),
      Map<String, dynamic>.from(pending['summary'] as Map? ?? {}),
      cut?['cash_cut'] is Map ? Map<String, dynamic>.from(cut!['cash_cut'] as Map) : null,
    );
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();

    return Loader<_HomeData>(
      key: _loader,
      load: _load,
      builder: (context, d, reload) {
        final amount = (d.summary['amount'] as num?) ?? 0;
        final collected = (d.cut?['expected_amount'] as num?) ?? 0;

        return ListView(padding: EdgeInsets.zero, children: [
          GreetingHeader(
            name: session.user?.name ?? '',
            subtitle: '${session.tenant?.name ?? ''} · ${Fmt.longDate(DateTime.now())}',
            bottom: Row(children: [
              StatTile(value: '${d.summary['clients'] ?? 0}', label: 'Por visitar', onTap: () => Shell.of(context).go(2)),
              const SizedBox(width: 10),
              StatTile(value: Fmt.money(amount), label: 'Por cobrar', onTap: () => Shell.of(context).go(2)),
              const SizedBox(width: 10),
              StatTile(value: Fmt.money(collected), label: 'Cobrado', onTap: () => Shell.of(context).go(3)),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              if (d.cut != null)
                SectionCard(
                  child: Row(children: [
                    const CircleAvatar(backgroundColor: Color(0x1A16A34A), child: Icon(Icons.point_of_sale_rounded, color: AppColors.success)),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text('Corte ${d.cut!['folio']}', style: const TextStyle(fontWeight: FontWeight.w800)),
                        Text('${d.cut!['payments_count']} cobros · ${Fmt.money(collected)} en efectivo', style: const TextStyle(color: AppColors.slate, fontSize: 13)),
                      ]),
                    ),
                    TextButton(onPressed: () => Shell.of(context).go(3), child: const Text('Ver')),
                  ]),
                ),
              const SizedBox(height: 12),
              SectionCard(
                title: 'Próximos clientes',
                trailing: TextButton(onPressed: () => Shell.of(context).go(2), child: const Text('Ver todos')),
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
                child: d.pending.isEmpty
                    ? const EmptyState(icon: Icons.celebration_outlined, title: 'Sin clientes pendientes', message: 'No tienes saldos por cobrar asignados.')
                    : Column(children: [
                        for (final c in d.pending)
                          PendingTile(client: c, onTap: () async {
                            await Open.collect(context, c.id);
                            _loader.currentState?.reload();
                          }),
                      ]),
              ),
              const SizedBox(height: 16),
              OutlinedButton.icon(onPressed: () => Shell.of(context).go(ShellState.routeTab), icon: const Icon(Icons.route_outlined), label: const Text('Ver mi ruta de cobranza')),
              const SizedBox(height: 24),
            ]),
          ),
        ]);
      },
    );
  }
}
