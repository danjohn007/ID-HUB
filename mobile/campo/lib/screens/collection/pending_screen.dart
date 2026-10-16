import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../models.dart';
import '../../theme.dart';
import '../../widgets.dart';
import '../open.dart';

/// Control de cobradores (sección 26): clientes pendientes, monto, dirección y última visita.
class PendingScreen extends StatefulWidget {
  const PendingScreen({super.key});

  @override
  State<PendingScreen> createState() => _PendingScreenState();
}

class _PendingScreenState extends State<PendingScreen> {
  final _loader = GlobalKey<LoaderState<Cached<Map<String, dynamic>>>>();
  String _search = '';
  Timer? _debounce;

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  Future<Cached<Map<String, dynamic>>> _load() =>
      context.read<Session>().api.getCached('/collection/pending', query: {'per_page': 100, 'search': _search.isEmpty ? null : _search}, cacheKey: 'pending-$_search');

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Clientes pendientes'),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(64),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
            child: TextField(
              decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'Buscar cliente', isDense: true),
              onChanged: (v) {
                _debounce?.cancel();
                _debounce = Timer(const Duration(milliseconds: 400), () {
                  _search = v.trim();
                  _loader.currentState?.reload();
                });
              },
            ),
          ),
        ),
      ),
      body: Loader<Cached<Map<String, dynamic>>>(
        key: _loader,
        load: _load,
        builder: (context, cached, reload) {
          final list = (cached.data['data'] as List).map(PendingClient.new).toList();
          final summary = Map<String, dynamic>.from(cached.data['summary'] as Map? ?? {});
          return ListView(padding: const EdgeInsets.all(16), children: [
            if (cached.fromCache) OfflineBanner(savedAt: cached.savedAt),
            Row(children: [
              Expanded(child: _Total(label: 'Clientes', value: '${summary['clients'] ?? list.length}')),
              const SizedBox(width: 10),
              Expanded(child: _Total(label: 'Monto a cobrar', value: Fmt.money(summary['amount'] as num?))),
            ]),
            const SizedBox(height: 12),
            if (list.isEmpty)
              const SectionCard(child: EmptyState(icon: Icons.celebration_outlined, title: 'Sin pendientes', message: 'No hay clientes con saldo asignados a ti.'))
            else
              Card(
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  child: Column(children: [
                    for (final (i, c) in list.indexed) ...[
                      if (i > 0) const Divider(height: 1),
                      PendingTile(client: c, onTap: () async {
                        await Open.collect(context, c.id);
                        _loader.currentState?.reload();
                      }),
                    ],
                  ]),
                ),
              ),
          ]);
        },
      ),
    );
  }
}

class _Total extends StatelessWidget {
  const _Total({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(label, style: const TextStyle(color: AppColors.slate, fontSize: 12)),
          FittedBox(child: Text(value, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: AppColors.navy))),
        ]),
      ),
    );
  }
}

/// Renglón de cliente con saldo: monto, atraso, dirección y resultado de la última visita.
class PendingTile extends StatelessWidget {
  const PendingTile({super.key, required this.client, required this.onTap});

  final PendingClient client;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = client;
    final v = c.lastVisit;
    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 12),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(c.name, style: const TextStyle(fontWeight: FontWeight.w800)),
              Text(c.address.isEmpty ? '#${c.code}' : c.address, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.slate, fontSize: 13)),
              if (v != null)
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Row(children: [
                    StatusChip.of(v.result, dense: true),
                    const SizedBox(width: 6),
                    Text(Fmt.date(v.visitedAt), style: const TextStyle(fontSize: 11, color: AppColors.slate)),
                    if (v.promiseDate != null) Text(' · promete ${Fmt.date(v.promiseDate)}', style: const TextStyle(fontSize: 11, color: AppColors.slate)),
                  ]),
                ),
            ]),
          ),
          const SizedBox(width: 8),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text(Fmt.money(c.balance), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16, color: AppColors.danger)),
            if (c.overdueDays > 0) Text('${c.overdueDays} días de atraso', style: const TextStyle(fontSize: 11, color: AppColors.slate)),
          ]),
        ]),
      ),
    );
  }
}
