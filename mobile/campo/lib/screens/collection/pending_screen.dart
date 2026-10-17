import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../models.dart';
import '../../theme.dart';
import '../../widgets.dart';
import '../clients/clients_screen.dart';
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

  void _onSearch(String v) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 400), () {
      _search = v.trim();
      _loader.currentState?.reload();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Loader<Cached<Map<String, dynamic>>>(
        key: _loader,
        load: _load,
        builder: (context, cached, reload) {
          final list = (cached.data['data'] as List).map(PendingClient.new).toList();
          final summary = Map<String, dynamic>.from(cached.data['summary'] as Map? ?? {});
          return ListView(padding: EdgeInsets.zero, children: [
            PageHeader(
              eyebrow: 'Cobranza',
              title: 'Clientes pendientes',
              bottom: Column(children: [
                Row(children: [
                  StatTile(value: '${summary['clients'] ?? list.length}', label: 'Clientes'),
                  const SizedBox(width: 10),
                  StatTile(value: Fmt.money(summary['amount'] as num?), label: 'Monto a cobrar'),
                ]),
                const SizedBox(height: 12),
                HeaderSearch(hint: 'Buscar cliente', onChanged: _onSearch),
              ]),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                if (cached.fromCache) OfflineBanner(savedAt: cached.savedAt),
                if (list.isEmpty)
                  const SectionCard(child: EmptyState(icon: Icons.celebration_outlined, title: 'Sin pendientes', message: 'No hay clientes con saldo asignados a ti.'))
                else
                  for (final (i, c) in list.indexed)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: AccentCard(
                        accent: overdueColor(c.overdueDays),
                        index: i,
                        padding: const EdgeInsets.fromLTRB(12, 2, 12, 2),
                        onTap: () async {
                          await Open.collect(context, c.id);
                          _loader.currentState?.reload();
                        },
                        child: PendingTile(client: c, avatar: true),
                      ),
                    ),
              ]),
            ),
          ]);
        },
      ),
    );
  }
}

/// Color según el atraso: en tiempo, más de 30 días o más de 60.
Color overdueColor(int days) => days > 60 ? AppColors.danger : (days > 30 ? AppColors.warning : AppColors.brand600);

/// Renglón de cliente con saldo: monto, atraso, dirección y resultado de la última visita.
class PendingTile extends StatelessWidget {
  const PendingTile({super.key, required this.client, this.onTap, this.avatar = false});

  final PendingClient client;
  final VoidCallback? onTap;

  /// Con iniciales a la izquierda (lista completa).
  final bool avatar;

  @override
  Widget build(BuildContext context) {
    final c = client;
    final v = c.lastVisit;
    final row = Padding(
      padding: const EdgeInsets.symmetric(vertical: 12),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        if (avatar) ...[ClientAvatar(name: c.name, size: 42), const SizedBox(width: 12)],
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(c.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy)),
            Text(c.address.isEmpty ? '#${c.code}' : c.address, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.slate, fontSize: 13)),
            if (v != null)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Wrap(spacing: 6, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
                  StatusChip.of(v.result, dense: true),
                  Text(Fmt.date(v.visitedAt), style: const TextStyle(fontSize: 11, color: AppColors.slate)),
                  if (v.promiseDate != null) Text('promete ${Fmt.date(v.promiseDate)}', style: const TextStyle(fontSize: 11, color: AppColors.slate)),
                ]),
              ),
          ]),
        ),
        const SizedBox(width: 8),
        Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Text(Fmt.money(c.balance), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16, color: AppColors.danger, letterSpacing: -.3)),
          if (c.overdueDays > 0) ...[
            const SizedBox(height: 4),
            Pill(label: '${c.overdueDays} días', color: overdueColor(c.overdueDays), icon: Icons.schedule_rounded),
          ],
        ]),
      ]),
    );
    return onTap == null ? row : InkWell(onTap: onTap, borderRadius: BorderRadius.circular(12), child: row);
  }
}
