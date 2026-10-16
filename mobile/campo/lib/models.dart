/// Modelos de la API v1. Cada uno conserva el JSON original y expone getters tipados.
library;

typedef Json = Map<String, dynamic>;

Json _map(dynamic v) => v is Map ? Map<String, dynamic>.from(v) : <String, dynamic>{};
List<Json> _list(dynamic v) => v is List ? v.map(_map).toList() : <Json>[];
double? _num(dynamic v) => v is num ? v.toDouble() : (v is String ? double.tryParse(v) : null);

/// Valor de catálogo: {"value": "activo", "label": "Activo", "color": "success"}.
class Catalog {
  Catalog(dynamic json) : json = _map(json);
  final Json json;

  String get value => json['value'] as String? ?? '';
  String get label => json['label'] as String? ?? value;
  String? get color => json['color'] as String?;
}

class LatLngJson {
  const LatLngJson(this.lat, this.lng);
  final double lat;
  final double lng;

  static LatLngJson? from(dynamic v) {
    final m = _map(v);
    final lat = _num(m['lat']);
    final lng = _num(m['lng']);
    return lat == null || lng == null ? null : LatLngJson(lat, lng);
  }
}

class ClientSummary {
  ClientSummary(dynamic json) : json = _map(json);
  final Json json;

  int get id => json['id'] as int;
  String get code => '${json['code'] ?? ''}';
  String get name => json['full_name'] as String? ?? '';
  String? get phone => json['phone'] as String?;
  String get address => json['address'] as String? ?? '';
  LatLngJson? get location => LatLngJson.from(json['location']);
  Catalog get status => Catalog(json['service_status']);
  double get balance => _num(json['balance']) ?? 0;
}

/// Cliente completo (GET /clients/{id}).
class ClientDetail extends ClientSummary {
  ClientDetail(super.json);

  String? get whatsapp => json['whatsapp'] as String? ?? phone;
  String? get email => json['email'] as String?;
  String? get references => json['references'] as String?;
  String get fullAddress => [json['address'], json['colonia'], json['community']].where((e) => e != null && '$e'.isNotEmpty).join(', ');
  String? get navigationUrl => json['navigation_url'] as String?;
  String? get planName => _map(json['plan'])['name'] as String?;
  String? get planSpeed {
    final p = _map(json['plan']);
    final d = _num(p['download_mbps']);
    return d == null ? null : '${d.toStringAsFixed(0)} Mbps';
  }

  String? get zone => _map(json['zone'])['name'] as String?;
  String? get repeater => _map(json['repeater'])['code'] as String?;
  bool get online => json['online'] == true;
  double? get signal => _num(json['signal_dbm']);
  String? get ip => json['ip_address'] as String?;
  String? get mac => json['mac_address'] as String?;
  String? get pppoe => json['pppoe_user'] as String?;
  String? get installationDate => json['installation_date'] as String?;
  String? get nextDueDate => json['next_due_date'] as String?;
  String? get notes => json['notes'] as String?;
}

class Attachment {
  Attachment(dynamic json) : json = _map(json);
  final Json json;

  int get id => json['id'] as int;
  String get kind => json['kind'] as String? ?? 'otro';
  String get kindLabel => json['kind_label'] as String? ?? kind;
  String get url => json['url'] as String? ?? '';
  bool get isImage => json['is_image'] == true;
  String? get takenAt => json['taken_at'] as String?;
  bool get hasGps => json['location'] != null;
}

class UsedMaterial {
  UsedMaterial(dynamic json) : json = _map(json);
  final Json json;

  int get id => json['id'] as int;
  double get quantity => _num(json['quantity']) ?? 0;
  String get itemName => _map(json['item'])['name'] as String? ?? 'Artículo';
  String get unit => _map(json['item'])['unit'] as String? ?? '';
  String? get serial => json['serial'] as String?;
  String get typeLabel => Catalog(json['type']).label;
}

class Comment {
  Comment(dynamic json) : json = _map(json);
  final Json json;

  String get type => json['type'] as String? ?? 'comment';
  String get body => json['body'] as String? ?? '';
  String get author => json['author'] as String? ?? 'Sistema';
  bool get internal => json['internal'] == true;
  String? get createdAt => json['created_at'] as String?;
}

class Ticket {
  Ticket(dynamic json) : json = _map(json);
  final Json json;

  int get id => json['id'] as int;
  String get folio => json['folio'] as String? ?? '#$id';
  String get subject => json['subject'] as String? ?? '';
  String? get description => json['description'] as String?;
  String get categoryLabel => Catalog(json['category']).label;
  Catalog get priority => Catalog(json['priority']);
  Catalog get status => Catalog(json['status']);
  ClientSummary? get client => json['client'] is Map ? ClientSummary(json['client']) : null;
  String? get slaDueAt => _map(json['sla'])['due_at'] as String?;
  String? get slaState => _map(json['sla'])['state'] as String?;
  bool get slaBreached => _map(json['sla'])['breached'] == true;
  String? get onWayAt => json['on_way_at'] as String?;
  String? get arrivedAt => json['arrived_at'] as String?;
  String? get departedAt => json['departed_at'] as String?;
  String? get createdAt => json['created_at'] as String?;
  String? get resolution => json['resolution'] as String?;
  List<Comment> get comments => _list(json['comments']).map(Comment.new).toList();
  List<Attachment> get attachments => _list(json['attachments']).map(Attachment.new).toList();
  List<UsedMaterial> get materials => _list(json['materials']).map(UsedMaterial.new).toList();
  Json? get signature => json['signature'] is Map ? _map(json['signature']) : null;

  bool get isOpen => !['resuelto', 'cerrado', 'cancelado'].contains(status.value);
  bool get onSite => arrivedAt != null && departedAt == null;
}

class ChecklistItem {
  ChecklistItem(dynamic json) : json = _map(json);
  final Json json;

  String get key => json['key'] as String;
  String get label => json['label'] as String? ?? key;
  bool get done => json['done'] == true;
  bool get auto => json['auto'] == true;
}

class Installation {
  Installation(dynamic json) : json = _map(json);
  final Json json;

  int get id => json['id'] as int;
  String get folio => json['folio'] as String? ?? '#$id';
  Catalog get status => Catalog(json['status']);
  String? get scheduledAt => json['scheduled_at'] as String?;
  String? get startedAt => json['started_at'] as String?;
  Json get contact => _map(json['contact']);
  String get name => contact['name'] as String? ?? 'Sin nombre';
  String? get phone => contact['phone'] as String?;
  String? get whatsapp => contact['whatsapp'] as String? ?? phone;
  String get address => contact['address'] as String? ?? '';
  String? get references => contact['references'] as String?;
  LatLngJson? get location => LatLngJson.from(json['location']);
  String? get navigationUrl => json['navigation_url'] as String?;
  String? get planName => _map(json['plan'])['name'] as String?;
  String? get repeater => _map(json['repeater'])['code'] as String?;
  List<ChecklistItem> get checklist => _list(json['checklist']).map(ChecklistItem.new).toList();
  Json get measures => _map(json['measures']);
  Json? get signature => json['signature'] is Map ? _map(json['signature']) : null;
  String? get notes => json['notes'] as String?;
  List<Attachment> get attachments => _list(json['attachments']).map(Attachment.new).toList();
  List<UsedMaterial> get materials => _list(json['materials']).map(UsedMaterial.new).toList();
  bool get canWork => json['can_complete'] == true;

  int get checklistDone => checklist.where((c) => c.done).length;
}

/// Parada de la ruta del día (ticket, instalación o cliente a cobrar).
class RouteStop {
  RouteStop(dynamic json) : json = _map(json);
  final Json json;

  int get id => json['id'] as int;
  int get sequence => (json['sequence'] as num?)?.toInt() ?? 0;
  String get status => json['status'] as String? ?? 'pending';
  String get type => json['type'] as String? ?? '';
  Json get subject => _map(json['subject']);
  ClientSummary? get client => json['client'] is Map ? ClientSummary(json['client']) : null;
  LatLngJson? get location => LatLngJson.from(json['location']) ?? client?.location;
  String? get navigationUrl => json['navigation_url'] as String?;
  String? get arrivedAt => json['arrived_at'] as String?;
  String? get departedAt => json['departed_at'] as String?;

  bool get done => status == 'done' || status == 'skipped';
  bool get onSite => arrivedAt != null && departedAt == null && !done;

  String get title => switch (type) {
        'ticket' => 'Ticket ${subject['folio'] ?? ''}',
        'installation' || 'installation_order' => 'Instalación ${subject['folio'] ?? ''}',
        _ => client?.name ?? 'Parada',
      };

  /// Segunda línea: asunto del ticket, saldo del cliente o tipo de visita.
  String get detail => switch (type) {
        'ticket' => '${subject['subject'] ?? 'Soporte'}',
        'client' => 'Cobranza',
        _ => kindLabel,
      };

  String get kindLabel => switch (type) {
        'ticket' => 'Soporte',
        'installation' || 'installation_order' => 'Instalación',
        'client' => 'Cobranza',
        _ => 'Visita',
      };
}

class FieldRoute {
  FieldRoute(dynamic json) : json = _map(json);
  final Json json;

  String? get mapsUrl => json['maps_url'] as String?;
  double? get distanceKm => _num(json['distance_km']);
  List<RouteStop> get stops => _list(json['stops']).map(RouteStop.new).toList()..sort((a, b) => a.sequence.compareTo(b.sequence));
}

/// GET /field/today
class FieldDay {
  FieldDay(dynamic json) : json = _map(json);
  final Json json;

  String get date => json['date'] as String? ?? '';
  Json get summary => _map(json['summary']);
  int count(String key) => (summary[key] as num?)?.toInt() ?? 0;
  FieldRoute? get route => json['route'] is Map ? FieldRoute(json['route']) : null;
  List<Ticket> get tickets => _list(json['open_tickets']).map(Ticket.new).toList();
  List<Installation> get installations => _list(json['installations']).map(Installation.new).toList();
  String? get message => json['message'] as String?;
}

class InventoryItem {
  InventoryItem(dynamic json) : json = _map(json);
  final Json json;

  int get id => json['id'] as int;
  String get sku => json['sku'] as String? ?? '';
  String get name => json['name'] as String? ?? '';
  String get category => Catalog(json['category']).value;
  String get categoryLabel => Catalog(json['category']).label;
  String get unit => json['unit'] as String? ?? 'pza';
  double get stock => _num(json['stock']) ?? 0;
  String get stockState => json['stock_state'] as String? ?? 'success';
  bool get serialized => json['serialized'] == true;
}

/// Cliente con saldo para el cobrador (GET /collection/pending).
class PendingClient extends ClientSummary {
  PendingClient(super.json);

  double get overdueAmount => _num(json['overdue_amount']) ?? 0;
  int get overdueDays => (json['overdue_days'] as num?)?.toInt() ?? 0;
  String? get nextDueDate => json['next_due_date'] as String?;
  String? get navigationUrl => json['navigation_url'] as String?;
  Visit? get lastVisit => json['last_visit'] is Map ? Visit(json['last_visit']) : null;
}

class Visit {
  Visit(dynamic json) : json = _map(json);
  final Json json;

  Catalog get result => Catalog(json['result']);
  double get amountDue => _num(json['amount_due']) ?? 0;
  String? get promiseDate => json['promise_date'] as String?;
  double? get promiseAmount => _num(json['promise_amount']);
  String? get notes => json['notes'] as String?;
  String? get visitedAt => json['visited_at'] as String?;
  Json? get payment => json['payment'] is Map ? _map(json['payment']) : null;
}

class Payment {
  Payment(dynamic json) : json = _map(json);
  final Json json;

  String get folio => json['folio'] as String? ?? '';
  double get amount => _num(json['amount']) ?? 0;
  String? get paidAt => json['paid_at'] as String?;
  String? get receiptUrl => json['receipt_url'] as String?;
  ClientSummary? get client => json['client'] is Map ? ClientSummary(json['client']) : null;
}
