import 'cache_store.dart';
import 'outbox.dart';

/// Refleja en la copia local de los datos las acciones que están en la bandeja de salida, para que la
/// pantalla muestre lo que se hizo sin señal: llegada, estado, comentario, foto, firma, material,
/// checklist, mediciones, cobro o visita. Se aplica al guardar la acción ([apply]) y otra vez sobre los
/// datos que lleguen del servidor mientras siga pendiente ([overlay]); al enviarse, manda el servidor.
class LocalPatch {
  static Future<void> apply(OutboxItem item) async {
    final action = _Action.parse(item);
    if (action == null) return;
    final keys = {...action.keys, for (final prefix in action.listPrefixes) ...await CacheStore.keys(prefix)};
    for (final key in keys) {
      await CacheStore.update(key, (data) => action.patch(key, data));
    }
  }

  /// Respuesta recién llegada del servidor ([key] es su nombre en la caché) con lo pendiente aplicado.
  static Map<String, dynamic> overlay(String key, Map<String, dynamic> data, Iterable<OutboxItem> pending) {
    for (final item in pending) {
      _Action.parse(item)?.patch(key, data);
    }
    return data;
  }
}

typedef _Json = Map<String, dynamic>;

/// Catálogos del servidor (config/wisp.php) para mostrar el estado nuevo con su etiqueta y color.
const _ticketStatus = {
  'abierto': ('Abierto', 'info'),
  'asignado': ('Asignado', 'purple'),
  'en_proceso': ('En proceso', 'warning'),
  'en_espera': ('En espera', 'slate'),
  'resuelto': ('Resuelto', 'success'),
  'cerrado': ('Cerrado', 'teal'),
  'cancelado': ('Cancelado', 'slate'),
};
const _installationStatus = {'en_proceso': ('En proceso', 'warning'), 'instalada': ('Instalada', 'teal')};
const _visitResults = {
  'pago': ('Pago', 'success'),
  'no_localizado': ('No localizado', 'slate'),
  'promesa': ('Promesa de pago', 'info'),
  'rechazo': ('Rechazo', 'danger'),
  'cerrado': ('Domicilio cerrado', 'warning'),
};
const _evidenceKinds = {
  'antes': 'Foto antes',
  'durante': 'Foto durante',
  'despues': 'Foto después',
  'equipo': 'Foto del equipo',
  'instalacion': 'Foto de instalación',
  'otro': 'Otro',
};

_Json _catalog(Map<String, (String, String)> catalog, String value) => {'value': value, 'label': catalog[value]?.$1 ?? value, 'color': catalog[value]?.$2};

_Json? _obj(dynamic v) => v is Map<String, dynamic> ? v : null;
List<dynamic> _list(dynamic v) => v is List ? v : const [];
num _num(dynamic v) => v is num ? v : (num.tryParse('$v') ?? 0);
bool _truthy(dynamic v) => v == true || v == 1 || v == '1' || v == 'true';

/// Acción de la bandeja interpretada a partir de su método y ruta.
class _Action {
  _Action(this.item, this.kind, this.id, this.verb, [this.sub]);

  final OutboxItem item;

  /// ticket, installation, stop, payment o visit
  final String kind;
  final int id;
  final String verb;
  final int? sub;

  _Json get f => item.fields;
  String get at => f['occurred_at'] as String? ?? item.createdAt.toUtc().toIso8601String();

  static _Action? parse(OutboxItem item) {
    final p = item.path;
    RegExpMatch? m;
    if ((m = RegExp(r'^/field/stops/(\d+)/(arrive|depart)$').firstMatch(p)) != null) {
      return _Action(item, 'stop', int.parse(m![1]!), m[2]!);
    }
    if ((m = RegExp(r'^/field/tickets/(\d+)/(on-way|arrive|depart)$').firstMatch(p)) != null) {
      return _Action(item, 'ticket', int.parse(m![1]!), m[2]!);
    }
    if ((m = RegExp(r'^/(tickets|installations)/(\d+)/materials/(\d+)$').firstMatch(p)) != null && item.method == 'DELETE') {
      return _Action(item, m![1] == 'tickets' ? 'ticket' : 'installation', int.parse(m[2]!), 'materials-return', int.parse(m[3]!));
    }
    if ((m = RegExp(r'^/(tickets|installations)/(\d+)/(\w+)$').firstMatch(p)) != null) {
      return _Action(item, m![1] == 'tickets' ? 'ticket' : 'installation', int.parse(m[2]!), m[3]!);
    }
    final client = int.tryParse('${item.fields['client_id']}');
    if (client != null && p == '/collection/payments') return _Action(item, 'payment', client, 'payment');
    if (client != null && p == '/collection/visits') return _Action(item, 'visit', client, 'visit');
    return null;
  }

  /// Respuestas guardadas que esta acción puede cambiar.
  List<String> get keys => switch (kind) {
    'ticket' => [CacheKeys.ticket(id), CacheKeys.today('tecnico'), CacheKeys.inventory],
    'installation' => [CacheKeys.installation(id), CacheKeys.today('tecnico'), CacheKeys.inventory],
    'stop' => [CacheKeys.today('tecnico'), CacheKeys.today('cobranza'), if (item.meta['ticket_id'] is int) CacheKeys.ticket(item.meta['ticket_id'] as int)],
    _ => [CacheKeys.client(id), CacheKeys.visits(id), CacheKeys.pendingHome, CacheKeys.today('cobranza')],
  };

  List<String> get listPrefixes => switch (kind) {
    'ticket' => ['tickets-', 'client-tickets-'],
    'installation' => ['installations-'],
    'payment' || 'visit' => ['pending-'],
    _ => const [],
  };

  void patch(String key, _Json data) {
    switch (kind) {
      case 'ticket':
        if (key == CacheKeys.ticket(id)) {
          final t = _obj(data['data']);
          if (t != null) _ticket(t, verb);
        } else if (key.startsWith('today-')) {
          for (final t in _list(data['open_tickets']).whereType<_Json>().where((t) => t['id'] == id)) {
            _ticket(t, verb);
          }
          _subjectStop(data, 'ticket');
          if (verb == 'on-way') {
            for (final s in _stops(data).where((s) => s['type'] == 'ticket' && _obj(s['subject'])?['id'] == id)) {
              _obj(s['subject'])?['on_way_at'] = at;
            }
          }
        } else if (key.startsWith('tickets-') || key.startsWith('client-tickets-')) {
          for (final t in _list(data['data']).whereType<_Json>().where((t) => t['id'] == id)) {
            _ticket(t, verb);
          }
        } else if (key == CacheKeys.inventory) {
          _inventory(data);
        }
      case 'installation':
        if (key == CacheKeys.installation(id)) {
          final o = _obj(data['data']);
          if (o != null) _installation(o);
        } else if (key.startsWith('today-')) {
          for (final o in _list(data['installations']).whereType<_Json>().where((o) => o['id'] == id)) {
            _installation(o);
          }
          _subjectStop(data, 'installation_order');
        } else if (key.startsWith('installations-')) {
          for (final o in _list(data['data']).whereType<_Json>().where((o) => o['id'] == id)) {
            _installation(o);
          }
        } else if (key == CacheKeys.inventory) {
          _inventory(data);
        }
      case 'stop':
        if (key.startsWith('today-')) {
          for (final s in _stops(data).where((s) => s['id'] == id)) {
            _stop(data, s, verb);
          }
        } else if (item.meta['ticket_id'] is int && key == CacheKeys.ticket(item.meta['ticket_id'] as int)) {
          final t = _obj(data['data']);
          if (t != null) _ticket(t, verb);
        }
      case 'payment' || 'visit':
        _collection(key, data);
    }
  }

  // ---------------- Tickets ----------------

  void _ticket(_Json t, String verb) {
    switch (verb) {
      case 'on-way':
        t['on_way_at'] = at;
      case 'arrive':
        if (t['arrived_at'] == null || t['departed_at'] != null) t['arrived_at'] = at;
        t['departed_at'] = null;
        final status = _obj(t['status'])?['value'];
        if (['abierto', 'asignado', 'en_espera'].contains(status)) t['status'] = _catalog(_ticketStatus, 'en_proceso');
      case 'depart':
        t['departed_at'] = at;
      case 'status':
        final status = '${f['status']}';
        t['status'] = _catalog(_ticketStatus, status);
        if (status == 'en_proceso') t['arrived_at'] ??= at;
        if (status == 'resuelto') {
          t['resolution'] = f['note'] ?? t['resolution'];
          t['departed_at'] ??= at;
        }
      case 'comments':
        if (t['comments'] is List) {
          (t['comments'] as List).add({
            'type': 'comment',
            'body': f['body'],
            'internal': _truthy(f['internal']),
            'author': item.meta['author'] ?? 'Tú',
            'created_at': at,
            'pending': true,
          });
        }
      default:
        _work(t);
    }
  }

  // ---------------- Instalaciones ----------------

  void _installation(_Json o) {
    switch (verb) {
      case 'start':
        o['status'] = _catalog(_installationStatus, 'en_proceso');
        o['started_at'] ??= at;
      case 'checklist':
        _check(o, '${f['key']}', _truthy(f['done']));
      case 'measures':
        final measures = _obj(o['measures']) ?? <String, dynamic>{};
        for (final k in ['signal_dbm', 'speed_down', 'speed_up']) {
          if (f[k] != null) measures[k] = _num(f[k]);
        }
        o['measures'] = measures;
        if (f['signal_dbm'] != null) _check(o, 'senal', true);
        if (f['speed_down'] != null) _check(o, 'velocidad', true);
      case 'complete':
        o['status'] = _catalog(_installationStatus, 'instalada');
        o['can_complete'] = false;
      case 'attachments':
        _work(o);
        _check(o, 'fotografia', true);
      case 'signature':
        _work(o);
        _check(o, 'firma', true);
      default:
        _work(o);
    }
  }

  void _check(_Json o, String key, bool done) {
    for (final c in _list(o['checklist']).whereType<_Json>().where((c) => c['key'] == key)) {
      c['done'] = done;
    }
  }

  // ---------------- Evidencia, firma y materiales (tickets e instalaciones) ----------------

  void _work(_Json w) {
    switch (verb) {
      case 'attachments':
        if (w['attachments'] is! List) return;
        final lat = f['lat'], lng = f['lng'];
        (w['attachments'] as List).add({
          'id': -1,
          'kind': f['kind'] ?? 'otro',
          'kind_label': _evidenceKinds[f['kind']] ?? 'Foto',
          'url': '',
          'local_path': item.filePath,
          'is_image': true,
          'location': lat != null && lng != null ? {'lat': lat, 'lng': lng} : null,
          'taken_at': f['taken_at'] ?? at,
          'pending': true,
        });
      case 'signature':
        w['signature'] = {'url': '', 'local_path': item.filePath, 'signed_by': f['signed_by'], 'pending': true};
      case 'materials':
        if (w['materials'] is! List) return;
        (w['materials'] as List).add({
          'id': -(item.createdAt.millisecondsSinceEpoch % 1000000000),
          'quantity': _num(f['quantity']),
          'serial': f['serial'],
          'item': {'id': f['item_id'], 'name': item.meta['item_name'] ?? 'Material', 'unit': item.meta['unit'] ?? ''},
          'pending': true,
        });
      case 'materials-return':
        if (w['materials'] is List) (w['materials'] as List).removeWhere((m) => m is Map && m['id'] == sub);
    }
  }

  /// Existencia del almacén: lo usado sin señal se descuenta y lo devuelto se regresa.
  void _inventory(_Json data) {
    final (itemId, qty) = switch (verb) {
      'materials' => (f['item_id'], -_num(f['quantity'])),
      'materials-return' => (item.meta['item_id'], _num(item.meta['quantity'])),
      _ => (null, 0),
    };
    if (itemId == null) return;
    for (final i in _list(data['data']).whereType<_Json>().where((i) => '${i['id']}' == '$itemId')) {
      i['stock'] = _num(i['stock']) + qty;
    }
  }

  // ---------------- Ruta ----------------

  /// Avance que la acción deja en la parada de su ticket, instalación o cliente: llegada, salida o nada. Resolver
  /// el ticket, terminar la instalación o registrar el cobro/visita también termina la parada (igual que en el servidor).
  String? get _stopVerb => switch ((kind, verb)) {
        (_, 'arrive' || 'depart') => verb,
        ('payment' || 'visit', _) => 'depart',
        ('ticket', 'status') when ['resuelto', 'cerrado'].contains('${f['status']}') => 'depart',
        ('installation', 'start') => 'arrive',
        ('installation', 'complete') => 'depart',
        _ => null,
      };

  void _subjectStop(_Json day, String type) {
    final v = _stopVerb;
    if (v == null) return;
    for (final s in _stops(day).where((s) => s['type'] == type && _obj(s['subject'])?['id'] == id)) {
      _stop(day, s, v);
    }
  }

  Iterable<_Json> _stops(_Json day) => _list(_obj(day['route'])?['stops']).whereType<_Json>();

  void _stop(_Json day, _Json s, String verb) {
    if (verb == 'arrive' && !['arrived', 'done'].contains(s['status'])) {
      s['status'] = 'arrived';
      s['arrived_at'] = at;
    }
    if (verb == 'depart' && s['status'] != 'done') {
      s['status'] = 'done';
      s['departed_at'] = at;
      s['arrived_at'] ??= at;
      final summary = _obj(day['summary']);
      if (summary != null) summary['done'] = _num(summary['done']) + 1;
    }
  }

  // ---------------- Cobranza ----------------

  _Json get _visit => {
    'result': _catalog(_visitResults, kind == 'payment' ? 'pago' : '${f['result']}'),
    'visited_at': at,
    'promise_date': f['promise_date'],
    'promise_amount': f['promise_amount'],
    'notes': f['notes'],
    if (kind == 'payment') 'payment': {'folio': 'por subir', 'amount': _num(f['amount'])},
    'pending': true,
  };

  void _collection(String key, _Json data) {
    final paid = kind == 'payment' ? _num(f['amount']) : 0;
    if (key == CacheKeys.client(id)) {
      final c = _obj(data['data']);
      if (c != null) c['balance'] = _num(c['balance']) - paid;
    } else if (key == CacheKeys.visits(id)) {
      if (data['data'] is List) (data['data'] as List).insert(0, _visit);
    } else if (key.startsWith('today-')) {
      _subjectStop(data, 'client');
    } else if (key.startsWith('pending-')) {
      for (final c in _list(data['data']).whereType<_Json>().where((c) => c['id'] == id)) {
        c['balance'] = _num(c['balance']) - paid;
        c['last_visit'] = _visit;
        final summary = _obj(data['summary']);
        if (summary != null && paid > 0) summary['amount'] = _num(summary['amount']) - paid;
      }
    }
  }
}
