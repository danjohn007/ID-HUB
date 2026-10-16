import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/format.dart';
import '../../core/session.dart';
import '../../models.dart';
import '../../theme.dart';
import '../../widgets.dart';
import '../open.dart';

/// Órdenes de instalación asignadas al técnico (pendientes o terminadas).
class InstallationsScreen extends StatelessWidget {
  const InstallationsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Mis instalaciones'),
          bottom: PreferredSize(
            preferredSize: const Size.fromHeight(48),
            child: Container(color: Colors.white, child: const TabBar(tabs: [Tab(text: 'Pendientes'), Tab(text: 'Terminadas')])),
          ),
        ),
        body: const TabBarView(children: [_List(status: 'open'), _List(status: 'instalada,activada')]),
      ),
    );
  }
}

class _List extends StatefulWidget {
  const _List({required this.status});

  final String status;

  @override
  State<_List> createState() => _ListState();
}

class _ListState extends State<_List> {
  final _loader = GlobalKey<LoaderState<List<Installation>>>();

  Future<List<Installation>> _load() async {
    final res = await context.read<Session>().api.get('/installations', query: {'status': widget.status, 'per_page': 50});
    return (res['data'] as List).map(Installation.new).toList();
  }

  @override
  Widget build(BuildContext context) {
    return Loader<List<Installation>>(
      key: _loader,
      load: _load,
      builder: (context, list, reload) {
        if (list.isEmpty) return ListView(children: const [EmptyState(icon: Icons.router_outlined, title: 'Sin instalaciones')]);
        return ListView.separated(
          padding: const EdgeInsets.all(16),
          itemCount: list.length,
          separatorBuilder: (_, _) => const SizedBox(height: 8),
          itemBuilder: (context, i) {
            final o = list[i];
            return Card(
              child: ListTile(
                contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
                leading: CircleAvatar(backgroundColor: AppColors.teal.withValues(alpha: .12), child: const Icon(Icons.router_outlined, color: AppColors.teal)),
                title: Text(o.name, style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text('${o.folio} · ${o.scheduledAt == null ? 'Sin fecha' : Fmt.dateTime(o.scheduledAt)}\n${o.address}', maxLines: 2, overflow: TextOverflow.ellipsis),
                isThreeLine: true,
                trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                  StatusChip.of(o.status, dense: true),
                  const SizedBox(height: 4),
                  Text('${o.checklistDone}/${o.checklist.length}', style: const TextStyle(fontSize: 12, color: AppColors.slate)),
                ]),
                onTap: () async {
                  await Open.installation(context, o.id);
                  _loader.currentState?.reload();
                },
              ),
            );
          },
        );
      },
    );
  }
}
