import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/api.dart';
import '../core/format.dart';
import '../core/session.dart';
import '../models.dart';
import '../theme.dart';
import '../widgets.dart';

/// 7. Inventario: existencias por categoría (antenas, CPE, routers, cable, conectores...).
class InventoryScreen extends StatefulWidget {
  const InventoryScreen({super.key});

  @override
  State<InventoryScreen> createState() => _InventoryScreenState();
}

class _InventoryScreenState extends State<InventoryScreen> {
  String? _category;
  String _search = '';

  static const _icons = {
    'antena': Icons.settings_input_antenna,
    'cpe': Icons.router_outlined,
    'router': Icons.wifi_rounded,
    'cable': Icons.cable_rounded,
    'conector': Icons.settings_ethernet_rounded,
    'fuente': Icons.power_outlined,
    'poe': Icons.electrical_services_rounded,
    'torre': Icons.cell_tower_rounded,
    'herraje': Icons.hardware_outlined,
    'refaccion': Icons.build_outlined,
  };

  Future<Cached<Map<String, dynamic>>> _load() => context.read<Session>().api.getCached('/inventory', cacheKey: 'inventory');

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Loader<Cached<Map<String, dynamic>>>(
        load: _load,
        builder: (context, cached, reload) {
          final items = (cached.data['data'] as List).map(InventoryItem.new).toList();
          final categories = (cached.data['categories'] as List).cast<Map>();
          final list = items.where((i) => (_category == null || i.category == _category) && (_search.isEmpty || i.name.toLowerCase().contains(_search))).toList();
          final low = items.where((i) => i.stockState == 'danger' || i.stockState == 'warning').length;

          return ListView(padding: EdgeInsets.zero, children: [
            PageHeader(
              eyebrow: 'Almacén',
              title: 'Inventario',
              bottom: Column(children: [
                Row(children: [
                  StatTile(value: '${items.length}', label: 'Artículos'),
                  const SizedBox(width: 10),
                  StatTile(value: '${categories.length}', label: 'Categorías'),
                  const SizedBox(width: 10),
                  StatTile(value: '$low', label: 'Por agotarse'),
                ]),
                const SizedBox(height: 12),
                HeaderSearch(hint: 'Buscar artículo', onChanged: (v) => setState(() => _search = v.trim().toLowerCase())),
              ]),
            ),
            Padding(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                if (cached.fromCache) OfflineBanner(savedAt: cached.savedAt),
                SizedBox(
                  height: 40,
                  child: ListView(scrollDirection: Axis.horizontal, children: [
                    Padding(padding: const EdgeInsets.only(right: 8), child: ChoiceChip(label: Text('Todo (${items.length})'), selected: _category == null, onSelected: (_) => setState(() => _category = null))),
                    for (final c in categories)
                      Padding(
                        padding: const EdgeInsets.only(right: 8),
                        child: ChoiceChip(
                          avatar: (c['low'] as num) > 0 ? const Icon(Icons.warning_amber_rounded, size: 16, color: AppColors.warning) : null,
                          label: Text('${c['label']}'),
                          selected: _category == c['value'],
                          onSelected: (_) => setState(() => _category = c['value'] as String),
                        ),
                      ),
                  ]),
                ),
                const SizedBox(height: 12),
                Card(
                  child: list.isEmpty
                      ? const EmptyState(icon: Icons.inventory_2_outlined, title: 'Sin artículos')
                      : Column(children: [
                          for (final (i, it) in list.indexed) ...[
                            if (i > 0) const Divider(height: 1, indent: 64),
                            ListTile(
                              leading: IconBadge(_icons[it.category] ?? Icons.inventory_2_outlined, color: const ['danger', 'warning'].contains(it.stockState) ? AppColors.semantic(it.stockState) : AppColors.navy, size: 40),
                              title: Text(it.name, style: const TextStyle(fontWeight: FontWeight.w600)),
                              subtitle: Text('${it.categoryLabel} · ${it.sku}'),
                              trailing: Text('${Fmt.qty(it.stock)} ${it.unit}', style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.semantic(it.stockState))),
                            ),
                          ],
                        ]),
                ),
                const SizedBox(height: 12),
                const Text('Los materiales se descuentan al registrarlos en un ticket o instalación.', textAlign: TextAlign.center, style: TextStyle(color: AppColors.slate, fontSize: 12)),
              ]),
            ),
          ]);
        },
      ),
    );
  }
}
