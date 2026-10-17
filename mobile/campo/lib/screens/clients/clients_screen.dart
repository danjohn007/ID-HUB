import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/format.dart';
import '../../core/session.dart';
import '../../models.dart';
import '../../theme.dart';
import '../../widgets.dart';
import '../open.dart';

/// Búsqueda de clientes del WISP (nombre, ID, teléfono o usuario PPPoE).
class ClientsScreen extends StatefulWidget {
  const ClientsScreen({super.key});

  @override
  State<ClientsScreen> createState() => _ClientsScreenState();
}

class _ClientsScreenState extends State<ClientsScreen> {
  final _loader = GlobalKey<LoaderState<List<ClientSummary>>>();
  String _search = '';
  Timer? _debounce;

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  Future<List<ClientSummary>> _load() async {
    final res = await context.read<Session>().api.get('/clients', query: {'search': _search.isEmpty ? null : _search, 'per_page': 40, 'sort': 'first_name'});
    return (res['data'] as List).map(ClientSummary.new).toList();
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
          child: Loader<List<ClientSummary>>(
            key: _loader,
            load: _load,
            builder: (context, clients, reload) {
              if (clients.isEmpty) {
                return ListView(children: [
                  EmptyState(icon: Icons.person_search_outlined, title: 'Sin resultados', message: _search.isEmpty ? null : 'No hay clientes que coincidan con "$_search".'),
                ]);
              }
              return ListView.separated(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
                itemCount: clients.length,
                separatorBuilder: (_, _) => const SizedBox(height: 10),
                itemBuilder: (context, i) => _ClientCard(client: clients[i], index: i),
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
              Text('#${c.code}', style: const TextStyle(color: AppColors.slate, fontSize: 12, fontWeight: FontWeight.w700)),
              if (c.address.isNotEmpty) ...[
                const SizedBox(width: 6),
                const Icon(Icons.place_outlined, size: 13, color: AppColors.slate),
                const SizedBox(width: 2),
                Expanded(child: Text(c.address, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.slate, fontSize: 12.5))),
              ],
            ]),
          ]),
        ),
        const SizedBox(width: 8),
        Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          StatusChip.of(c.status, dense: true),
          if (c.balance > 0) ...[
            const SizedBox(height: 5),
            Text(Fmt.money(c.balance), style: const TextStyle(fontSize: 13, color: AppColors.danger, fontWeight: FontWeight.w800)),
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
