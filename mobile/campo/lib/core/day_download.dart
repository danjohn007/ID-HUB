import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api.dart';
import 'cache_store.dart';
import 'session.dart';

/// "Descargar mi día": guarda en el teléfono lo necesario para trabajar sin señal. Técnico: ruta, detalle
/// de cada ticket e instalación, ficha de sus clientes, inventario y listas. Cobrador: ruta, pendientes,
/// ficha e historial de visitas de sus clientes y corte de caja. Corre sola al entrar con señal (y al
/// volver a la app si pasó más de media hora); también con el botón de la pantalla Más.
class DayDownload extends ChangeNotifier {
  DayDownload(this.session);

  static const _prefsKey = 'day-download:at';
  static const _autoEvery = Duration(minutes: 30);

  /// Clientes del cobrador que se descargan completos (primero los de la ruta).
  static const _maxCollectorClients = 60;

  final Session session;
  bool running = false;
  int done = 0;
  int total = 0;
  DateTime? lastAt;
  String? error;

  ApiClient get _api => session.api;

  Future<void> loadLast() async {
    final raw = (await SharedPreferences.getInstance()).getString(_prefsKey);
    lastAt = raw == null ? null : DateTime.tryParse(raw);
    notifyListeners();
  }

  Future<void> reset() async {
    lastAt = null;
    await (await SharedPreferences.getInstance()).remove(_prefsKey);
    notifyListeners();
  }

  /// [auto]: sólo si la última descarga tiene más de media hora; sin señal no muestra error.
  Future<bool> run({bool auto = false}) async {
    if (running || session.user == null) return false;
    if (auto && lastAt != null && DateTime.now().difference(lastAt!) < _autoEvery) return false;
    running = true;
    done = 0;
    total = 0;
    error = null;
    notifyListeners();
    try {
      final jobs = session.mode == AppMode.tecnico ? await _technician() : await _collector();
      total = jobs.length + 1;
      done = 1;
      notifyListeners();
      final missing = await _pool(jobs, 3);
      if (missing > 0) {
        // Se cortó la señal a medio camino: no cuenta como descarga (se reintenta al volver a la app).
        error = 'La señal se cortó: faltaron $missing de ${total - 1} datos. Vuelve a descargar cuando tengas señal.';
        return false;
      }
      lastAt = DateTime.now();
      await (await SharedPreferences.getInstance()).setString(_prefsKey, lastAt!.toIso8601String());
      return true;
    } on ApiException catch (e) {
      if (auto) return false;
      error = e.offline ? 'Sin conexión: conecta el teléfono a internet para descargar tu día.' : e.detail;
      return false;
    } finally {
      running = false;
      notifyListeners();
    }
  }

  /// La ruta se pide directo al servidor (no a la copia): sin señal se detiene aquí.
  Future<Map<String, dynamic>> _today(String type) async {
    final fresh = await _api.get('/field/today', query: {'type': type});
    final day = _api.pendingOverlay?.call(CacheKeys.today(type), fresh) ?? fresh;
    await CacheStore.write(CacheKeys.today(type), day);
    return day;
  }

  Future<List<Future<void> Function()>> _technician() async {
    final day = await _today('tecnico');
    final stops = _maps(_map(day['route'])['stops']);
    final tickets = {
      ..._maps(day['open_tickets']).map((t) => t['id']),
      ...stops.where((s) => s['type'] == 'ticket').map((s) => _map(s['subject'])['id']),
    }.whereType<int>();
    final installations = {
      ..._maps(day['installations']).map((o) => o['id']),
      ...stops.where((s) => s['type'] == 'installation_order').map((s) => _map(s['subject'])['id']),
    }.whereType<int>();
    final clients = {...stops.map((s) => _map(s['client'])['id']), ..._maps(day['open_tickets']).map((t) => _map(t['client'])['id'])}.whereType<int>();

    return [
      for (final id in tickets) () => _api.getCached('/tickets/$id', cacheKey: CacheKeys.ticket(id)),
      for (final id in installations) () => _api.getCached('/installations/$id', cacheKey: CacheKeys.installation(id)),
      if (session.can('clients.view'))
        for (final id in clients) () => _api.getCached('/clients/$id', cacheKey: CacheKeys.client(id)),
      if (session.can('tickets.view'))
        for (final id in clients) () => _api.getCached('/tickets', query: {'client_id': id, 'per_page': 5}, cacheKey: CacheKeys.clientTickets(id)),
      () => _api.getCached('/inventory', cacheKey: CacheKeys.inventory),
      if (session.can('clients.view')) () => _api.getCached('/clients', query: clientsQuery(''), cacheKey: CacheKeys.clients('')),
      if (session.can('tickets.view'))
        for (final status in ticketTabs) () => _api.getCached('/tickets', query: ticketsQuery(status), cacheKey: CacheKeys.tickets(status)),
      for (final status in ['open', 'instalada,activada'])
        () => _api.getCached('/installations', query: {'status': status, 'per_page': 50}, cacheKey: CacheKeys.installations(status)),
    ];
  }

  Future<List<Future<void> Function()>> _collector() async {
    final day = await _today('cobranza');
    final pending = (await _api.getCached('/collection/pending', query: {'per_page': 100}, cacheKey: CacheKeys.pending(''))).data;
    final clients = {
      ..._maps(_map(day['route'])['stops']).map((s) => _map(s['client'])['id']),
      ..._maps(pending['data']).map((c) => c['id']),
    }.whereType<int>().take(_maxCollectorClients);

    return [
      () => _api.getCached('/collection/pending', query: {'per_page': 5}, cacheKey: CacheKeys.pendingHome),
      () => _api.getCached('/collection/cash-cut', cacheKey: CacheKeys.cashCut),
      () => _api.getCached('/collection/cash-cuts', query: {'per_page': 20}, cacheKey: CacheKeys.cashCuts),
      for (final id in clients) () => _api.getCached('/clients/$id', cacheKey: CacheKeys.client(id)),
      for (final id in clients) () => _api.getCached('/collection/clients/$id/visits', cacheKey: CacheKeys.visits(id)),
    ];
  }

  /// Ejecuta las descargas de [width] en [width]; una que falle (404, permiso) no detiene las demás.
  /// Devuelve cuántas no se pudieron guardar por falta de señal (y sin copia previa).
  Future<int> _pool(List<Future<void> Function()> jobs, int width) async {
    var next = 0;
    var offline = 0;
    Future<void> worker() async {
      while (next < jobs.length) {
        final job = jobs[next++];
        try {
          await job();
        } on ApiException catch (e) {
          if (e.offline) offline++;
        } catch (_) {}
        done++;
        notifyListeners();
      }
    }

    await Future.wait(List.generate(width, (_) => worker()));
    return offline;
  }

  /// Pestañas de "Mis tickets" y su consulta (las comparte la pantalla de tickets).
  static const ticketTabs = ['abierto,asignado', 'en_proceso,en_espera', 'resuelto,cerrado'];

  static Map<String, dynamic> ticketsQuery(String status) => {
    'mine': 1,
    'status': status,
    'per_page': 50,
    'sort': status.startsWith('resuelto') ? '-updated_at' : 'sla_due_at',
  };

  /// Consulta de la lista de clientes (la comparte la pantalla de clientes).
  static Map<String, dynamic> clientsQuery(String search) => {'search': search.isEmpty ? null : search, 'per_page': 40, 'sort': 'first_name'};

  static Map<String, dynamic> _map(dynamic v) => v is Map<String, dynamic> ? v : const {};
  static Iterable<Map<String, dynamic>> _maps(dynamic v) => v is List ? v.whereType<Map<String, dynamic>>() : const [];
}
