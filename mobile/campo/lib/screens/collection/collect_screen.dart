import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/geo.dart';
import '../../core/launch.dart';
import '../../core/photos.dart';
import '../../core/outbox.dart';
import '../../core/session.dart';
import '../../core/uuid.dart';
import '../../models.dart';
import '../../theme.dart';
import '../../widgets.dart';

/// Ficha de cobranza del cliente (secciones 26 y 27): saldo, contacto, historial de visitas,
/// cobro en efectivo con evidencia y GPS, o visita sin pago (no localizado, promesa, rechazo, cerrado).
class CollectScreen extends StatefulWidget {
  const CollectScreen({super.key, required this.clientId});

  final int clientId;

  @override
  State<CollectScreen> createState() => _CollectScreenState();
}

class _CollectScreenState extends State<CollectScreen> {
  final _loader = GlobalKey<LoaderState<(ClientDetail, List<Visit>)>>();

  Future<(ClientDetail, List<Visit>)> _load() async {
    final api = context.read<Session>().api;
    final results = await Future.wait([api.get('/clients/${widget.clientId}'), api.get('/collection/clients/${widget.clientId}/visits')]);
    return (ClientDetail(results[0]['data']), (results[1]['data'] as List).map(Visit.new).toList());
  }

  Future<void> _pay(ClientDetail c) async {
    final res = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _PaymentForm(client: c),
    );
    if (res == null || !mounted) return;
    if (res['queued'] == true) {
      // Sin señal: el cobro quedó en la bandeja de salida con su clave (no se duplicará al reenviarse).
      await showDialog(
        context: context,
        builder: (ctx) => AlertDialog(
          icon: const Icon(Icons.cloud_upload_outlined, color: AppColors.warning, size: 48),
          title: const Text('Cobro pendiente de envío'),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            Text(Fmt.money(res['amount'] as num?), style: const TextStyle(fontSize: 28, fontWeight: FontWeight.w800)),
            const SizedBox(height: 6),
            const Text('${Outbox.offlineMessage}. No lo registres de nuevo: el folio y el comprobante llegan al enviarse.',
                textAlign: TextAlign.center, style: TextStyle(color: AppColors.slate)),
          ]),
          actions: [FilledButton(onPressed: () => Navigator.pop(ctx), child: const Text('Entendido'))],
        ),
      );
      return;
    }
    final payment = Payment(res['data']);
    await showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        icon: const Icon(Icons.check_circle_rounded, color: AppColors.success, size: 48),
        title: Text('Cobro ${payment.folio}'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          Text(Fmt.money(payment.amount), style: const TextStyle(fontSize: 28, fontWeight: FontWeight.w800)),
          const SizedBox(height: 6),
          Text('Saldo restante: ${Fmt.money(res['client_balance'] as num?)}', style: const TextStyle(color: AppColors.slate)),
          if (res['cash_cut'] is Map) Text('Agregado al corte ${(res['cash_cut'] as Map)['folio']}', style: const TextStyle(color: AppColors.slate, fontSize: 12)),
        ]),
        actions: [
          if (payment.receiptUrl != null && c.whatsapp != null)
            TextButton.icon(
              onPressed: () => Launch.whatsapp(ctx, c.whatsapp, text: 'Hola ${c.name.split(' ').first}, recibimos tu pago de ${Fmt.money(payment.amount)} (folio ${payment.folio}). Comprobante: ${payment.receiptUrl}'),
              icon: const Icon(Icons.chat_outlined),
              label: const Text('Enviar comprobante'),
            ),
          FilledButton(onPressed: () => Navigator.pop(ctx), child: const Text('Listo')),
        ],
      ),
    );
    _loader.currentState?.reload();
  }

  Future<void> _visit(ClientDetail c) async {
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _VisitForm(client: c),
    );
    if (ok == true) _loader.currentState?.reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Cobranza')),
      body: Loader<(ClientDetail, List<Visit>)>(
        key: _loader,
        load: _load,
        builder: (context, data, reload) {
          final (c, visits) = data;
          return ListView(padding: EdgeInsets.zero, children: [
            HeroHeader(children: [
              Text('#${c.code}', style: const TextStyle(color: Color(0xFFB7C4DC))),
              Text(c.name, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: Colors.white)),
              const SizedBox(height: 14),
              const Text('Saldo a cobrar', style: TextStyle(color: Color(0xFFB7C4DC))),
              Text(Fmt.money(c.balance), style: const TextStyle(fontSize: 34, fontWeight: FontWeight.w900, color: Colors.white)),
              if (c.nextDueDate != null) Text('Fecha límite: ${Fmt.date(c.nextDueDate)}', style: const TextStyle(color: Color(0xFFB7C4DC))),
            ]),
            Padding(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                SectionCard(
                  child: Column(children: [
                    InfoRow(icon: Icons.place_outlined, label: 'Dirección', value: c.fullAddress),
                    if (c.references != null) InfoRow(icon: Icons.signpost_outlined, label: 'Referencias', value: c.references),
                    InfoRow(icon: Icons.speed_outlined, label: 'Plan', value: c.planName),
                    InfoRow(icon: Icons.wifi_rounded, label: 'Servicio', value: c.status.label),
                    const SizedBox(height: 8),
                    Row(mainAxisAlignment: MainAxisAlignment.spaceEvenly, children: [
                      RoundAction(icon: Icons.call_outlined, label: 'Llamar', onTap: c.phone == null ? null : () => Launch.call(context, c.phone), color: AppColors.success),
                      RoundAction(icon: Icons.chat_outlined, label: 'WhatsApp', onTap: c.whatsapp == null ? null : () => Launch.whatsapp(context, c.whatsapp), color: const Color(0xFF25D366)),
                      RoundAction(icon: Icons.navigation_outlined, label: 'Navegar', onTap: c.navigationUrl == null ? null : () => Launch.navigate(context, c.navigationUrl)),
                    ]),
                  ]),
                ),
                const SizedBox(height: 16),
                FilledButton.icon(onPressed: () => _pay(c), icon: const Icon(Icons.payments_rounded), label: const Text('Registrar pago en efectivo')),
                const SizedBox(height: 10),
                OutlinedButton.icon(onPressed: () => _visit(c), icon: const Icon(Icons.edit_note_rounded), label: const Text('Registrar visita sin pago')),
                const SizedBox(height: 16),
                SectionCard(
                  title: 'Historial de visitas',
                  child: visits.isEmpty
                      ? const Text('Sin visitas registradas.', style: TextStyle(color: AppColors.slate))
                      : Column(children: [
                          for (final v in visits.take(20))
                            ListTile(
                              contentPadding: EdgeInsets.zero,
                              dense: true,
                              leading: StatusChip.of(v.result, dense: true),
                              title: Text(Fmt.dateTime(v.visitedAt), style: const TextStyle(fontWeight: FontWeight.w600)),
                              subtitle: Text([
                                if (v.payment != null) 'Cobro ${v.payment!['folio']} · ${Fmt.money(v.payment!['amount'] as num?)}',
                                if (v.promiseDate != null) 'Promesa ${Fmt.date(v.promiseDate)}${v.promiseAmount != null ? ' por ${Fmt.money(v.promiseAmount)}' : ''}',
                                if (v.notes != null) v.notes!,
                              ].join('\n')),
                            ),
                        ]),
                ),
                const SizedBox(height: 24),
              ]),
            ),
          ]);
        },
      ),
    );
  }
}

/// Formulario de cobro en efectivo: monto, referencia, nota y foto opcional (evidencia).
class _PaymentForm extends StatefulWidget {
  const _PaymentForm({required this.client});

  final ClientDetail client;

  @override
  State<_PaymentForm> createState() => _PaymentFormState();
}

class _PaymentFormState extends State<_PaymentForm> {
  late final _amount = TextEditingController(text: widget.client.balance > 0 ? widget.client.balance.toStringAsFixed(2) : '');
  final _reference = TextEditingController();
  final _notes = TextEditingController();
  EvidencePhoto? _photo;
  bool _busy = false;
  String? _error;

  /// Clave de idempotencia de este intento de cobro: se reutiliza en cada reintento del formulario
  /// y en la bandeja de salida, así el servidor nunca registra el mismo cobro dos veces.
  final String _key = newUuid();

  @override
  void dispose() {
    _amount.dispose();
    _reference.dispose();
    _notes.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final amount = double.tryParse(_amount.text.replaceAll(',', '').replaceAll(r'$', '').trim());
    if (amount == null || amount <= 0) {
      setState(() => _error = 'Escribe el monto recibido.');
      return;
    }
    final outbox = context.read<Session>().outbox;
    setState(() {
      _busy = true;
      _error = null;
    });
    final gps = await Geo.current();
    try {
      final fields = {
        'client_id': widget.client.id,
        'amount': amount,
        'reference': _reference.text.trim().isEmpty ? null : _reference.text.trim(),
        'notes': _notes.text.trim().isEmpty ? null : _notes.text.trim(),
        'lat': gps?.lat,
        'lng': gps?.lng,
        ..._evidenceFields(_photo),
      };
      final res = await outbox.submit(
        path: '/collection/payments',
        data: fields,
        photo: _photo,
        fileField: 'evidence',
        key: _key,
        label: 'Cobro ${Fmt.money(amount)} · ${widget.client.name}',
      );
      if (mounted) Navigator.pop(context, res.queued ? {'queued': true, 'amount': amount} : res.response);
    } on ApiException catch (e) {
      setState(() {
        _busy = false;
        _error = e.detail;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 0, 20, MediaQuery.viewInsetsOf(context).bottom + 20),
      child: SingleChildScrollView(
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          const Text('Cobro en efectivo', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
          Text(widget.client.name, style: const TextStyle(color: AppColors.slate)),
          const SizedBox(height: 16),
          TextField(
            controller: _amount,
            autofocus: true,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800),
            decoration: const InputDecoration(labelText: 'Monto recibido', prefixText: r'$ '),
          ),
          const SizedBox(height: 12),
          TextField(controller: _reference, decoration: const InputDecoration(labelText: 'Referencia (opcional)', hintText: 'Folio de recibo en papel, etc.')),
          const SizedBox(height: 12),
          TextField(controller: _notes, maxLength: 190, decoration: const InputDecoration(labelText: 'Nota (opcional)')),
          _EvidenceButton(photo: _photo, onChanged: (p) => setState(() => _photo = p)),
          if (_error != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(_error!, style: const TextStyle(color: AppColors.danger, fontWeight: FontWeight.w600))),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: _busy ? null : _submit,
            child: _busy ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white)) : const Text('Registrar cobro'),
          ),
        ]),
      ),
    );
  }
}

/// Visita sin pago: no localizado, promesa de pago, rechazo o domicilio cerrado.
class _VisitForm extends StatefulWidget {
  const _VisitForm({required this.client});

  final ClientDetail client;

  @override
  State<_VisitForm> createState() => _VisitFormState();
}

class _VisitFormState extends State<_VisitForm> {
  static const _results = [
    ('no_localizado', 'No localizado', Icons.person_off_outlined),
    ('promesa', 'Promesa de pago', Icons.handshake_outlined),
    ('rechazo', 'Rechazo', Icons.block_rounded),
    ('cerrado', 'Domicilio cerrado', Icons.door_front_door_outlined),
  ];

  String _result = 'no_localizado';
  DateTime? _promiseDate;
  final _promiseAmount = TextEditingController();
  final _notes = TextEditingController();
  EvidencePhoto? _photo;
  bool _busy = false;
  String? _error;

  /// Clave de idempotencia de esta visita (reintentos y bandeja de salida).
  final String _key = newUuid();

  @override
  void dispose() {
    _promiseAmount.dispose();
    _notes.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_result == 'promesa' && _promiseDate == null) {
      setState(() => _error = 'Elige la fecha en que promete pagar.');
      return;
    }
    final outbox = context.read<Session>().outbox;
    setState(() {
      _busy = true;
      _error = null;
    });
    final gps = await Geo.current();
    try {
      final fields = {
        ..._evidenceFields(_photo),
        'client_id': widget.client.id,
        'result': _result,
        'promise_date': _result == 'promesa' && _promiseDate != null ? Fmt.apiDate(_promiseDate!) : null,
        'promise_amount': _result == 'promesa' ? double.tryParse(_promiseAmount.text.replaceAll(',', '').trim()) : null,
        'notes': _notes.text.trim().isEmpty ? null : _notes.text.trim(),
        'lat': gps?.lat,
        'lng': gps?.lng,
      };
      final label = _results.firstWhere((r) => r.$1 == _result).$2;
      final res = await outbox.submit(
          path: '/collection/visits', data: fields, photo: _photo, fileField: 'evidence', key: _key, label: 'Visita: $label · ${widget.client.name}');
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(res.queued
          ? const SnackBar(content: Text(Outbox.offlineMessage), backgroundColor: AppColors.warning)
          : const SnackBar(content: Text('Visita registrada'), backgroundColor: AppColors.success));
      Navigator.pop(context, true);
    } on ApiException catch (e) {
      setState(() {
        _busy = false;
        _error = e.detail;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 0, 20, MediaQuery.viewInsetsOf(context).bottom + 20),
      child: SingleChildScrollView(
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          const Text('Visita sin pago', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
          Text(widget.client.name, style: const TextStyle(color: AppColors.slate)),
          const SizedBox(height: 12),
          RadioGroup<String>(
            groupValue: _result,
            onChanged: (v) => setState(() => _result = v ?? _result),
            child: Column(children: [
              for (final (value, label, icon) in _results)
                RadioListTile<String>(value: value, contentPadding: EdgeInsets.zero, title: Text(label), secondary: Icon(icon)),
            ]),
          ),
          if (_result == 'promesa') ...[
            OutlinedButton.icon(
              onPressed: () async {
                final now = DateTime.now();
                final d = await showDatePicker(context: context, firstDate: now, lastDate: now.add(const Duration(days: 60)), initialDate: _promiseDate ?? now.add(const Duration(days: 1)));
                if (d != null) setState(() => _promiseDate = d);
              },
              icon: const Icon(Icons.event_outlined),
              label: Text(_promiseDate == null ? 'Fecha de la promesa' : 'Paga el ${Fmt.longDate(_promiseDate!)}'),
            ),
            const SizedBox(height: 10),
            TextField(controller: _promiseAmount, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'Monto prometido (opcional)', prefixText: r'$ ')),
            const SizedBox(height: 10),
          ],
          TextField(controller: _notes, maxLength: 190, decoration: const InputDecoration(labelText: 'Notas (opcional)')),
          _EvidenceButton(photo: _photo, onChanged: (p) => setState(() => _photo = p)),
          if (_error != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(_error!, style: const TextStyle(color: AppColors.danger, fontWeight: FontWeight.w600))),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: _busy ? null : _submit,
            child: _busy ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white)) : const Text('Guardar visita'),
          ),
        ]),
      ),
    );
  }
}

/// Hora y GPS del momento en que se tomó la foto de evidencia.
Map<String, dynamic> _evidenceFields(EvidencePhoto? photo) => photo == null
    ? const {}
    : {'evidence_taken_at': photo.takenAtIso, 'evidence_lat': photo.gps?.lat, 'evidence_lng': photo.gps?.lng};

/// Botón de foto de evidencia: solo cámara (la foto se toma en el momento, no desde la galería).
class _EvidenceButton extends StatelessWidget {
  const _EvidenceButton({required this.photo, required this.onChanged});

  final EvidencePhoto? photo;
  final ValueChanged<EvidencePhoto> onChanged;

  @override
  Widget build(BuildContext context) {
    final p = photo;
    return OutlinedButton.icon(
      onPressed: () async {
        final taken = await Photos.capture();
        if (taken != null) onChanged(taken);
      },
      icon: Icon(p == null ? Icons.add_a_photo_outlined : Icons.check_circle, color: p == null ? null : AppColors.success),
      label: Text(p == null ? 'Tomar foto de evidencia (opcional)' : 'Foto tomada ${Fmt.time(p.takenAt.toIso8601String())}${p.gps == null ? ' (sin GPS)' : ''}'),
    );
  }
}
