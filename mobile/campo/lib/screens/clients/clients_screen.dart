import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api.dart';
import '../../core/cache_store.dart';
import '../../core/day_download.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../models.dart';
import '../../theme.dart';
import '../../widgets.dart';
import '../open.dart';

/// Búsqueda de clientes del WISP (nombre, ID, teléfono o usuario PPPoE). Sin señal busca entre los
/// clientes guardados en el teléfono (lista inicial y fichas descargadas).
class ClientsScreen extends StatefulWidget {
  const ClientsScreen({super.key});

  @override
  State<ClientsScreen> createState() => _ClientsScreenState();
}

class _ClientsScreenState extends State<ClientsScreen> {
  final _loader = GlobalKey<LoaderState<Cached<List<ClientSummary>>>>();
  String _search = '';
  Timer? _debounce;

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  Future<Cached<List<ClientSummary>>> _load() async {
    final api = context.read<Session>().api;
    try {
      final res = await api.getCached('/clients', query: DayDownload.clientsQuery(_search), cacheKey: CacheKeys.clients(_search));
      return Cached((res.data['data'] as List).map(ClientSummary.new).toList(), fromCache: res.fromCache, savedAt: res.savedAt);
    } on ApiException catch (e) {
      if (!e.offline || _search.isEmpty) rethrow;
      return Cached(await _searchSaved(_search.toLowerCase()), fromCache: true);
    }
  }

  /// Búsqueda sin señal: lista inicial guardada más las fichas de clientes descargadas.
  static Future<List<ClientSummary>> _searchSaved(String q) async {
    final found = <int, ClientSummary>{};
    final base = await CacheStore.read(CacheKeys.clients(''));
    for (final c in (base?.data['data'] as List? ?? const []).map(ClientSummary.new)) {
      found[c.id] = c;
    }
    for (final key in (await CacheStore.keys('client-')).where((k) => RegExp(r'^client-\d+$').hasMatch(k))) {
      final saved = await CacheStore.read(key);
      if (saved?.data['data'] is Map) {
        final c = ClientSummary(saved!.data['data']);
        found[c.id] = c;
      }
    }
    bool match(ClientSummary c) => [c.name, c.code, c.phone ?? '', c.address, '${c.json['pppoe_user'] ?? ''}'].any((v) => v.toLowerCase().contains(q));
    return found.values.where(match).toList()..sort((a, b) => a.name.compareTo(b.name));
  }

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
      body: Column(children: [
        PageHeader(
          eyebrow: 'Cartera',
          title: 'Clientes',
          bottom: HeaderSearch(hint: 'Nombre, ID, teléfono o PPPoE', onChanged: _onSearch),
        ),
        Expanded(
          child: Loader<Cached<List<ClientSummary>>>(
            key: _loader,
            load: _load,
            builder: (context, cached, reload) {
              final clients = cached.data;
              final banner = cached.fromCache ? OfflineBanner(savedAt: cached.savedAt) : null;
              if (clients.isEmpty) {
                return ListView(padding: const EdgeInsets.all(16), children: [
                  ?banner,
                  EmptyState(icon: Icons.person_search_outlined, title: 'Sin resultados', message: _search.isEmpty ? null : 'No hay clientes que coincidan con "$_search".'),
                ]);
              }
              return ListView.separated(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
                itemCount: clients.length + (banner == null ? 0 : 1),
                separatorBuilder: (_, _) => const SizedBox(height: 10),
                itemBuilder: (context, i) => banner != null && i == 0 ? banner : _ClientCard(client: clients[i - (banner == null ? 0 : 1)], index: i),
              );
            },
          ),
        ),
      ]),
    );
  }
}

class _ClientCard extends StatelessWidget {
  const _ClientCard({required this.client, required this.index});

  final ClientSummary client;
  final int index;

  @override
  Widget build(BuildContext context) {
    final c = client;
    final status = AppColors.semantic(c.status.color, brand: Theme.of(context).colorScheme.primary);

    return AccentCard(
      accent: status,
      index: index,
      onTap: () => Open.client(context, c.id),
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 12),
      child: Row(children: [
        ClientAvatar(name: c.name),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(c.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: AppColors.navy)),
            const SizedBox(height: 2),
            Row(children: [
              Text('#${c.code}', style: const TextStyle(color: AppColors.slate, fontSize: 13, fontWeight: FontWeight.w700)),
              if (c.address.isNotEmpty) ...[
                const SizedBox(width: 6),
                const Icon(Icons.place_outlined, size: 13, color: AppColors.slate),
                const SizedBox(width: 2),
                Expanded(child: Text(c.address, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.slate, fontSize: 13.5))),
              ],
            ]),
          ]),
        ),
        const SizedBox(width: 8),
        Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          StatusChip.of(c.status, dense: true),
          if (c.balance > 0) ...[
            const SizedBox(height: 5),
            Text(Fmt.money(c.balance), style: const TextStyle(fontSize: 14, color: AppColors.danger, fontWeight: FontWeight.w800)),
          ],
        ]),
      ]),
    );
  }
}

/// Iniciales del cliente en un círculo con degradado de marca.
class ClientAvatar extends StatelessWidget {
  const ClientAvatar({super.key, required this.name, this.size = 46});

  final String name;
  final double size;

  @override
  Widget build(BuildContext context) {
    final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
    final initials = parts.take(2).map((p) => p[0].toUpperCase()).join();
    final brand = Theme.of(context).colorScheme.primary;
    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: LinearGradient(colors: [brand, Color.lerp(brand, AppColors.navy, .35)!], begin: Alignment.topLeft, end: Alignment.bottomRight),
        boxShadow: [BoxShadow(color: brand.withValues(alpha: .3), blurRadius: 10, offset: const Offset(0, 4))],
      ),
      child: Text(initials, style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: size * .34)),
    );
  }
}
