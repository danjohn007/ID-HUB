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
      appBar: AppBar(
        title: const Text('Clientes'),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(64),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
            child: TextField(
              onChanged: _onSearch,
              textInputAction: TextInputAction.search,
              decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'Nombre, ID, teléfono o PPPoE', isDense: true),
            ),
          ),
        ),
      ),
      body: Loader<List<ClientSummary>>(
        key: _loader,
        load: _load,
        builder: (context, clients, reload) {
          if (clients.isEmpty) return ListView(children: const [EmptyState(icon: Icons.person_search_outlined, title: 'Sin resultados')]);
          return ListView.separated(
            padding: const EdgeInsets.symmetric(vertical: 8),
            itemCount: clients.length,
            separatorBuilder: (_, _) => const Divider(height: 1, indent: 72),
            itemBuilder: (context, i) {
              final c = clients[i];
              return ListTile(
                leading: _Avatar(name: c.name),
                title: Text(c.name, style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text('#${c.code} · ${c.address}', maxLines: 1, overflow: TextOverflow.ellipsis),
                trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                  StatusChip.of(c.status, dense: true),
                  if (c.balance > 0) Text(Fmt.money(c.balance), style: const TextStyle(fontSize: 12, color: AppColors.danger, fontWeight: FontWeight.w700)),
                ]),
                onTap: () => Open.client(context, c.id),
              );
            },
          );
        },
      ),
    );
  }
}

class _Avatar extends StatelessWidget {
  const _Avatar({required this.name});

  final String name;

  @override
  Widget build(BuildContext context) {
    final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
    final initials = parts.take(2).map((p) => p[0].toUpperCase()).join();
    final brand = Theme.of(context).colorScheme.primary;
    return CircleAvatar(backgroundColor: brand.withValues(alpha: .12), child: Text(initials, style: TextStyle(color: brand, fontWeight: FontWeight.w800)));
  }
}
