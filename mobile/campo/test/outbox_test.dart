import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wispcontrol_campo/core/api.dart';
import 'package:wispcontrol_campo/core/outbox.dart';
import 'package:wispcontrol_campo/core/uuid.dart';

/// API falsa: sin señal lanza ApiException.offline; con señal registra la clave enviada.
class _FakeApi extends ApiClient {
  _FakeApi() : super(server: 'https://ejemplo.mx');

  bool online = false;
  int? failStatus;

  /// Rutas que el servidor rechaza (422) aunque haya señal.
  final failPaths = <String>{};
  final sent = <String>[];

  Future<Map<String, dynamic>> _fake(String method, String path, Map<String, String>? headers) async {
    if (!online) throw ApiException('Sin conexión', offline: true);
    if (failStatus != null) throw ApiException('El monto no es válido.', status: failStatus);
    if (failPaths.contains(path)) throw ApiException('La firma no es válida.', status: 422);
    sent.add('$method $path ${headers?['Idempotency-Key']}');
    return {'message': 'ok'};
  }

  @override
  Future<Map<String, dynamic>> post(String path, {Map<String, dynamic>? data, Map<String, String>? headers}) => _fake('POST', path, headers);

  @override
  Future<Map<String, dynamic>> patch(String path, {Map<String, dynamic>? data, Map<String, String>? headers}) => _fake('PATCH', path, headers);
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('UUID v4 con formato válido y sin repetirse', () {
    final ids = List.generate(200, (_) => newUuid());
    expect(ids.toSet().length, 200);
    for (final id in ids) {
      expect(RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$').hasMatch(id), isTrue, reason: id);
    }
  });

  group('Outbox', () {
    setUp(() => SharedPreferences.setMockInitialValues({}));

    test('sin señal guarda la acción y la reenvía con la misma clave', () async {
      final api = _FakeApi();
      final outbox = Outbox(api)..userId = 7;

      final res = await outbox.submit(path: '/collection/payments', data: {'amount': 150, 'notes': null}, key: 'clave-1', label: 'Cobro');
      expect(res.queued, isTrue);
      expect(outbox.pendingCount, 1);

      // Persistente: otra instancia (app reiniciada) la encuentra
      final reopened = Outbox(api)..userId = 7;
      await reopened.load();
      final fields = reopened.items.single.fields;
      expect(fields['amount'], 150);
      expect(fields.containsKey('notes'), isFalse);
      // Hora real de la acción: el servidor la registra en lugar de la hora de envío
      expect(DateTime.parse(fields['occurred_at'] as String).isUtc, isTrue);

      api.online = true;
      await reopened.flush(force: true);
      expect(api.sent, ['POST /collection/payments clave-1']);
      expect(reopened.pendingCount, 0);
    });

    test('con señal envía directo; un rechazo del servidor queda marcado y no se reintenta solo', () async {
      final api = _FakeApi()..online = true;
      final outbox = Outbox(api)..userId = 7;

      final ok = await outbox.submit(method: 'PATCH', path: '/tickets/1/status', data: {'status': 'en_proceso'}, label: 'Estado');
      expect(ok.queued, isFalse);
      expect(api.sent.single, startsWith('PATCH /tickets/1/status '));

      api.online = false;
      await outbox.submit(path: '/collection/visits', data: {'result': 'cerrado'}, label: 'Visita');
      api
        ..online = true
        ..failStatus = 422;
      await outbox.flush(force: true);
      expect(outbox.pendingCount, 0);
      expect(outbox.failedCount, 1);
      expect(outbox.items.single.error, 'El monto no es válido.');

      await outbox.discard(outbox.items.single);
      expect(outbox.items, isEmpty);
    });

    test('una acción rechazada detiene las siguientes del mismo trabajo, no las de otros', () async {
      final api = _FakeApi();
      final outbox = Outbox(api)..userId = 7;
      await outbox.submit(path: '/installations/5/signature', data: {'signed_by': 'Ana'}, label: 'Firma');
      await outbox.submit(path: '/installations/5/complete', label: 'Finalizar');
      await outbox.submit(path: '/field/tickets/2/arrive', label: 'Llegada');

      api
        ..online = true
        ..failPaths.add('/installations/5/signature');
      await outbox.flush(force: true);

      expect(api.sent.map((s) => s.split(' ')[1]), ['/field/tickets/2/arrive']);
      final complete = outbox.items.firstWhere((i) => i.path.endsWith('/complete'));
      expect(outbox.waiting(complete), isTrue);
      expect(outbox.failedCount, 1);

      // Al descartar la firma rechazada, "finalizar" ya puede enviarse
      await outbox.discard(outbox.items.firstWhere((i) => i.failed));
      expect(outbox.waiting(complete), isFalse);
      await outbox.flush(force: true);
      expect(api.sent.last, startsWith('POST /installations/5/complete '));
      expect(outbox.items, isEmpty);
    });

    test('sólo envía las acciones del usuario con sesión', () async {
      final api = _FakeApi();
      final outbox = Outbox(api)..userId = 1;
      await outbox.submit(path: '/field/stops/3/arrive', label: 'Llegada');
      outbox.userId = 2;
      expect(outbox.pendingCount, 0);
      api.online = true;
      await outbox.flush(force: true);
      expect(api.sent, isEmpty);
    });
  });
}
