import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../config.dart';
import '../core/geo.dart';
import '../core/session.dart';
import '../theme.dart';
import '../widgets.dart';
import 'collection/cash_cut_screen.dart';
import 'collection/pending_screen.dart';
import 'installations/installations_screen.dart';
import 'inventory_screen.dart';
import 'shell.dart';

/// 8. Perfil / Más: datos del usuario, accesos, ubicación y cerrar sesión.
class MoreScreen extends StatelessWidget {
  const MoreScreen({super.key});

  void _push(BuildContext context, Widget page) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => page));

  Future<void> _logout(BuildContext context) async {
    final session = context.read<Session>();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Cerrar sesión'),
        content: const Text('Se borrará la información guardada en este teléfono.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancelar')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), style: FilledButton.styleFrom(backgroundColor: AppColors.danger), child: const Text('Cerrar sesión')),
        ],
      ),
    );
    if (ok == true) await session.logout();
  }

  Future<void> _share(BuildContext context) async {
    final session = context.read<Session>();
    final gps = await Geo.current();
    if (!context.mounted) return;
    if (gps == null) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(Geo.lastError ?? 'Sin GPS'), backgroundColor: AppColors.warning));
      return;
    }
    await runAction(context, () => session.api.post('/field/location', data: gps.toJson()), success: 'Ubicación compartida con la oficina');
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final user = session.user!;
    final brand = Theme.of(context).colorScheme.primary;
    final tech = session.mode == AppMode.tecnico;

    return Scaffold(
      appBar: AppBar(title: const Text('Mi perfil')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        SectionCard(
          child: Row(children: [
            CircleAvatar(radius: 28, backgroundColor: brand, child: Text(user.name.isEmpty ? '?' : user.name[0].toUpperCase(), style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800))),
            const SizedBox(width: 14),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(user.name, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: AppColors.navy)),
                Text(user.email, style: const TextStyle(color: AppColors.slate)),
                const SizedBox(height: 4),
                Text('${user.roleLabel} · ${session.tenant?.name ?? ''}', style: TextStyle(color: brand, fontWeight: FontWeight.w600, fontSize: 13)),
              ]),
            ),
          ]),
        ),
        const SizedBox(height: 12),
        Card(
          child: Column(children: [
            _Item(icon: Icons.route_outlined, label: 'Mi ruta', onTap: () => Shell.of(context).go(ShellState.routeTab)),
            if (tech) ...[
              _Item(icon: Icons.router_outlined, label: 'Mis instalaciones', onTap: () => _push(context, const InstallationsScreen())),
              if (session.can('inventory.view') || session.can('field.work')) _Item(icon: Icons.inventory_2_outlined, label: 'Inventario', onTap: () => _push(context, const InventoryScreen())),
              if (session.isCollector) ...[
                _Item(icon: Icons.payments_outlined, label: 'Cobranza pendiente', onTap: () => _push(context, const PendingScreen())),
                _Item(icon: Icons.point_of_sale_outlined, label: 'Mi corte de caja', onTap: () => _push(context, const CashCutScreen())),
              ],
            ],
            _Item(icon: Icons.my_location_rounded, label: 'Compartir mi ubicación', onTap: () => _share(context)),
          ]),
        ),
        const SizedBox(height: 12),
        Card(
          child: Column(children: [
            _Item(icon: Icons.dns_outlined, label: 'Servidor', trailing: Text(Uri.tryParse(session.server)?.host ?? '', style: const TextStyle(color: AppColors.slate, fontSize: 12))),
            const _Item(icon: Icons.info_outline, label: 'Versión', trailing: Text('${AppConfig.appName} 1.0.0', style: TextStyle(color: AppColors.slate, fontSize: 12))),
            _Item(icon: Icons.logout_rounded, label: 'Cerrar sesión', color: AppColors.danger, onTap: () => _logout(context)),
          ]),
        ),
      ]),
    );
  }
}

class _Item extends StatelessWidget {
  const _Item({required this.icon, required this.label, this.onTap, this.trailing, this.color});

  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  final Widget? trailing;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      leading: Icon(icon, color: color ?? AppColors.navy),
      title: Text(label, style: TextStyle(fontWeight: FontWeight.w600, color: color)),
      trailing: trailing ?? (onTap != null && color == null ? const Icon(Icons.chevron_right_rounded) : null),
      onTap: onTap,
    );
  }
}
