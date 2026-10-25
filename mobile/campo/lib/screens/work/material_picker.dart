import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/cache_store.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../models.dart';
import '../../theme.dart';
import '../../widgets.dart';

/// Material elegido: artículo, cantidad y número de serie (si aplica).
class MaterialPick {
  const MaterialPick(this.item, this.quantity, this.serial);

  final InventoryItem item;
  final double quantity;
  final String? serial;
}

/// Selector de material del inventario. Sin señal usa el inventario guardado en el teléfono
/// (con lo ya usado sin señal descontado).
Future<MaterialPick?> showMaterialPicker(BuildContext context) {
  return showModalBottomSheet<MaterialPick>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    builder: (_) => const FractionallySizedBox(heightFactor: .9, child: _Picker()),
  );
}

class _Picker extends StatefulWidget {
  const _Picker();

  @override
  State<_Picker> createState() => _PickerState();
}

class _PickerState extends State<_Picker> {
  String _search = '';
  InventoryItem? _item;
  double _qty = 1;
  final _serial = TextEditingController();

  @override
  void dispose() {
    _serial.dispose();
    super.dispose();
  }

  Future<List<InventoryItem>> _load() async {
    final res = await context.read<Session>().api.getCached('/inventory', cacheKey: CacheKeys.inventory);
    return (res.data['data'] as List).map(InventoryItem.new).where((i) => i.stock > 0).toList();
  }

  @override
  Widget build(BuildContext context) {
    if (_item != null) return _quantity(_item!);

    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
        child: TextField(
          autofocus: false,
          decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'Buscar material (nombre o SKU)'),
          onChanged: (v) => setState(() => _search = v.trim().toLowerCase()),
        ),
      ),
      Expanded(
        child: Loader<List<InventoryItem>>(
          load: _load,
          builder: (context, items, reload) {
            final list = items.where((i) => _search.isEmpty || i.name.toLowerCase().contains(_search) || i.sku.toLowerCase().contains(_search)).toList();
            if (list.isEmpty) return ListView(children: const [EmptyState(icon: Icons.inventory_2_outlined, title: 'Sin materiales con existencia')]);
            return ListView.separated(
              itemCount: list.length,
              separatorBuilder: (_, _) => const Divider(height: 1),
              itemBuilder: (_, i) {
                final it = list[i];
                return ListTile(
                  title: Text(it.name, style: const TextStyle(fontWeight: FontWeight.w600)),
                  subtitle: Text('${it.categoryLabel} · ${it.sku}'),
                  trailing: Text('${Fmt.qty(it.stock)} ${it.unit}', style: TextStyle(fontWeight: FontWeight.w700, color: AppColors.semantic(it.stockState))),
                  onTap: () => setState(() {
                    _item = it;
                    _qty = 1;
                  }),
                );
              },
            );
          },
        ),
      ),
    ]);
  }

  Widget _quantity(InventoryItem it) {
    final step = it.unit == 'm' ? 5.0 : 1.0;
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 0, 20, MediaQuery.viewInsetsOf(context).bottom + 20),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          IconButton(onPressed: () => setState(() => _item = null), icon: const Icon(Icons.arrow_back)),
          Expanded(child: Text(it.name, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800))),
        ]),
        Text('Existencia: ${Fmt.qty(it.stock)} ${it.unit}', style: const TextStyle(color: AppColors.slate)),
        const SizedBox(height: 24),
        Row(mainAxisAlignment: MainAxisAlignment.center, children: [
          IconButton.filledTonal(onPressed: _qty > step ? () => setState(() => _qty -= step) : null, icon: const Icon(Icons.remove), iconSize: 28),
          SizedBox(width: 120, child: Text('${Fmt.qty(_qty)} ${it.unit}', textAlign: TextAlign.center, style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800))),
          IconButton.filledTonal(onPressed: _qty + step <= it.stock ? () => setState(() => _qty += step) : null, icon: const Icon(Icons.add), iconSize: 28),
        ]),
        if (it.serialized) ...[
          const SizedBox(height: 20),
          TextField(controller: _serial, decoration: const InputDecoration(labelText: 'Número de serie / MAC', prefixIcon: Icon(Icons.qr_code_2_rounded))),
        ],
        const Spacer(),
        FilledButton(
          onPressed: () => Navigator.pop(context, MaterialPick(it, _qty, _serial.text.trim().isEmpty ? null : _serial.text.trim())),
          child: const Text('Registrar material'),
        ),
      ]),
    );
  }
}
