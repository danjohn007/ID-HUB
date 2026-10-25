import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api.dart';
import '../../core/cache_store.dart';
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
      child: const Scaffold(
        body: Column(children: [
          PageHeader(eyebrow: 'Altas en campo', title: 'Mis instalaciones', bottom: HeaderTabs(tabs: ['Pendientes', 'Terminadas'])),
          Expanded(child: TabBarView(children: [_List(status: 'open'), _List(status: 'instalada,activada')])),
        ]),
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
  final _loader = GlobalKey<LoaderState<Cached<List<Installation>>>>();

  Future<Cached<List<Installation>>> _load() async {
    final res = await context.read<Session>().api.getCached('/installations', query: {'status': widget.status, 'per_page': 50}, cacheKey: CacheKeys.installations(widget.status));
    return Cached((res.data['data'] as List).map(Installation.new).toList(), fromCache: res.fromCache, savedAt: res.savedAt);
  }

  @override
  Widget build(BuildContext context) {
    return Loader<Cached<List<Installation>>>(
      key: _loader,
      load: _load,
      builder: (context, cached, reload) {
        final list = cached.data;
        final banner = cached.fromCache ? OfflineBanner(savedAt: cached.savedAt) : null;
        if (list.isEmpty) return ListView(padding: const EdgeInsets.all(16), children: [?banner, const EmptyState(icon: Icons.router_outlined, title: 'Sin instalaciones')]);
        return ListView.separated(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
          itemCount: list.length + (banner == null ? 0 : 1),
          separatorBuilder: (_, _) => const SizedBox(height: 10),
          itemBuilder: (context, i) {
            if (banner != null && i == 0) return banner;
            final o = list[i - (banner == null ? 0 : 1)];
            final total = o.checklist.length;
            final progress = total == 0 ? 0.0 : o.checklistDone / total;
            return AccentCard(
              accent: AppColors.semantic(o.status.color, brand: AppColors.teal),
              index: i,
              onTap: () async {
                await Open.installation(context, o.id);
                _loader.currentState?.reload();
              },
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  const IconBadge(Icons.router_rounded, color: AppColors.teal),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(o.folio, style: const TextStyle(color: AppColors.slate, fontSize: 12, fontWeight: FontWeight.w700)),
                      Text(o.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: AppColors.navy)),
                    ]),
                  ),
                  StatusChip.of(o.status, dense: true),
                ]),
                if (o.address.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  Row(children: [
                    const Icon(Icons.place_outlined, size: 15, color: AppColors.slate),
                    const SizedBox(width: 4),
                    Expanded(child: Text(o.address, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.slate, fontSize: 13))),
                  ]),
                ],
                const SizedBox(height: 10),
                Row(children: [
                  Pill(label: o.scheduledAt == null ? 'Sin fecha' : Fmt.dateTime(o.scheduledAt), color: AppColors.navy, icon: Icons.event_rounded),
                  const SizedBox(width: 12),
                  Expanded(
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(6),
                      child: LinearProgressIndicator(value: progress, minHeight: 6, color: AppColors.teal, backgroundColor: AppColors.border),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Text('${o.checklistDone}/$total', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: AppColors.slate)),
                ]),
              ]),
            );
          },
        );
      },
    );
  }
}
