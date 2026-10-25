import 'package:flutter_test/flutter_test.dart';
import 'package:wispcontrol_campo/core/cache_store.dart';
import 'package:wispcontrol_campo/core/local_patch.dart';
import 'package:wispcontrol_campo/core/outbox.dart';
import 'package:wispcontrol_campo/models.dart';

const _at = '2026-10-25T16:00:00.000Z';

OutboxItem _item(String path, {String method = 'POST', Map<String, dynamic> fields = const {}, Map<String, dynamic> meta = const {}, String? filePath}) => OutboxItem(
      id: 'k-$path',
      userId: 1,
      method: method,
      path: path,
      fields: {...fields, 'occurred_at': _at},
      label: 'Acción',
      createdAt: DateTime.utc(2026, 10, 25, 16),
      meta: meta,
      filePath: filePath,
    );

Map<String, dynamic> _ticket() => {
      'data': {
        'id': 9,
        'status': {'value': 'asignado', 'label': 'Asignado'},
        'arrived_at': null,
        'departed_at': null,
        'comments': <dynamic>[],
        'attachments': <dynamic>[],
        'materials': <dynamic>[
          {'id': 40, 'quantity': 2, 'item': {'id': 3, 'name': 'Conector', 'unit': 'pza'}},
        ],
        'signature': null,
      },
    };

void main() {
  group('LocalPatch: lo hecho sin señal se ve en la pantalla', () {
    test('ticket: llegada, comentario, foto, firma y material', () {
      final data = LocalPatch.overlay(CacheKeys.ticket(9), _ticket(), [
        _item('/field/tickets/9/arrive'),
        _item('/tickets/9/comments', fields: {'body': 'Antena realineada', 'internal': 0}, meta: {'author': 'Luis'}),
        _item('/tickets/9/attachments', fields: {'kind': 'antes', 'lat': 20.5, 'lng': -100.4}, filePath: '/docs/outbox/a.jpg'),
        _item('/tickets/9/signature', fields: {'signed_by': 'Ana Pérez'}, filePath: '/docs/outbox/b.png'),
        _item('/tickets/9/materials', fields: {'item_id': 5, 'quantity': 10.0}, meta: {'item_name': 'Cable UTP', 'unit': 'm'}),
        _item('/tickets/9/materials/40', method: 'DELETE', meta: {'item_id': 3, 'quantity': 2}),
      ]);
      final t = Ticket(data['data']);

      expect(t.arrivedAt, _at);
      expect(t.status.value, 'en_proceso');
      expect(t.comments.single.body, 'Antena realineada');
      expect(t.comments.single.author, 'Luis');
      expect(t.comments.single.pending, isTrue);
      expect(t.attachments.single.kind, 'antes');
      expect(t.attachments.single.localPath, '/docs/outbox/a.jpg');
      expect(t.attachments.single.hasGps, isTrue);
      expect(t.signature?['signed_by'], 'Ana Pérez');
      expect(t.materials.single.itemName, 'Cable UTP');
      expect(t.materials.single.pending, isTrue);
    });

    test('resolver el ticket guarda la solución', () {
      final data = LocalPatch.overlay(CacheKeys.ticket(9), _ticket(), [
        _item('/tickets/9/status', method: 'PATCH', fields: {'status': 'resuelto', 'note': 'Se cambió el conector'}),
      ]);
      final t = Ticket(data['data']);
      expect(t.status.label, 'Resuelto');
      expect(t.isOpen, isFalse);
      expect(t.resolution, 'Se cambió el conector');
    });

    test('instalación: iniciar, checklist, mediciones, foto y firma dejan lista la orden para finalizar', () {
      final keys = ['antena', 'cableado', 'senal', 'velocidad', 'fotografia', 'firma'];
      Map<String, dynamic> order() => {
            'data': {
              'id': 4,
              'status': {'value': 'programada'},
              'can_complete': true,
              'checklist': [for (final k in keys) {'key': k, 'label': k, 'done': false}],
              'measures': <String, dynamic>{},
              'attachments': <dynamic>[],
              'materials': <dynamic>[],
            },
          };
      final data = LocalPatch.overlay(CacheKeys.installation(4), order(), [
        _item('/installations/4/start'),
        _item('/installations/4/checklist', method: 'PATCH', fields: {'key': 'antena', 'done': true}),
        _item('/installations/4/checklist', method: 'PATCH', fields: {'key': 'cableado', 'done': true}),
        _item('/installations/4/measures', method: 'PATCH', fields: {'signal_dbm': -58, 'speed_down': 50}),
        _item('/installations/4/attachments', fields: {'kind': 'instalacion'}),
        _item('/installations/4/signature', fields: {'signed_by': 'Ana'}),
      ]);
      final o = Installation(data['data']);
      expect(o.status.value, 'en_proceso');
      expect(o.checklistDone, keys.length);
      expect(o.measures['signal_dbm'], -58);

      final done = LocalPatch.overlay(CacheKeys.installation(4), data, [_item('/installations/4/complete')]);
      expect(Installation(done['data']).status.value, 'instalada');
      expect(Installation(done['data']).canWork, isFalse);
    });

    test('ruta: llegada y salida de la parada; el ticket ligado también registra la llegada', () {
      Map<String, dynamic> day() => {
            'summary': {'done': 0},
            'route': {
              'stops': [
                {'id': 30, 'status': 'pending', 'type': 'ticket', 'subject': {'id': 9}},
              ],
            },
            'open_tickets': <dynamic>[],
          };
      final arrived = LocalPatch.overlay(CacheKeys.today('tecnico'), day(), [_item('/field/stops/30/arrive', meta: {'ticket_id': 9})]);
      final stop = RouteStop(((arrived['route'] as Map)['stops'] as List).single);
      expect(stop.onSite, isTrue);

      final left = LocalPatch.overlay(CacheKeys.today('tecnico'), arrived, [_item('/field/stops/30/depart')]);
      expect(RouteStop(((left['route'] as Map)['stops'] as List).single).done, isTrue);
      expect((left['summary'] as Map)['done'], 1);

      final ticket = LocalPatch.overlay(CacheKeys.ticket(9), _ticket(), [_item('/field/stops/30/arrive', meta: {'ticket_id': 9})]);
      expect(Ticket(ticket['data']).arrivedAt, _at);
    });

    test('inventario: lo usado sin señal se descuenta y lo devuelto se regresa', () {
      Map<String, dynamic> inventory() => {
            'data': [
              {'id': 5, 'name': 'Cable UTP', 'stock': 100},
              {'id': 3, 'name': 'Conector', 'stock': 8},
            ],
          };
      final data = LocalPatch.overlay(CacheKeys.inventory, inventory(), [
        _item('/installations/4/materials', fields: {'item_id': 5, 'quantity': 30.0}),
        _item('/tickets/9/materials/40', method: 'DELETE', meta: {'item_id': 3, 'quantity': 2}),
      ]);
      final items = (data['data'] as List).map(InventoryItem.new).toList();
      expect(items[0].stock, 70);
      expect(items[1].stock, 10);
    });

    test('cobranza: el cobro baja el saldo y aparece en el historial como "por subir"', () {
      final pay = _item('/collection/payments', fields: {'client_id': 12, 'amount': 300});
      final client = LocalPatch.overlay(CacheKeys.client(12), {
        'data': {'id': 12, 'full_name': 'Juan', 'balance': 500},
      }, [pay]);
      expect(ClientDetail(client['data']).balance, 200);

      final visits = LocalPatch.overlay(CacheKeys.visits(12), {'data': <dynamic>[]}, [pay]);
      final v = Visit((visits['data'] as List).single);
      expect(v.result.value, 'pago');
      expect(v.pending, isTrue);
      expect(v.payment?['amount'], 300);

      final pending = LocalPatch.overlay(CacheKeys.pending(''), {
        // Como llega del JSON: Map<String, dynamic>
        'data': [
          <String, dynamic>{'id': 12, 'balance': 500},
          <String, dynamic>{'id': 13, 'balance': 100},
        ],
        'summary': {'clients': 2, 'amount': 600},
      }, [pay, _item('/collection/visits', fields: {'client_id': 13, 'result': 'promesa', 'promise_date': '2026-10-30'})]);
      final list = (pending['data'] as List).map(PendingClient.new).toList();
      expect(list[0].balance, 200);
      expect(list[1].lastVisit?.result.label, 'Promesa de pago');
      expect((pending['summary'] as Map)['amount'], 300);
    });

    test('no toca respuestas de otros trabajos', () {
      final other = _ticket();
      final data = LocalPatch.overlay(CacheKeys.ticket(9), other, [_item('/field/tickets/77/arrive'), _item('/installations/9/start')]);
      expect(Ticket(data['data']).arrivedAt, isNull);
      expect(Ticket(data['data']).status.value, 'asignado');
    });
  });
}
