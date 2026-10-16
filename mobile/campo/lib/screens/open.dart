import 'package:flutter/material.dart';

import '../models.dart';
import 'clients/client_screen.dart';
import 'collection/collect_screen.dart';
import 'installations/installation_screen.dart';
import 'tickets/ticket_screen.dart';

/// Navegación a los detalles. Devuelve cuando la pantalla se cierra (para recargar la lista).
class Open {
  static Future<void> ticket(BuildContext context, int id) =>
      Navigator.of(context).push(MaterialPageRoute(builder: (_) => TicketScreen(id: id)));

  static Future<void> installation(BuildContext context, int id) =>
      Navigator.of(context).push(MaterialPageRoute(builder: (_) => InstallationScreen(id: id)));

  static Future<void> client(BuildContext context, int id) =>
      Navigator.of(context).push(MaterialPageRoute(builder: (_) => ClientScreen(id: id)));

  static Future<void> collect(BuildContext context, int clientId) =>
      Navigator.of(context).push(MaterialPageRoute(builder: (_) => CollectScreen(clientId: clientId)));

  /// Detalle de una parada de la ruta según su tipo.
  static Future<void> stop(BuildContext context, RouteStop stop, {required bool collector}) {
    final id = stop.subject['id'] as int?;
    return switch (stop.type) {
      'ticket' when id != null => ticket(context, id),
      'installation_order' when id != null => installation(context, id),
      _ when stop.client != null => collector ? collect(context, stop.client!.id) : client(context, stop.client!.id),
      _ => Future.value(),
    };
  }
}
