import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:signature/signature.dart';

import '../../theme.dart';

class SignatureResult {
  const SignatureResult(this.png, this.name);

  final Uint8List png;
  final String name;
}

/// Captura de la firma del cliente en pantalla completa.
class SignatureScreen extends StatefulWidget {
  const SignatureScreen({super.key, this.initialName});

  final String? initialName;

  @override
  State<SignatureScreen> createState() => _SignatureScreenState();
}

class _SignatureScreenState extends State<SignatureScreen> {
  final _pad = SignatureController(penStrokeWidth: 3, penColor: AppColors.navy, exportBackgroundColor: Colors.white);
  late final _name = TextEditingController(text: widget.initialName ?? '');
  String? _error;

  @override
  void dispose() {
    _pad.dispose();
    _name.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final name = _name.text.trim();
    if (name.length < 3) {
      setState(() => _error = 'Escribe el nombre de quien firma.');
      return;
    }
    if (_pad.isEmpty) {
      setState(() => _error = 'Pide al cliente que firme en el recuadro.');
      return;
    }
    final png = await _pad.toPngBytes(height: 300, width: 800);
    if (png == null || !mounted) return;
    Navigator.pop(context, SignatureResult(png, name));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Firma de conformidad'),
        actions: [TextButton(onPressed: _pad.clear, child: const Text('Borrar', style: TextStyle(color: Colors.white)))],
      ),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            TextField(
              controller: _name,
              textCapitalization: TextCapitalization.words,
              decoration: const InputDecoration(labelText: 'Nombre de quien firma', prefixIcon: Icon(Icons.person_outline)),
            ),
            const SizedBox(height: 12),
            const Text('El cliente firma con el dedo dentro del recuadro:', style: TextStyle(color: AppColors.slate)),
            const SizedBox(height: 8),
            Expanded(
              child: Container(
                clipBehavior: Clip.antiAlias,
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16), border: Border.all(color: const Color(0xFFCBD5E1), width: 1.5)),
                child: Signature(controller: _pad, backgroundColor: Colors.white),
              ),
            ),
            if (_error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_error!, style: const TextStyle(color: AppColors.danger, fontWeight: FontWeight.w600))),
            const SizedBox(height: 12),
            FilledButton.icon(onPressed: _save, icon: const Icon(Icons.check_rounded), label: const Text('Guardar firma')),
          ]),
        ),
      ),
    );
  }
}
