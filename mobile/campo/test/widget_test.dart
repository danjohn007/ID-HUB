import 'package:flutter_test/flutter_test.dart';
import 'package:wispcontrol_campo/core/api.dart';
import 'package:wispcontrol_campo/models.dart';

void main() {
  group('ApiClient.normalizeServer', () {
    test('agrega https y quita diagonales y /api/v1', () {
      expect(ApiClient.normalizeServer('midominio.com/'), 'https://midominio.com');
      expect(ApiClient.normalizeServer('http://127.0.0.1:8000/api/v1/'), 'http://127.0.0.1:8000');
      expect(ApiClient.normalizeServer(' https://a.mx/ID-HUB/wispcontrol/public '), 'https://a.mx/ID-HUB/wispcontrol/public');
    });
  });

  group('modelos', () {
    test('ticket con catálogos, SLA y estado en sitio', () {
      final t = Ticket({
        'id': 7,
        'folio': 'TK-0007',
        'subject': 'Sin Internet',
        'category': {'value': 'sin_internet', 'label': 'Sin Internet'},
        'priority': {'value': 'critica', 'label': 'Crítica', 'color': 'danger'},
        'status': {'value': 'en_proceso', 'label': 'En proceso', 'color': 'warning'},
        'sla': {'due_at': '2026-10-15T12:00:00-06:00', 'state': 'warning', 'breached': false},
        'arrived_at': '2026-10-15T10:00:00-06:00',
        'departed_at': null,
        'client': {'id': 1, 'code': '1001', 'full_name': 'María López', 'location': {'lat': 20.7, 'lng': -100.4}, 'balance': 450},
        'materials': [
          {'id': 3, 'quantity': 2, 'item': {'name': 'Conector RJ45', 'unit': 'pza'}, 'type': {'value': 'instalacion', 'label': 'Instalación'}},
        ],
      });
      expect(t.isOpen, isTrue);
      expect(t.onSite, isTrue);
      expect(t.priority.color, 'danger');
      expect(t.client!.location!.lat, 20.7);
      expect(t.materials.single.itemName, 'Conector RJ45');
    });

    test('parada de ruta: tipo, título y ubicación del cliente', () {
      final s = RouteStop({
        'id': 2,
        'sequence': 1,
        'status': 'pending',
        'type': 'installation_order',
        'subject': {'id': 9, 'folio': 'INS-0009'},
        'client': {'id': 1, 'full_name': 'Juan Pérez', 'location': {'lat': 20.1, 'lng': -100.2}},
      });
      expect(s.kindLabel, 'Instalación');
      expect(s.title, 'Instalación INS-0009');
      expect(s.location!.lng, -100.2);
      expect(s.done, isFalse);
    });
  });
}
