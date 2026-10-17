import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../brand.dart';
import '../config.dart';
import '../core/geo.dart';
import '../core/session.dart';
import '../motion.dart';
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
      body: ListView(padding: EdgeInsets.zero, children: [
        PageHeader(
          eyebrow: 'Mi cuenta',
          title: 'Perfil',
          bottom: Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: .08),
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: Colors.white.withValues(alpha: .11)),
            ),
            child: Row(children: [
              Container(
                width: 60,
                height: 60,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: LinearGradient(colors: [brand, Color.lerp(brand, Colors.white, .25)!], begin: Alignment.topLeft, end: Alignment.bottomRight),
                  border: Border.all(color: Colors.white.withValues(alpha: .3), width: 2.5),
                  boxShadow: [BoxShadow(color: brand.withValues(alpha: .5), blurRadius: 18)],
                ),
                child: Text(user.name.isEmpty ? '?' : user.name[0].toUpperCase(), style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w800)),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(user.name, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: Colors.white)),
                  Text(user.email, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.navy200, fontSize: 13)),
                  const SizedBox(height: 6),
                  Wrap(spacing: 6, runSpacing: 4, children: [
                    Pill(label: user.roleLabel, color: AppColors.brand400, icon: tech ? Icons.engineering_outlined : Icons.payments_outlined),
                    if ((session.tenant?.name ?? '').isNotEmpty) Pill(label: session.tenant!.name, color: AppColors.navy200, icon: Icons.business_rounded),
                  ]),
                ]),
              ),
            ]),
          ),
        ),
        Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            _Group(index: 1, title: 'Mi trabajo', children: [
              _Item(icon: Icons.route_rounded, color: brand, label: 'Mi ruta', onTap: () => Shell.of(context).go(ShellState.routeTab)),
              if (tech) ...[
                _Item(icon: Icons.router_rounded, color: AppColors.teal, label: 'Mis instalaciones', onTap: () => _push(context, const InstallationsScreen())),
                if (session.can('inventory.view') || session.can('field.work'))
                  _Item(icon: Icons.inventory_2_rounded, color: AppColors.purple, label: 'Inventario', onTap: () => _push(context, const InventoryScreen())),
                if (session.isCollector) ...[
                  _Item(icon: Icons.payments_rounded, color: AppColors.warning, label: 'Cobranza pendiente', onTap: () => _push(context, const PendingScreen())),
                  _Item(icon: Icons.point_of_sale_rounded, color: AppColors.success, label: 'Mi corte de caja', onTap: () => _push(context, const CashCutScreen())),
                ],
              ],
              _Item(icon: Icons.my_location_rounded, color: AppColors.info, label: 'Compartir mi ubicación', onTap: () => _share(context)),
            ]),
            const SizedBox(height: 16),
            _Group(index: 2, title: 'App', children: [
              _Item(
                icon: Icons.dns_rounded,
                color: AppColors.slate,
                label: 'Servidor',
                trailing: Text(Uri.tryParse(session.server)?.host ?? '', style: const TextStyle(color: AppColors.slate, fontSize: 12)),
              ),
              const _Item(icon: Icons.info_rounded, color: AppColors.slate, label: 'Versión', trailing: Text('${AppConfig.appName} 1.0.0', style: TextStyle(color: AppColors.slate, fontSize: 12))),
            ]),
            const SizedBox(height: 16),
            FadeSlideIn(
              index: 3,
              child: OutlinedButton.icon(
                onPressed: () => _logout(context),
                style: OutlinedButton.styleFrom(foregroundColor: AppColors.danger, side: BorderSide(color: AppColors.danger.withValues(alpha: .35))),
                icon: const Icon(Icons.logout_rounded),
                label: const Text('Cerrar sesión'),
              ),
            ),
            const SizedBox(height: 28),
            const Opacity(opacity: .55, child: Center(child: IdHubMark(size: 34))),
            const SizedBox(height: 8),
            const Text('ID-Hub · Tu WISP bajo control', textAlign: TextAlign.center, style: TextStyle(color: AppColors.slate, fontSize: 12)),
            const SizedBox(height: 16),
          ]),
        ),
      ]),
    );
  }
}

/// Grupo de opciones con título pequeño.
class _Group extends StatelessWidget {
  const _Group({required this.title, required this.children, this.index = 0});

  final String title;
  final List<Widget> children;
  final int index;

  @override
  Widget build(BuildContext context) {
    return FadeSlideIn(
      index: index,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Padding(
          padding: const EdgeInsets.only(left: 4, bottom: 8),
          child: Text(title.toUpperCase(), style: const TextStyle(color: AppColors.slate, fontSize: 11.5, fontWeight: FontWeight.w800, letterSpacing: 1.4)),
        ),
        Card(
          clipBehavior: Clip.antiAlias,
          child: Column(children: [
            for (final (i, c) in children.indexed) ...[if (i > 0) const Divider(height: 1, indent: 68), c],
          ]),
        ),
      ]),
    );
  }
}

class _Item extends StatelessWidget {
  const _Item({required this.icon, required this.label, required this.color, this.onTap, this.trailing});

  final IconData icon;
  final String label;
  final Color color;
  final VoidCallback? onTap;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
      leading: IconBadge(icon, color: color, size: 40),
      title: Text(label, style: const TextStyle(fontWeight: FontWeight.w700, color: AppColors.navy)),
      trailing: trailing ?? (onTap != null ? const Icon(Icons.chevron_right_rounded, color: AppColors.slate) : null),
      onTap: onTap,
    );
  }
}
