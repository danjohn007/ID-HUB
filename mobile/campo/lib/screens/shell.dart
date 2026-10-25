import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../core/push.dart';
import '../core/session.dart';
import '../motion.dart';
import '../theme.dart';
import 'clients/clients_screen.dart';
import 'collection/cash_cut_screen.dart';
import 'collection/collector_home.dart';
import 'collection/pending_screen.dart';
import 'more_screen.dart';
import 'open.dart';
import 'outbox_bar.dart';
import 'route_screen.dart';
import 'tech/home_screen.dart';
import 'tickets/tickets_screen.dart';

class _Tab {
  const _Tab(this.label, this.icon, this.selectedIcon, this.page);

  final String label;
  final IconData icon;
  final IconData selectedIcon;
  final Widget page;
}

/// Barra inferior según el rol: técnico (Inicio · Ruta · Clientes · Tickets · Más)
/// o cobrador (Inicio · Ruta · Cobranza · Corte · Más).
class Shell extends StatefulWidget {
  const Shell({super.key});

  static ShellState of(BuildContext context) => context.findAncestorStateOfType<ShellState>()!;

  @override
  State<Shell> createState() => ShellState();
}

class ShellState extends State<Shell> with SingleTickerProviderStateMixin, WidgetsBindingObserver {
  int _index = 0;

  /// Pestañas ya abiertas: las demás no se construyen (ni piden datos) hasta visitarlas.
  final _visited = <int>{0};

  /// Fundido al cambiar de pestaña.
  late final _fade = AnimationController(vsync: this, duration: const Duration(milliseconds: 360), value: 1);

  /// Reintento periódico de la bandeja de salida (acciones guardadas sin conexión).
  Timer? _outboxTimer;

  @override
  void initState() {
    super.initState();
    Push.attach(_openPush);
    WidgetsBinding.instance.addObserver(this);
    _outboxTimer = Timer.periodic(const Duration(minutes: 1), (_) {
      if (mounted) context.read<Session>().outbox.flush();
    });
  }

  @override
  void dispose() {
    Push.detach(_openPush);
    WidgetsBinding.instance.removeObserver(this);
    _outboxTimer?.cancel();
    _fade.dispose();
    super.dispose();
  }

  /// Al volver a la app se envía lo pendiente y, si pasó más de media hora, se vuelve a descargar el día.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed || !mounted) return;
    final session = context.read<Session>();
    session.outbox.flush(force: true).then((_) => session.download.run(auto: true));
  }

  /// Notificación tocada: abre el ticket, la instalación o la ruta que menciona.
  void _openPush(Map<String, dynamic> data) {
    if (!mounted) return;
    final id = int.tryParse('${data['id'] ?? ''}');
    switch (data['type']) {
      case 'ticket' when id != null:
        Open.ticket(context, id);
      case 'installation_order' when id != null:
        Open.installation(context, id);
      case 'route':
        go(routeTab);
    }
  }

  /// Cambia de pestaña desde cualquier pantalla (p. ej. "Ver ruta" en Inicio).
  void go(int index) {
    if (index == _index) return;
    HapticFeedback.selectionClick();
    setState(() {
      _index = index;
      _visited.add(index);
    });
    if (!reduceMotion(context)) _fade.forward(from: 0);
  }

  static const routeTab = 1;

  List<_Tab> _tabs(AppMode mode) => mode == AppMode.tecnico
      ? const [
          _Tab('Inicio', Icons.home_outlined, Icons.home_rounded, TechHomeScreen()),
          _Tab('Ruta', Icons.route_outlined, Icons.route_rounded, RouteScreen(type: 'tecnico')),
          _Tab('Clientes', Icons.people_outline, Icons.people_rounded, ClientsScreen()),
          _Tab('Tickets', Icons.confirmation_number_outlined, Icons.confirmation_number_rounded, TicketsScreen()),
          _Tab('Más', Icons.grid_view_outlined, Icons.grid_view_rounded, MoreScreen()),
        ]
      : const [
          _Tab('Inicio', Icons.home_outlined, Icons.home_rounded, CollectorHomeScreen()),
          _Tab('Ruta', Icons.route_outlined, Icons.route_rounded, RouteScreen(type: 'cobranza')),
          _Tab('Cobranza', Icons.payments_outlined, Icons.payments_rounded, PendingScreen()),
          _Tab('Corte', Icons.point_of_sale_outlined, Icons.point_of_sale_rounded, CashCutScreen()),
          _Tab('Más', Icons.grid_view_outlined, Icons.grid_view_rounded, MoreScreen()),
        ];

  @override
  Widget build(BuildContext context) {
    final tabs = _tabs(context.select<Session, AppMode>((s) => s.mode));

    return Scaffold(
      body: AnimatedBuilder(
        animation: _fade,
        builder: (_, child) {
          final v = Motion.curve.transform(_fade.value);
          return Opacity(
            opacity: .35 + .65 * v,
            child: Transform.translate(offset: Offset(0, 14 * (1 - v)), child: child),
          );
        },
        child: IndexedStack(index: _index, children: [for (final (i, t) in tabs.indexed) _visited.contains(i) ? t.page : const SizedBox.shrink()]),
      ),
      bottomNavigationBar: Column(mainAxisSize: MainAxisSize.min, children: [
        OutboxBar(outbox: context.read<Session>().outbox),
        NavigationBar(
          selectedIndex: _index,
          onDestinationSelected: go,
          height: 66,
          destinations: [for (final t in tabs) NavigationDestination(icon: Icon(t.icon), selectedIcon: Icon(t.selectedIcon), label: t.label)],
        ),
      ]),
    );
  }
}
